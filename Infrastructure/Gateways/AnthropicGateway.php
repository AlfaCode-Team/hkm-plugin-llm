<?php

declare(strict_types=1);

namespace Plugins\Llm\Infrastructure\Gateways;

use Plugins\Llm\API\FinishReason;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\LlmResponse;
use Plugins\Llm\API\Message;
use Plugins\Llm\API\Parts\ReasoningPart;
use Plugins\Llm\API\Parts\TextPart;
use Plugins\Llm\API\Parts\ToolCallPart;
use Plugins\Llm\API\Parts\ToolResultPart;
use Plugins\Llm\API\Role;
use Plugins\Llm\API\Schema;
use Plugins\Llm\API\ToolChoice;
use Plugins\Llm\API\Usage;
use Plugins\Llm\Application\Exceptions\LlmException;

/**
 * Anthropic Messages API — `POST /v1/messages`.
 *
 * Request shape: `x-api-key` and `anthropic-version` headers; a top-level
 * `system` string; `messages` of typed content blocks; tools as
 * `{name, description, input_schema}`; tool results as `tool_result` blocks in
 * a USER turn; `max_tokens` is REQUIRED; extended thinking as
 * `thinking: {type: enabled, budget_tokens}`; structured output as
 * `output_config.format = {type: json_schema, schema}`.
 *
 * Built from the provider's documented shapes and checked against hand-written
 * fixtures. NOT checked against a live response.
 */
final class AnthropicGateway extends HttpModelGateway
{
    private const VERSION = '2023-06-01';

    protected function capabilities(): array
    {
        return ['structured_output' => $this->settings->nativeSchema, 'tool_use' => true, 'effort' => true, 'required_tool_choice' => true];
    }

    public function cannotConstrain(Schema $schema): ?string
    {
        if (!$this->settings->nativeSchema) {
            return 'native structured output is switched off (LLM_ANTHROPIC_NATIVE_SCHEMA)';
        }
        if (!$schema->isObject()) {
            return 'the root of the schema must be an object';
        }
        if (!$schema->isClosed()) {
            return 'constrained decoding needs a closed schema: additionalProperties false and every property required';
        }
        if ($schema->hasRangeConstraints()) {
            return 'range keywords (minimum, maxLength, minItems, …) are not guaranteed to be accepted by constrained decoding';
        }

        return null;
    }

    protected function assertSupported(LlmRequest $request): void
    {
        if ($request->effort !== null) {
            if ($request->temperature !== null) {
                throw LlmException::unsupported($this->name(), 'a temperature together with extended thinking (the API rejects the combination)');
            }
            if ($request->toolChoice !== null && \in_array($request->toolChoice->type, [ToolChoice::REQUIRED, ToolChoice::TOOL], true)) {
                throw LlmException::unsupported($this->name(), 'a forced tool choice together with extended thinking (the API rejects the combination)');
            }
            $budget = $request->effort->budget();
            if ($request->maxTokens !== null && $request->maxTokens <= $budget) {
                throw LlmException::unsupported(
                    $this->name(),
                    "effort {$request->effort->value} (a {$budget}-token thinking budget) with maxTokens {$request->maxTokens}: maxTokens must exceed the budget",
                );
            }
        }
    }

    protected function url(string $model): string
    {
        return $this->settings->baseUrl . '/v1/messages';
    }

    protected function headers(): array
    {
        return [
            'x-api-key' => $this->settings->apiKey?->reveal() ?? '',
            'anthropic-version' => self::VERSION,
        ];
    }

    protected function payload(LlmRequest $request, string $model): array
    {
        $payload = [
            'model' => $model,
            'max_tokens' => $request->maxTokens ?? $this->defaultMaxTokens,
            'messages' => $this->messages($request),
        ];

        $system = $request->system();
        if ($system !== '') {
            $payload['system'] = $system;
        }

        if ($request->tools !== []) {
            $payload['tools'] = array_map(static fn ($t): array => [
                'name' => $t->name,
                'description' => $t->description,
                'input_schema' => $t->input->toJsonSchema(),
            ], $request->tools);
        }

        if ($request->toolChoice !== null && ($request->tools !== [] || $request->toolChoice->type === ToolChoice::NONE)) {
            $payload['tool_choice'] = match ($request->toolChoice->type) {
                ToolChoice::AUTO => ['type' => 'auto'],
                ToolChoice::NONE => ['type' => 'none'],
                ToolChoice::REQUIRED => ['type' => 'any'],
                default => ['type' => 'tool', 'name' => $request->toolChoice->name],
            };
        }

        if ($request->effort !== null) {
            $budget = $request->effort->budget();
            $payload['thinking'] = ['type' => 'enabled', 'budget_tokens' => $budget];
            $payload['max_tokens'] = $request->maxTokens ?? ($budget + $this->defaultMaxTokens);
        }

        if ($request->schema !== null) {
            $payload['output_config'] = ['format' => ['type' => 'json_schema', 'schema' => $request->schema->toJsonSchema()]];
        }

        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }
        if ($request->stop !== []) {
            $payload['stop_sequences'] = $request->stop;
        }

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function messages(LlmRequest $request): array
    {
        $out = [];
        foreach ($request->conversation() as $message) {
            $blocks = [];
            foreach ($message->parts as $part) {
                $block = match (true) {
                    $part instanceof TextPart => $part->text === '' ? null : ['type' => 'text', 'text' => $part->text],
                    $part instanceof ToolCallPart => [
                        'type' => 'tool_use', 'id' => $part->id, 'name' => $part->name, 'input' => self::object($part->arguments),
                    ],
                    $part instanceof ToolResultPart => [
                        'type' => 'tool_result', 'tool_use_id' => $part->callId, 'content' => $part->content,
                        'is_error' => $part->isError,
                    ],
                    // Only a block that came FROM Anthropic can go back to it.
                    $part instanceof ReasoningPart => \in_array($part->opaque['type'] ?? null, ['thinking', 'redacted_thinking'], true)
                        ? $part->opaque : null,
                    default => null,
                };
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
            if ($blocks === []) {
                continue;
            }
            $out[] = ['role' => $message->role === Role::Assistant ? 'assistant' : 'user', 'content' => $blocks];
        }

        return $out;
    }

    protected function parse(array $body, string $model): LlmResponse
    {
        if (!isset($body['content']) || !\is_array($body['content'])) {
            throw $this->failure('returned a response with no content');
        }

        $parts = [];
        foreach ($body['content'] as $block) {
            if (!\is_array($block)) {
                throw $this->failure('returned a content block that is not an object');
            }
            switch ($block['type'] ?? '') {
                case 'text':
                    $parts[] = new TextPart((string) $block['text']);
                    break;
                case 'tool_use':
                    $parts[] = new ToolCallPart($this->required($block['id'] ?? null, 'tool call id'), $this->required($block['name'] ?? null, 'tool call name'), (array) ($block['input'] ?? []));
                    break;
                case 'thinking':
                    $parts[] = new ReasoningPart((string) ($block['thinking'] ?? ''), $block);
                    break;
                case 'redacted_thinking':
                    $parts[] = new ReasoningPart('', $block);
                    break;
            }
        }

        $usage = $body['usage'] ?? [];

        return new LlmResponse(
            provider: $this->name(),
            model: (string) ($body['model'] ?? $model),
            message: Message::assistant($parts === [] ? [new TextPart('')] : $parts),
            finish: match ($body['stop_reason'] ?? '') {
                'end_turn', 'stop_sequence' => FinishReason::Stop,
                'max_tokens' => FinishReason::Length,
                'tool_use' => FinishReason::ToolCalls,
                'refusal' => FinishReason::ContentFilter,
                default => FinishReason::Other,
            },
            usage: new Usage(
                input: (int) ($usage['input_tokens'] ?? 0),
                output: (int) ($usage['output_tokens'] ?? 0),
                cacheRead: (int) ($usage['cache_read_input_tokens'] ?? 0),
            ),
        );
    }
}
