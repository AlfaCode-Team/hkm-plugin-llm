<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Support;

use Plugins\Llm\API\Message;
use Plugins\Llm\API\Parts\TextPart;
use Plugins\Llm\API\Parts\ToolCallPart;
use Plugins\Llm\API\Parts\ToolResultPart;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\Schema;
use Plugins\Llm\API\Tool;
use Plugins\Llm\Infrastructure\Config\LlmConfig;
use Plugins\Llm\Infrastructure\Config\ProviderSettings;
use Plugins\Llm\Infrastructure\Gateways\AnthropicGateway;
use Plugins\Llm\Infrastructure\Gateways\GeminiGateway;
use Plugins\Llm\Infrastructure\Gateways\HttpModelGateway;
use Plugins\Llm\Infrastructure\Gateways\OllamaGateway;
use Plugins\Llm\Infrastructure\Gateways\OpenAiGateway;

/** Shared builders. Keys are obviously fake and unique, so a leak is greppable. */
final class Make
{
    public const ANTHROPIC_KEY = 'sk-ant-TESTKEY-4f9a1c';
    public const OPENAI_KEY = 'sk-live-ABCD1234secretWXYZ';
    public const GEMINI_KEY = 'AIzaTESTKEY-gemini-77b2';

    /** @param array<string, mixed> $env */
    public static function settings(string $provider, array $env = []): ProviderSettings
    {
        $config = LlmConfig::fromArray($env + [
            'LLM_ANTHROPIC_API_KEY' => self::ANTHROPIC_KEY,
            'LLM_OPENAI_API_KEY' => self::OPENAI_KEY,
            'LLM_OPENAI_MODEL' => 'gpt-test-2026',
            'LLM_GEMINI_API_KEY' => self::GEMINI_KEY,
            'LLM_GEMINI_MODEL' => 'gemini-test-2026',
            'LLM_OLLAMA_MODEL' => 'llama-test',
        ]);

        return $config->providers[$provider];
    }

    /** @param array<string, mixed> $env */
    public static function gateway(string $provider, RecordingHttpClient $http, array $env = []): HttpModelGateway
    {
        $s = self::settings($provider, $env);
        $class = match ($provider) {
            'anthropic' => AnthropicGateway::class,
            'openai' => OpenAiGateway::class,
            'gemini' => GeminiGateway::class,
            'ollama' => OllamaGateway::class,
        };

        return new $class($http, $s, 30, 1_000_000, 4096);
    }

    public static function invoiceTool(): Tool
    {
        return new Tool('get_invoice', 'Fetch one invoice by id.', Schema::object('get_invoice_input', ['id' => ['type' => 'string']]));
    }

    public static function pingTool(): Tool
    {
        return new Tool('ping', 'Check the service is up.', Schema::object('ping_input', []));
    }

    /** system + user + an assistant turn with two tool calls + their results; every provider's golden request. */
    public static function toolConversation(): LlmRequest
    {
        return new LlmRequest(
            messages: [
                Message::system('Be brief.'),
                Message::user('Invoice 7?'),
                Message::assistant([
                    new TextPart('Checking.'),
                    new ToolCallPart('c1', 'get_invoice', ['id' => 'inv-7']),
                    new ToolCallPart('c2', 'ping', []),
                ]),
                Message::toolResults([
                    new ToolResultPart('c1', 'get_invoice', '{"total":100}'),
                    new ToolResultPart('c2', 'ping', 'pong'),
                ]),
            ],
            tools: [self::invoiceTool(), self::pingTool()],
            toolChoice: \Plugins\Llm\API\ToolChoice::auto(),
            maxTokens: 500,
            temperature: 0.2,
            stop: ['END'],
        );
    }

    public static function personSchema(): Schema
    {
        return Schema::object('person', ['name' => ['type' => 'string'], 'age' => ['type' => 'integer']]);
    }
}
