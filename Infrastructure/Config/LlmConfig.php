<?php

declare(strict_types=1);

namespace Plugins\Llm\Infrastructure\Config;

use Plugins\Llm\Infrastructure\Support\Secret;

/**
 * Every knob, normalised once.
 *
 * Read with `env()` and never `getenv()`: `$_ENV` is the source of truth here
 * and `putenv()` is never called. An EMPTY value counts as absent — `.env`
 * seeding writes `KEY=` for a variable that is required but has no default,
 * and `''` must never become an API key or a model id.
 */
final readonly class LlmConfig
{
    public const PROVIDERS = ['anthropic', 'openai', 'gemini', 'ollama'];

    private const HOSTED = ['anthropic', 'openai', 'gemini'];

    private const BASE_URLS = [
        'anthropic' => 'https://api.anthropic.com',
        'openai' => 'https://api.openai.com',
        'gemini' => 'https://generativelanguage.googleapis.com',
        'ollama' => 'http://127.0.0.1:11434',
    ];

    /** @param array<string, ProviderSettings> $providers */
    private function __construct(
        public string $defaultProvider,
        public int $timeout,
        public int $defaultMaxTokens,
        public int $maxResponseBytes,
        public array $providers,
    ) {}

    public static function fromEnv(): self
    {
        // Each key is spelled out, not built from a list: `ground check`
        // matches literal `env('KEY')` calls against module.json config[],
        // and a loop over names would be invisible to it.
        return self::fromArray(array_filter([
            'LLM_DEFAULT_PROVIDER' => env('LLM_DEFAULT_PROVIDER'),
            'LLM_TIMEOUT' => env('LLM_TIMEOUT'),
            'LLM_DEFAULT_MAX_TOKENS' => env('LLM_DEFAULT_MAX_TOKENS'),
            'LLM_MAX_RESPONSE_BYTES' => env('LLM_MAX_RESPONSE_BYTES'),
            'LLM_BLOCK_PRIVATE' => env('LLM_BLOCK_PRIVATE'),
            'LLM_ANTHROPIC_API_KEY' => env('LLM_ANTHROPIC_API_KEY'),
            'LLM_ANTHROPIC_MODEL' => env('LLM_ANTHROPIC_MODEL'),
            'LLM_ANTHROPIC_BASE_URL' => env('LLM_ANTHROPIC_BASE_URL'),
            'LLM_ANTHROPIC_NATIVE_SCHEMA' => env('LLM_ANTHROPIC_NATIVE_SCHEMA'),
            'LLM_OPENAI_API_KEY' => env('LLM_OPENAI_API_KEY'),
            'LLM_OPENAI_MODEL' => env('LLM_OPENAI_MODEL'),
            'LLM_OPENAI_BASE_URL' => env('LLM_OPENAI_BASE_URL'),
            'LLM_OPENAI_NATIVE_SCHEMA' => env('LLM_OPENAI_NATIVE_SCHEMA'),
            'LLM_GEMINI_API_KEY' => env('LLM_GEMINI_API_KEY'),
            'LLM_GEMINI_MODEL' => env('LLM_GEMINI_MODEL'),
            'LLM_GEMINI_BASE_URL' => env('LLM_GEMINI_BASE_URL'),
            'LLM_GEMINI_NATIVE_SCHEMA' => env('LLM_GEMINI_NATIVE_SCHEMA'),
            'LLM_OLLAMA_MODEL' => env('LLM_OLLAMA_MODEL'),
            'LLM_OLLAMA_BASE_URL' => env('LLM_OLLAMA_BASE_URL'),
            'LLM_OLLAMA_NATIVE_SCHEMA' => env('LLM_OLLAMA_NATIVE_SCHEMA'),
            'LLM_OLLAMA_BLOCK_PRIVATE' => env('LLM_OLLAMA_BLOCK_PRIVATE'),
        ], static fn (mixed $value): bool => $value !== null));
    }

    /** @param array<string, mixed> $v the same keys as the environment */
    public static function fromArray(array $v): self
    {
        $blockPrivate = self::bool($v['LLM_BLOCK_PRIVATE'] ?? null, true);
        $providers = [];

        foreach (self::PROVIDERS as $name) {
            $p = 'LLM_' . strtoupper($name) . '_';
            $hosted = \in_array($name, self::HOSTED, true);
            $missing = [];

            $key = self::text($v[$p . 'API_KEY'] ?? null);
            if ($hosted && $key === null) {
                $missing[] = $p . 'API_KEY';
            }

            // Anthropic has a default model id; the others do not, because
            // model ids change faster than a plugin's releases and a stale
            // default is a quiet 404 months later.
            $model = self::text($v[$p . 'MODEL'] ?? null) ?? ($name === 'anthropic' ? 'claude-sonnet-5-5' : null);
            if ($model === null) {
                $missing[] = $p . 'MODEL';
            } elseif (preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}\z/', $model) !== 1) {
                $missing[] = $p . 'MODEL (not a plain model id)';
                $model = null;
            }

            $base = rtrim(self::text($v[$p . 'BASE_URL'] ?? null) ?? self::BASE_URLS[$name], '/');
            if (!self::validBaseUrl($base, requireHttps: $hosted)) {
                $missing[] = $p . 'BASE_URL (must be an '
                    . ($hosted ? 'https' : 'http(s)') . ' URL with a host and no credentials, query or fragment)';
                $base = self::BASE_URLS[$name];
            }

            $providers[$name] = new ProviderSettings(
                name: $name,
                apiKey: $key === null ? null : new Secret($key),
                model: $model,
                baseUrl: $base,
                nativeSchema: self::bool($v[$p . 'NATIVE_SCHEMA'] ?? null, true),
                blockPrivate: $hosted ? $blockPrivate : self::bool($v['LLM_OLLAMA_BLOCK_PRIVATE'] ?? null, false),
                missing: $missing,
            );
        }

        return new self(
            defaultProvider: strtolower(self::text($v['LLM_DEFAULT_PROVIDER'] ?? null) ?? 'anthropic'),
            timeout: max(1, min(600, (int) ($v['LLM_TIMEOUT'] ?? 60))),
            defaultMaxTokens: max(1, min(200_000, (int) ($v['LLM_DEFAULT_MAX_TOKENS'] ?? 4096))),
            // Replies are small; a ceiling far below the HTTP client's 32 MB
            // default stops a hostile or broken endpoint streaming filler.
            maxResponseBytes: max(65_536, min(67_108_864, (int) ($v['LLM_MAX_RESPONSE_BYTES'] ?? 8_388_608))),
            providers: $providers,
        );
    }

    private static function text(mixed $value): ?string
    {
        if ($value === null || \is_array($value) || \is_object($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private static function bool(mixed $value, bool $default): bool
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    /**
     * No userinfo, query or fragment: a URL is what the HTTP client puts in its
     * error messages, so anything secret in one would be printed by it.
     */
    private static function validBaseUrl(string $url, bool $requireHttps): bool
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            return false;
        }
        $scheme = strtolower($parts['scheme']);
        if (!\in_array($scheme, $requireHttps ? ['https'] : ['http', 'https'], true)) {
            return false;
        }

        return !isset($parts['user']) && !isset($parts['pass']) && !isset($parts['query']) && !isset($parts['fragment']);
    }
}
