<?php

declare(strict_types=1);

namespace Plugins\Llm\Infrastructure\Gateways;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use Plugins\Llm\Application\Ports\ModelGateway;
use Plugins\Llm\Application\Services\GatewayRegistry;
use Plugins\Llm\Infrastructure\Config\LlmConfig;

final class GatewayFactory
{
    /**
     * @param \Closure(): HttpClientPort $http resolved lazily — only when a gateway is first built
     */
    public static function registry(LlmConfig $config, \Closure $http): GatewayRegistry
    {
        $factories = [];
        $unconfigured = [];

        foreach ($config->providers as $name => $settings) {
            if (!$settings->isConfigured()) {
                $unconfigured[$name] = $settings->missing;
                continue;
            }

            $factories[$name] = static function () use ($name, $settings, $config, $http): ModelGateway {
                $args = [$http(), $settings, $config->timeout, $config->maxResponseBytes, $config->defaultMaxTokens];

                return match ($name) {
                    'anthropic' => new AnthropicGateway(...$args),
                    'openai' => new OpenAiGateway(...$args),
                    'gemini' => new GeminiGateway(...$args),
                    default => new OllamaGateway(...$args),
                };
            };
        }

        return new GatewayRegistry($config->defaultProvider, $factories, $unconfigured);
    }
}
