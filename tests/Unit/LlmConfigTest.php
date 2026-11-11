<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\Llm\Infrastructure\Config\LlmConfig;

final class LlmConfigTest extends TestCase
{
    public function test_defaults_configure_only_what_can_work_without_a_secret(): void
    {
        $c = LlmConfig::fromArray([]);

        self::assertSame('anthropic', $c->defaultProvider);
        self::assertSame([60, 4096, 8_388_608], [$c->timeout, $c->defaultMaxTokens, $c->maxResponseBytes]);
        self::assertSame(['LLM_ANTHROPIC_API_KEY'], $c->providers['anthropic']->missing, 'Anthropic has a default model, never a default key');
        self::assertSame('claude-sonnet-5-5', $c->providers['anthropic']->model);
        self::assertSame(['LLM_OPENAI_API_KEY', 'LLM_OPENAI_MODEL'], $c->providers['openai']->missing);
        self::assertSame(['LLM_GEMINI_API_KEY', 'LLM_GEMINI_MODEL'], $c->providers['gemini']->missing);
        self::assertSame(['LLM_OLLAMA_MODEL'], $c->providers['ollama']->missing, 'Ollama needs no key, only a model');
    }

    public function test_the_model_id_is_configuration_not_a_constant(): void
    {
        $c = LlmConfig::fromArray(['LLM_ANTHROPIC_API_KEY' => 'k', 'LLM_ANTHROPIC_MODEL' => 'claude-opus-5-5']);

        self::assertSame('claude-opus-5-5', $c->providers['anthropic']->model);
        self::assertTrue($c->providers['anthropic']->isConfigured());
    }

    public function test_blank_values_count_as_absent(): void
    {
        // `.env` seeding writes `KEY=` for a required variable with no default.
        $c = LlmConfig::fromArray(['LLM_ANTHROPIC_API_KEY' => '', 'LLM_ANTHROPIC_MODEL' => '  ', 'LLM_DEFAULT_PROVIDER' => '']);

        self::assertSame(['LLM_ANTHROPIC_API_KEY'], $c->providers['anthropic']->missing);
        self::assertSame('claude-sonnet-5-5', $c->providers['anthropic']->model);
        self::assertSame('anthropic', $c->defaultProvider);
    }

    public function test_private_address_blocking_defaults_on_for_hosted_and_off_for_ollama(): void
    {
        $c = LlmConfig::fromArray([]);
        self::assertTrue($c->providers['anthropic']->blockPrivate);
        self::assertTrue($c->providers['openai']->blockPrivate);
        self::assertFalse($c->providers['ollama']->blockPrivate);

        $c = LlmConfig::fromArray(['LLM_BLOCK_PRIVATE' => 'false', 'LLM_OLLAMA_BLOCK_PRIVATE' => '1']);
        self::assertFalse($c->providers['gemini']->blockPrivate);
        self::assertTrue($c->providers['ollama']->blockPrivate);
    }

    public function test_numbers_are_clamped_and_a_hosted_provider_must_be_https(): void
    {
        $c = LlmConfig::fromArray(['LLM_TIMEOUT' => '99999', 'LLM_DEFAULT_MAX_TOKENS' => '0', 'LLM_MAX_RESPONSE_BYTES' => '1', 'LLM_OPENAI_BASE_URL' => 'http://api.openai.com']);

        self::assertSame([600, 1, 65_536], [$c->timeout, $c->defaultMaxTokens, $c->maxResponseBytes]);
        self::assertStringContainsString('LLM_OPENAI_BASE_URL', implode(' ', $c->providers['openai']->missing), 'a key must not be sent in the clear');
    }

    public function test_ollama_may_be_http_and_the_host_is_reported_without_the_path(): void
    {
        $c = LlmConfig::fromArray(['LLM_OLLAMA_MODEL' => 'llama3:8b', 'LLM_OLLAMA_BASE_URL' => 'http://gpu-box.internal:11434/some/prefix/']);

        self::assertTrue($c->providers['ollama']->isConfigured());
        self::assertSame('http://gpu-box.internal:11434/some/prefix', $c->providers['ollama']->baseUrl);
        self::assertSame('gpu-box.internal:11434', $c->providers['ollama']->host());
    }

    public function test_a_model_id_that_is_not_plain_is_refused(): void
    {
        $c = LlmConfig::fromArray(['LLM_OPENAI_API_KEY' => 'k', 'LLM_OPENAI_MODEL' => "gpt\n; drop"]);

        self::assertFalse($c->providers['openai']->isConfigured());
        self::assertNull($c->providers['openai']->model);
    }

    public function test_fromenv_reads_the_environment_with_env_not_getenv(): void
    {
        $_ENV['LLM_OPENAI_API_KEY'] = 'sk-from-env-xyz';
        $_ENV['LLM_OPENAI_MODEL'] = 'gpt-from-env';
        try {
            $c = LlmConfig::fromEnv();
            self::assertTrue($c->providers['openai']->isConfigured());
            self::assertSame('gpt-from-env', $c->providers['openai']->model);
            self::assertSame('sk-from-env-xyz', $c->providers['openai']->apiKey?->reveal());
        } finally {
            unset($_ENV['LLM_OPENAI_API_KEY'], $_ENV['LLM_OPENAI_MODEL']);
        }
    }

    public function test_every_variable_module_json_declares_is_read_and_vice_versa(): void
    {
        $declared = array_column(json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/module.json'), true)['config'], 'key');
        $source = (string) file_get_contents(\dirname(__DIR__, 2) . '/Infrastructure/Config/LlmConfig.php');
        preg_match_all("/env\('(LLM_[A-Z_]+)'\)/", $source, $m);

        sort($declared);
        $read = array_values(array_unique($m[1]));
        sort($read);
        self::assertSame($declared, $read);
    }
}
