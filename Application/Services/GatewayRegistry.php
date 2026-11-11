<?php

declare(strict_types=1);

namespace Plugins\Llm\Application\Services;

use Plugins\Llm\API\ProviderInfo;
use Plugins\Llm\Application\Exceptions\LlmException;
use Plugins\Llm\Application\Ports\ModelGateway;

/**
 * The configured providers, built lazily.
 *
 * A gateway is constructed on first use, so a request that uses Anthropic does
 * not build the Ollama client, and — more to the point — resolves `HttpClientPort`
 * only when something is actually about to be sent.
 */
final class GatewayRegistry
{
    /** @var array<string, ModelGateway> */
    private array $built = [];

    /**
     * @param array<string, \Closure(): ModelGateway> $factories   configured providers
     * @param array<string, list<string>>              $unconfigured provider => the env vars that are missing
     */
    public function __construct(
        private readonly string $default,
        private readonly array $factories,
        private readonly array $unconfigured = [],
    ) {}

    public function get(?string $name): ModelGateway
    {
        $name ??= $this->default;

        if (isset($this->built[$name])) {
            return $this->built[$name];
        }
        if (isset($this->factories[$name])) {
            return $this->built[$name] = ($this->factories[$name])();
        }
        if (isset($this->unconfigured[$name])) {
            throw LlmException::notConfigured($name, $this->unconfigured[$name]);
        }

        throw LlmException::unknownProvider($name, array_keys($this->factories + $this->unconfigured));
    }

    /** @return list<ProviderInfo> configured providers only */
    public function describe(): array
    {
        return array_values(array_map(fn (string $n): ProviderInfo => $this->get($n)->describe(), array_keys($this->factories)));
    }

    /** @return array<string, list<string>> */
    public function unconfigured(): array
    {
        return $this->unconfigured;
    }
}
