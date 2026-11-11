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
use Plugins\Llm\Application\Exceptions\LlmException;

/**
 * Ollama — `POST {base}/api/chat` with `stream: false`.
 *
 * Request shape: no authentication; `messages` with roles system / user /
 * assistant / tool; assistant tool calls as `tool_calls[{function: {name,
 * arguments: <OBJECT>}}]` (an object here, unlike OpenAI's string); a result as
 * `{role: tool, tool_name, content}`; `tools` as OpenAI's `{type: function,
 * function}`; `format` takes the JSON schema itself and constrains decoding
 * for every model; sampling under `options` (`num_predict`, `temperature`,
 * `stop`).
 *
 * What Ollama cannot do is REFUSED rather than ignored: there is no
 * `tool_choice`, so `required` and a named tool throw (`none` is honoured by
 * not sending the tools), and there is no effort setting.
 *
 * The base URL is the OPERATOR'S, from LLM_OLLAMA_BASE_URL — never a request
 * field, never user input. A local server is a private address, so
 * `block_private` is off by default here and on for the hosted providers; set
 * LLM_OLLAMA_BLOCK_PRIVATE=true for a server on a public host.
 *
 * Built from the documented shapes and hand-written fixtures; NOT checked
 * against a running Ollama.
 */
final class OllamaGateway extends HttpModelGateway
{
    protected function capabilities(): array
    {
        return ['structured_output' => $this->settings->nativeSchema, 'tool_use' => true, 'effort' => false, 'required_tool_choice' => false];
    }

    public function cannotConstrain(Schema $schema): ?string
    {
        return $this->settings->nativeSchema ? null : 'native structured output is switched off (LLM_OLLAMA_NATIVE_SCHEMA)';
    }

    protected function assertSupported(LlmRequest $request): void
    {
        if ($request->effort !== null) {
            throw LlmException::unsupported($this->name(), 'an effort setting');
        }
        if ($request->toolChoice !== null && \in_array($request->toolChoice->type, [ToolChoice::REQUIRED, ToolChoice::TOOL], true)) {
            throw LlmException::unsupported($this->name(), 'a forced tool choice (there is no tool_choice in the Ollama API)');
        }
    }

    protected function url(string $model): string
    {
        return $this->settings->baseUrl . '/api/chat';
    }

    protected function headers(): array
    {
        return [];
    }

    protected function payload(LlmRequest $request, string $model): array
    {
        $payload = ['model' => $model, 'messages' => $this->messages($request), 'stream' => false];

        if ($request->tools !== [] && $request->toolChoice?->type !== ToolChoice::NONE) {
            $payload['tools'] = array_map(static fn ($t): array => [
                'type' => 'function',
                'function' => ['name' => $t->name, 'description' => $t->description, 'parameters' => $t->input->toJsonSchema()],
            ], $request->tools);
        }

        if ($request->schema !== null) {
            $payload['format'] = $request->schema->toJsonSchema();
        }

        $options = ['num_predict' => $request->maxTokens ?? $this->defaultMaxTokens];
        if ($request->temperature !== null) {
            $options['temperature'] = $request->temperature;
        }
        if ($request->stop !== []) {
            $options['stop'] = $request->stop;
        }
        $payload['options'] = $options;

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
                        $out[] = ['role' => 'tool', 'tool_name' => $part->name, 'content' => $part->content];
                    }
                }
                continue;
            }

            if ($message->role === Role::Assistant) {
                $entry = ['role' => 'assistant', 'content' => $message->text()];
                $calls = array_map(static fn (ToolCallPart $c): array => [
                    'function' => ['name' => $c->name, 'arguments' => self::object($c->arguments)],
                ], $message->toolCalls());
                if ($calls !== []) {
                    $entry['tool_calls'] = $calls;
                }
                $out[] = $entry;
                continue;
            }

            $out[] = ['role' => 'user', 'content' => $message->text()];
        }

        return $out;
    }

    protected function parse(array $body, string $model): LlmResponse
    {
        $message = $body['message'] ?? throw $this->failure('returned no message');
        if (!\is_array($message)) {
            throw $this->failure('returned a message that is not an object');
        }

        $parts = [];
        if (\is_string($message['content'] ?? null) && $message['content'] !== '') {
            $parts[] = new TextPart($message['content']);
        }
        $n = 0;
        foreach ($message['tool_calls'] ?? [] as $call) {
            $parts[] = new ToolCallPart('call_' . $n++, $this->required($call['function']['name'] ?? null, 'tool call name'), $this->decodeArguments($call['function']['arguments'] ?? []));
        }

        return new LlmResponse(
            provider: $this->name(),
            model: (string) ($body['model'] ?? $model),
            message: Message::assistant($parts === [] ? [new TextPart('')] : $parts),
            finish: $n > 0 ? FinishReason::ToolCalls : match ($body['done_reason'] ?? 'stop') {
                'stop' => FinishReason::Stop,
                'length' => FinishReason::Length,
                default => FinishReason::Other,
            },
            usage: new Usage(
                input: (int) ($body['prompt_eval_count'] ?? 0),
                output: (int) ($body['eval_count'] ?? 0),
            ),
        );
    }
}
