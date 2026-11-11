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
 * Google Gemini — `POST /v1beta/models/{model}:generateContent`.
 *
 * Request shape: the key travels in the `x-goog-api-key` HEADER, never as
 * `?key=` — a query-string key lands in every URL the HTTP client prints in an
 * error. `systemInstruction`; `contents` with roles user / model and `parts`;
 * a tool call is a `functionCall {name, args}` part and its result a
 * `functionResponse {name, response: <OBJECT>}` part in a user turn;
 * `tools[{functionDeclarations}]`; `toolConfig.functionCallingConfig.mode`;
 * `generationConfig` for tokens, temperature, stop, `thinkingConfig` and
 * structured output (`responseMimeType` + `responseSchema`, an OpenAPI subset
 * with UPPERCASE types).
 *
 * Gemini calls have no ids (a model may or may not return one), so this
 * gateway synthesises `call_<n>` and matches results by function NAME.
 * Built from the documented shapes and hand-written fixtures; NOT checked
 * against a live response, and newer model generations that require their
 * thought signatures to be round-tripped are NOT handled.
 */
final class GeminiGateway extends HttpModelGateway
{
    protected function capabilities(): array
    {
        return ['structured_output' => $this->settings->nativeSchema, 'tool_use' => true, 'effort' => true, 'required_tool_choice' => true];
    }

    public function cannotConstrain(Schema $schema): ?string
    {
        if (!$this->settings->nativeSchema) {
            return 'native structured output is switched off (LLM_GEMINI_NATIVE_SCHEMA)';
        }
        try {
            self::toGeminiSchema($schema->toJsonSchema());
        } catch (\UnexpectedValueException $e) {
            return $e->getMessage();
        }

        return null;
    }

    protected function assertSupported(LlmRequest $request): void {}

    protected function url(string $model): string
    {
        return $this->settings->baseUrl . '/v1beta/models/' . rawurlencode($model) . ':generateContent';
    }

    protected function headers(): array
    {
        return ['x-goog-api-key' => $this->settings->apiKey?->reveal() ?? ''];
    }

    protected function payload(LlmRequest $request, string $model): array
    {
        $payload = ['contents' => $this->contents($request)];

        $system = $request->system();
        if ($system !== '') {
            $payload['systemInstruction'] = ['parts' => [['text' => $system]]];
        }

        if ($request->tools !== []) {
            $payload['tools'] = [['functionDeclarations' => array_map(static fn ($t): array => [
                'name' => $t->name,
                'description' => $t->description,
                'parameters' => self::toGeminiSchema($t->input->toJsonSchema()),
            ], $request->tools)]];
        }

        if ($request->toolChoice !== null && ($request->tools !== [] || $request->toolChoice->type === ToolChoice::NONE)) {
            $config = match ($request->toolChoice->type) {
                ToolChoice::AUTO => ['mode' => 'AUTO'],
                ToolChoice::NONE => ['mode' => 'NONE'],
                ToolChoice::REQUIRED => ['mode' => 'ANY'],
                default => ['mode' => 'ANY', 'allowedFunctionNames' => [$request->toolChoice->name]],
            };
            $payload['toolConfig'] = ['functionCallingConfig' => $config];
        }

        $generation = ['maxOutputTokens' => $request->maxTokens ?? $this->defaultMaxTokens];
        if ($request->temperature !== null) {
            $generation['temperature'] = $request->temperature;
        }
        if ($request->stop !== []) {
            $generation['stopSequences'] = $request->stop;
        }
        if ($request->effort !== null) {
            $generation['thinkingConfig'] = ['thinkingBudget' => $request->effort->budget()];
        }
        if ($request->schema !== null) {
            $generation['responseMimeType'] = 'application/json';
            $generation['responseSchema'] = self::toGeminiSchema($request->schema->toJsonSchema());
        }
        $payload['generationConfig'] = $generation;

        return $payload;
    }

    /** @return list<array<string, mixed>> */
    private function contents(LlmRequest $request): array
    {
        $out = [];
        foreach ($request->conversation() as $message) {
            $parts = [];
            foreach ($message->parts as $part) {
                if ($part instanceof TextPart && $part->text !== '') {
                    $parts[] = ['text' => $part->text];
                } elseif ($part instanceof ToolCallPart) {
                    $parts[] = ['functionCall' => ['name' => $part->name, 'args' => self::object($part->arguments)]];
                } elseif ($part instanceof ToolResultPart) {
                    // `response` must be an object, so the text is wrapped.
                    $parts[] = ['functionResponse' => [
                        'name' => $part->name,
                        'response' => [$part->isError ? 'error' : 'result' => $part->content],
                    ]];
                }
            }
            if ($parts !== []) {
                $out[] = ['role' => $message->role === Role::Assistant ? 'model' : 'user', 'parts' => $parts];
            }
        }

        return $out;
    }

    protected function parse(array $body, string $model): LlmResponse
    {
        $candidate = $body['candidates'][0] ?? null;
        $usage = $body['usageMetadata'] ?? [];
        $usageVo = new Usage(
            input: (int) ($usage['promptTokenCount'] ?? 0),
            output: (int) ($usage['candidatesTokenCount'] ?? 0),
            cacheRead: (int) ($usage['cachedContentTokenCount'] ?? 0),
            reasoning: (int) ($usage['thoughtsTokenCount'] ?? 0),
        );

        if ($candidate !== null && !\is_array($candidate)) {
            throw $this->failure('returned a candidate that is not an object');
        }
        if ($candidate === null) {
            if (isset($body['promptFeedback']['blockReason'])) {
                return new LlmResponse($this->name(), (string) ($body['modelVersion'] ?? $model), Message::assistant([new TextPart('')]), FinishReason::ContentFilter, $usageVo);
            }
            throw $this->failure('returned no candidates');
        }

        $parts = [];
        $calls = 0;
        foreach ($candidate['content']['parts'] ?? [] as $part) {
            if (($part['thought'] ?? false) === true) {
                continue; // summarised thinking is not part of the answer
            }
            if (isset($part['text'])) {
                $parts[] = new TextPart((string) $part['text']);
            } elseif (isset($part['functionCall'])) {
                $call = $part['functionCall'];
                $parts[] = new ToolCallPart((string) ($call['id'] ?? 'call_' . $calls), $this->required($call['name'] ?? null, 'function call name'), $this->decodeArguments($call['args'] ?? []));
                $calls++;
            }
        }

        return new LlmResponse(
            provider: $this->name(),
            model: (string) ($body['modelVersion'] ?? $model),
            message: Message::assistant($parts === [] ? [new TextPart('')] : $parts),
            // Gemini reports STOP for a turn that ends in a function call.
            finish: $calls > 0 ? FinishReason::ToolCalls : match ($candidate['finishReason'] ?? '') {
                'STOP' => FinishReason::Stop,
                'MAX_TOKENS' => FinishReason::Length,
                'SAFETY', 'RECITATION', 'BLOCKLIST', 'PROHIBITED_CONTENT', 'SPII', 'IMAGE_SAFETY' => FinishReason::ContentFilter,
                default => FinishReason::Other,
            },
            usage: $usageVo,
        );
    }

    /**
     * JSON Schema → the OpenAPI subset Gemini's `responseSchema` takes:
     * UPPERCASE types, `nullable` instead of a `null` type, no
     * `additionalProperties`, `title` or `const`. Dropping `additionalProperties`
     * loses nothing the caller can see — the response is validated against the
     * original schema locally.
     *
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     * @throws \UnexpectedValueException for a shape Gemini cannot express
     */
    private static function toGeminiSchema(array $d): array
    {
        unset($d['additionalProperties'], $d['title']);

        if (isset($d['type'])) {
            $types = (array) $d['type'];
            $nonNull = array_values(array_filter($types, static fn (string $t): bool => $t !== 'null'));
            if (\count($nonNull) > 1) {
                throw new \UnexpectedValueException('Gemini takes one non-null type per schema node; use anyOf for alternatives');
            }
            if (\count($nonNull) === 1) {
                $d['type'] = strtoupper($nonNull[0]);
            } else {
                unset($d['type']);
            }
            if (\count($types) !== \count($nonNull)) {
                $d['nullable'] = true;
            }
        }

        if (\array_key_exists('const', $d)) {
            $d['enum'] = [$d['const']];
            unset($d['const']);
        }

        if (isset($d['properties']) && !$d['properties'] instanceof \stdClass) {
            foreach ($d['properties'] as $name => $sub) {
                $d['properties'][$name] = self::toGeminiSchema($sub);
            }
        }
        if (isset($d['items'])) {
            $d['items'] = self::toGeminiSchema($d['items']);
        }
        if (isset($d['anyOf'])) {
            $d['anyOf'] = array_map(static fn (array $s): array => self::toGeminiSchema($s), $d['anyOf']);
        }
        // Gemini wants enum members as strings.
        if (isset($d['enum'])) {
            $d['enum'] = array_map(static fn ($v): string => \is_string($v) ? $v : json_encode($v), $d['enum']);
        }

        return $d;
    }
}
