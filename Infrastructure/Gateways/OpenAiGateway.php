<?php

declare(strict_types=1);

namespace Plugins\Llm\Infrastructure\Gateways;

use Plugins\Llm\API\FinishReason;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\LlmResponse;
use Plugins\Llm\API\Message;
use Plugins\Llm\API\Parts\TextPart;
use Plugins\Llm\API\Parts\ToolCallPart;
use Plugins\Llm\API\Parts\ToolResultPart;
use Plugins\Llm\API\Role;
use Plugins\Llm\API\Schema;
use Plugins\Llm\API\ToolChoice;
use Plugins\Llm\API\Usage;

/**
 * OpenAI Chat Completions — `POST /v1/chat/completions`.
 *
 * Request shape: `Authorization: Bearer`; `messages` with roles system / user /
 * assistant / tool; assistant tool calls as
 * `tool_calls[{id, type: function, function: {name, arguments: <JSON STRING>}}]`;
 * one `tool` message per result keyed by `tool_call_id`; tools as
 * `{type: function, function: {name, description, parameters}}`;
 * `max_completion_tokens`; `reasoning_effort`; structured output as
 * `response_format = {type: json_schema, json_schema: {name, schema, strict: true}}`.
 *
 * Chat Completions rather than the Responses API: it is the shape the
 * OpenAI-compatible servers also speak. Built from the documented shapes and
 * checked against hand-written fixtures; NOT checked against a live response.
 */
final class OpenAiGateway extends HttpModelGateway
{
    protected function capabilities(): array
    {
        return ['structured_output' => $this->settings->nativeSchema, 'tool_use' => true, 'effort' => true, 'required_tool_choice' => true];
    }

    public function cannotConstrain(Schema $schema): ?string
    {
        if (!$this->settings->nativeSchema) {
            return 'native structured output is switched off (LLM_OPENAI_NATIVE_SCHEMA)';
        }
        if (!$schema->isObject()) {
            return 'the root of the schema must be an object';
        }
        if (!$schema->isClosed()) {
            // Sending strict:false would be "ask nicely" with a schema attached.
            return 'strict mode needs a closed schema: additionalProperties false and every property required';
        }
        if ($schema->hasRangeConstraints()) {
            return 'range keywords (minimum, maxLength, minItems, …) are not guaranteed to be accepted by strict mode';
        }

        return null;
    }

    protected function assertSupported(LlmRequest $request): void {}

    protected function url(string $model): string
    {
        return $this->settings->baseUrl . '/v1/chat/completions';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer ' . ($this->settings->apiKey?->reveal() ?? '')];
    }

    protected function payload(LlmRequest $request, string $model): array
    {
        $payload = ['model' => $model, 'messages' => $this->messages($request)];

        if ($request->tools !== []) {
            $payload['tools'] = array_map(static fn ($t): array => [
                'type' => 'function',
                'function' => ['name' => $t->name, 'description' => $t->description, 'parameters' => $t->input->toJsonSchema()],
            ], $request->tools);
        }

        if ($request->toolChoice !== null && ($request->tools !== [] || $request->toolChoice->type === ToolChoice::NONE)) {
            $payload['tool_choice'] = match ($request->toolChoice->type) {
                ToolChoice::TOOL => ['type' => 'function', 'function' => ['name' => $request->toolChoice->name]],
                default => $request->toolChoice->type, // auto | none | required
            };
        }

        if ($request->schema !== null) {
            $payload['response_format'] = [
                'type' => 'json_schema',
                'json_schema' => ['name' => $request->schema->name, 'schema' => $request->schema->toJsonSchema(), 'strict' => true],
            ];
        }

        $payload['max_completion_tokens'] = $request->maxTokens ?? $this->defaultMaxTokens;
        if ($request->temperature !== null) {
            $payload['temperature'] = $request->temperature;
        }
        if ($request->effort !== null) {
            $payload['reasoning_effort'] = $request->effort->value;
        }
        if ($request->stop !== []) {
            $payload['stop'] = $request->stop;
        }

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function messages(LlmRequest $request): array
    {
        $out = [];
        $system = $request->system();
        if ($system !== '') {
            $out[] = ['role' => 'system', 'content' => $system];
        }

        foreach ($request->conversation() as $message) {
            if ($message->role === Role::Tool) {
                foreach ($message->parts as $part) {
                    if ($part instanceof ToolResultPart) {
                        $out[] = ['role' => 'tool', 'tool_call_id' => $part->callId, 'content' => $part->content];
                    }
                }
                continue;
            }

            if ($message->role === Role::Assistant) {
                $entry = ['role' => 'assistant', 'content' => $message->text() === '' ? null : $message->text()];
                $calls = array_map(static fn (ToolCallPart $c): array => [
                    'id' => $c->id,
                    'type' => 'function',
                    // A JSON STRING, not an object — and `{}` for no arguments.
                    'function' => ['name' => $c->name, 'arguments' => json_encode(self::object($c->arguments), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                ], $message->toolCalls());
                if ($calls !== []) {
                    $entry['tool_calls'] = $calls;
                }
                if ($entry['content'] !== null || $calls !== []) {
                    $out[] = $entry;
                }
                continue;
            }

            $out[] = ['role' => 'user', 'content' => $message->text()];
        }

        return $out;
    }

    protected function parse(array $body, string $model): LlmResponse
    {
        $choice = $body['choices'][0] ?? throw $this->failure('returned no choices');
        if (!\is_array($choice) || !\is_array($choice['message'] ?? null)) {
            throw $this->failure('returned a choice with no message');
        }
        $message = $choice['message'];

        $parts = [];
        $refusal = $message['refusal'] ?? null;
        $text = \is_string($message['content'] ?? null) ? $message['content'] : '';
        if ($text !== '') {
            $parts[] = new TextPart($text);
        }
        if (\is_string($refusal) && $refusal !== '') {
            $parts[] = new TextPart($refusal);
        }
        foreach ($message['tool_calls'] ?? [] as $call) {
            $parts[] = new ToolCallPart(
                $this->required($call['id'] ?? null, 'tool call id'),
                $this->required($call['function']['name'] ?? null, 'tool call name'),
                $this->decodeArguments($call['function']['arguments'] ?? null),
            );
        }

        $usage = $body['usage'] ?? [];

        return new LlmResponse(
            provider: $this->name(),
            model: (string) ($body['model'] ?? $model),
            message: Message::assistant($parts === [] ? [new TextPart('')] : $parts),
            finish: \is_string($refusal) && $refusal !== '' ? FinishReason::ContentFilter : match ($choice['finish_reason'] ?? '') {
                'stop' => FinishReason::Stop,
                'length' => FinishReason::Length,
                'tool_calls', 'function_call' => FinishReason::ToolCalls,
                'content_filter' => FinishReason::ContentFilter,
                default => FinishReason::Other,
            },
            usage: new Usage(
                input: (int) ($usage['prompt_tokens'] ?? 0),
                output: (int) ($usage['completion_tokens'] ?? 0),
                cacheRead: (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
                reasoning: (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0),
            ),
        );
    }
}
