<?php

declare(strict_types=1);

namespace Plugins\Llm\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\LlmResponse;
use Plugins\Llm\API\ProviderInfo;
use Plugins\Llm\Application\Exceptions\LlmException;
use Plugins\Llm\Application\Ports\ModelGateway;
use Plugins\Llm\Infrastructure\Config\ProviderSettings;

/**
 * The shared half of the three HTTP providers: one POST, hardened, with every
 * failure turned into a GatewayException and none of them able to carry a key.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * WHAT EVERY CALL ASKS OF HttpClientPort
 * ════════════════════════════════════════════════════════════════════════════
 *
 *   block_private       the SSRF / DNS-rebind guard, on for the hosted
 *                       providers. For Ollama it is the operator's switch —
 *                       a local model server IS a private address.
 *   max_response_bytes  a ceiling far under the client's 32 MB default
 *   retry => 0          a completion costs money and is not idempotent;
 *                       whether to retry is the caller's decision
 *   timeout             LLM_TIMEOUT
 *
 * NOT streaming. HttpClientPort::request() returns a finished response —
 * the cURL adapter buffers with CURLOPT_RETURNTRANSFER and exposes no write
 * callback — so every call here is a whole-response call, and the contract
 * has no stream method to pretend otherwise.
 */
abstract class HttpModelGateway implements ModelGateway
{
    public function __construct(
        protected readonly HttpClientPort $http,
        protected readonly ProviderSettings $settings,
        protected readonly int $timeout = 60,
        protected readonly int $maxResponseBytes = 8_388_608,
        protected readonly int $defaultMaxTokens = 4096,
    ) {}

    public function name(): string
    {
        return $this->settings->name;
    }

    public function describe(): ProviderInfo
    {
        return new ProviderInfo($this->settings->name, $this->settings->model ?? '', $this->settings->host(), $this->capabilities());
    }

    public function generate(LlmRequest $request): LlmResponse
    {
        $model = $request->model ?? $this->settings->model
            ?? throw LlmException::notConfigured($this->name(), ['LLM_' . strtoupper($this->name()) . '_MODEL']);

        $this->assertSupported($request);

        $body = $this->send($this->url($model), $this->headers(), $this->payload($request, $model));

        try {
            return $this->parse($body, $model);
        } catch (GatewayException $e) {
            throw $e;
        } catch (\Throwable $e) {
            // A response that does not have the documented shape. The body is
            // not echoed: it is remote text of unbounded size.
            throw $this->failure('returned a response that does not have the expected shape', ['cause' => $e::class]);
        }
    }

    /** @return array<string, bool> */
    abstract protected function capabilities(): array;

    /** @throws LlmException for anything this provider cannot honour — before a byte is sent */
    abstract protected function assertSupported(LlmRequest $request): void;

    abstract protected function url(string $model): string;

    /** @return array<string, string> */
    abstract protected function headers(): array;

    /** @return array<string, mixed> */
    abstract protected function payload(LlmRequest $request, string $model): array;

    /** @param array<string, mixed> $body */
    abstract protected function parse(array $body, string $model): LlmResponse;

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function send(string $url, array $headers, array $payload): array
    {
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => $headers,
                'json' => $payload,
                'timeout' => $this->timeout,
                'retry' => 0,
                'block_private' => $this->settings->blockPrivate,
                'max_response_bytes' => $this->maxResponseBytes,
            ]);
        } catch (\Throwable $e) {
            // Rewrapped WITHOUT `previous`: the transport's own message can
            // contain whatever it was handed, and a chain walked by a logger
            // would print it. The cause is kept as a class name and a
            // scrubbed message.
            throw $this->failure('request failed: ' . $this->scrub($e->getMessage()), ['cause' => $e::class]);
        }

        if (!$response->ok()) {
            throw $this->httpError($response->status(), $response->json());
        }

        $decoded = $response->json();
        if (!\is_array($decoded)) {
            throw $this->failure('returned a body that is not a JSON object', ['status' => $response->status()]);
        }

        return $decoded;
    }

    /** @param mixed $body decoded JSON error body, if there was one */
    private function httpError(int $status, mixed $body): GatewayException
    {
        $context = ['status' => $status];

        if (\is_array($body)) {
            $error = $body['error'] ?? null;
            // Most specific first: Gemini's `status` (UNAUTHENTICATED) over its
            // numeric `code`, OpenAI's `code` (invalid_api_key) over its `type`.
            $code = null;
            foreach (['status', 'code', 'type'] as $field) {
                if (\is_array($error) && \is_string($error[$field] ?? null)) {
                    $code = $error[$field];
                    break;
                }
            }
            $message = \is_array($error) ? ($error['message'] ?? null) : (\is_string($error) ? $error : null);

            if ($code !== null) {
                $context['error'] = substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '', $code), 0, 64);
            }

            // Never for 401/403. OpenAI answers a bad key with "Incorrect API
            // key provided: sk-abc…xyz" — a masked key is still part of the
            // key, and an authentication failure's message has nothing the
            // operator needs that the status does not already say.
            if (\is_string($message) && !\in_array($status, [401, 403], true)) {
                $context['provider_message'] = mb_strcut($this->scrub($message), 0, 300, 'UTF-8');
            }
        }

        return $this->failure("returned HTTP {$status}" . (isset($context['error']) ? " ({$context['error']})" : ''), $context);
    }

    /** @param array<string, mixed> $context */
    protected function failure(string $what, array $context = []): GatewayException
    {
        return new GatewayException(
            "LLM provider [{$this->name()}] {$what}",
            layer: 'gateway.llm.' . $this->name(),
            context: ['provider' => $this->name()] + $context,
        );
    }

    protected function scrub(string $text): string
    {
        return $this->settings->apiKey?->scrub($text) ?? $text;
    }

    /** Decode a JSON-object string a model produced; a failure is the provider's, so a GatewayException. @return array<string, mixed> */
    protected function decodeArguments(mixed $raw): array
    {
        if (\is_array($raw)) {
            return $raw;
        }
        if ($raw === null || $raw === '') {
            return [];
        }
        $decoded = \is_string($raw) ? json_decode($raw, true) : null;
        if (!\is_array($decoded)) {
            throw $this->failure('produced a tool call whose arguments are not a JSON object');
        }

        return $decoded;
    }

    /** A field the response must carry; its absence is a malformed response, not an empty string. */
    protected function required(mixed $value, string $what): string
    {
        if (!\is_string($value) || $value === '') {
            throw $this->failure("returned a response with no {$what}");
        }

        return $value;
    }

    /** `[]` would encode as a JSON array where every provider wants an object. */
    protected static function object(array $value): array|\stdClass
    {
        return $value === [] ? new \stdClass() : $value;
    }
}
