<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Unit;

use AlfacodeTeam\PhpIoCli\BufferIO;
use PHPUnit\Framework\TestCase;
use Plugins\Llm\Infrastructure\Cli\ProvidersCommand;
use Plugins\Llm\Infrastructure\Config\LlmConfig;
use Plugins\Llm\Infrastructure\Gateways\GatewayFactory;
use Plugins\Llm\Infrastructure\Support\NoHttpClient;
use Tests\Plugins\Llm\Support\Make;

/**
 * The command, actually executed. `Table::render()` writes to STDOUT rather
 * than the IO object, so both channels are captured and joined (the same
 * quirk the legacy-passwords command test documents).
 */
final class ProvidersCommandTest extends TestCase
{
    /** @param array<string, mixed> $env @return array{code: int, output: string} */
    private function run_(array $env): array
    {
        $command = new ProvidersCommand(GatewayFactory::registry(LlmConfig::fromArray($env), static fn () => new NoHttpClient()));
        $io = new BufferIO();

        ob_start();
        $code = $command->execute([], $io);
        $direct = (string) ob_get_clean();

        return ['code' => $code, 'output' => (string) preg_replace('/\e\[[0-9;]*m/', '', $direct . "\n" . $io->getOutput())];
    }

    public function test_it_lists_configured_providers_and_names_what_the_others_lack(): void
    {
        $r = $this->run_([
            'LLM_ANTHROPIC_API_KEY' => Make::ANTHROPIC_KEY,
            'LLM_OLLAMA_MODEL' => 'llama3:8b',
            'LLM_OLLAMA_BASE_URL' => 'http://gpu-box.internal:11434/private/prefix',
        ]);

        self::assertSame(0, $r['code']);
        self::assertStringContainsString('anthropic', $r['output']);
        self::assertStringContainsString('claude-sonnet-5-5', $r['output']);
        self::assertStringContainsString('api.anthropic.com', $r['output']);
        self::assertStringContainsString('llama3:8b', $r['output']);
        self::assertStringContainsString('gpu-box.internal:11434', $r['output']);
        self::assertStringContainsString('+structured_output', $r['output']);
        self::assertStringContainsString('-effort', $r['output'], 'Ollama cannot honour an effort, and says so');
        self::assertStringContainsString('openai: not configured, missing LLM_OPENAI_API_KEY, LLM_OPENAI_MODEL', $r['output']);
    }

    public function test_the_output_carries_no_key_and_no_url_path(): void
    {
        $r = $this->run_([
            'LLM_ANTHROPIC_API_KEY' => Make::ANTHROPIC_KEY, 'LLM_OPENAI_API_KEY' => Make::OPENAI_KEY, 'LLM_OPENAI_MODEL' => 'm',
            'LLM_GEMINI_API_KEY' => Make::GEMINI_KEY, 'LLM_GEMINI_MODEL' => 'g',
            'LLM_OLLAMA_MODEL' => 'x', 'LLM_OLLAMA_BASE_URL' => 'http://h:1/private/prefix',
        ]);

        foreach ([Make::ANTHROPIC_KEY, Make::OPENAI_KEY, Make::GEMINI_KEY, 'TESTKEY', 'private/prefix'] as $needle) {
            self::assertStringNotContainsString($needle, $r['output']);
        }
    }

    public function test_with_nothing_configured_it_fails_and_says_so(): void
    {
        $r = $this->run_([]);

        self::assertSame(1, $r['code']);
        self::assertStringContainsString('No LLM provider is configured', $r['output']);
        self::assertStringContainsString('LLM_ANTHROPIC_API_KEY', $r['output']);
    }
}
