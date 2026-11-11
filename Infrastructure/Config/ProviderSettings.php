<?php

declare(strict_types=1);

namespace Plugins\Llm\Infrastructure\Config;

use Plugins\Llm\Infrastructure\Support\Secret;

/**
 * One provider's configuration, with its own account of what is missing.
 *
 * A provider that is not fully configured is not an error until somebody asks
 * for it — a deployment that only uses Ollama has no OpenAI key and must boot.
 * `missing` is therefore data, and the registry turns it into the exception
 * that names the exact variables.
 */
final readonly class ProviderSettings
{
    /** @param list<string> $missing */
    public function __construct(
        public string $name,
        public ?Secret $apiKey,
        public ?string $model,
        public string $baseUrl,
        public bool $nativeSchema,
        public bool $blockPrivate,
        public array $missing,
    ) {}

    public function isConfigured(): bool
    {
        return $this->missing === [];
    }

    /** host[:port] only — the base URL itself may carry a path an operator would rather not print. */
    public function host(): string
    {
        $host = (string) parse_url($this->baseUrl, PHP_URL_HOST);
        $port = parse_url($this->baseUrl, PHP_URL_PORT);

        return $port === null || $port === false ? $host : "{$host}:{$port}";
    }
}
