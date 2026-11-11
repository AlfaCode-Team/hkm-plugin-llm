<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

/**
 * What a provider is and can do — and nothing that could be a credential.
 *
 * This is the ONLY description a gateway offers, and it is built from fields
 * that never held the key: a name, a model id, the HOST of the base URL (not
 * the URL — an operator can put a path or a token there), and capability
 * flags. There is no "debug" variant that adds more.
 */
final readonly class ProviderInfo
{
    /**
     * @param array<string, bool> $capabilities structured_output, tool_use, effort, required_tool_choice
     */
    public function __construct(
        public string $name,
        public string $model,
        public string $host,
        public array $capabilities,
    ) {}

    /** @return array{name: string, model: string, host: string, capabilities: array<string, bool>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'model' => $this->model,
            'host' => $this->host,
            'capabilities' => $this->capabilities,
        ];
    }
}
