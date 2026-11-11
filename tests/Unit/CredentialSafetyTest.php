<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Unit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\Infrastructure\Config\LlmConfig;
use Plugins\Llm\Infrastructure\Gateways\GatewayFactory;
use Plugins\Llm\Infrastructure\Support\NoHttpClient;
use Plugins\Llm\Infrastructure\Support\Secret;
use Tests\Plugins\Llm\Support\Make;
use Tests\Plugins\Llm\Support\RecordingHttpClient;

/**
 * Four providers' API keys are held by this plugin. None of them may appear
 * in a description, a dump, a log line or an exception — including an
 * exception built from a remote error that itself quotes the key.
 */
final class CredentialSafetyTest extends TestCase
{
    private const KEYS = [Make::ANTHROPIC_KEY, Make::OPENAI_KEY, Make::GEMINI_KEY];

    /** @param string|array<mixed>|object $thing */
    private static function everyRepresentation(mixed $thing): string
    {
        ob_start();
        var_dump($thing);
        $dump = (string) ob_get_clean();

        $serialised = '';
        try {
            $serialised = serialize($thing);
        } catch (\Throwable $e) {
            $serialised = 'serialize refused: ' . $e->getMessage();
        }

        return implode("\n", [
            $dump,
            print_r($thing, true),
            var_export($thing, true),
            (string) json_encode($thing, JSON_PARTIAL_OUTPUT_ON_ERROR),
            $serialised,
        ]);
    }

    private static function assertNoKey(string $haystack, string $where): void
    {
        foreach ([...self::KEYS, 'TESTKEY', 'secretWXYZ'] as $needle) {
            self::assertStringNotContainsString($needle, $haystack, "a key leaked through {$where}");
        }
    }

    #[DataProvider('hosted')]
    public function test_describe_carries_no_credential(string $provider, string $key = ''): void
    {
        $info = Make::gateway($provider, new RecordingHttpClient())->describe();

        self::assertNoKey(self::everyRepresentation($info), 'describe()');
        self::assertSame(['name', 'model', 'host', 'capabilities'], array_keys($info->toArray()));
    }

    #[DataProvider('hosted')]
    public function test_dumping_a_gateway_or_its_settings_does_not_print_the_key(string $provider, string $key = ''): void
    {
        $gateway = Make::gateway($provider, new RecordingHttpClient());

        self::assertNoKey(self::everyRepresentation($gateway), 'dumping the gateway');
        self::assertNoKey(self::everyRepresentation(Make::settings($provider)), 'dumping the settings');
    }

    public function test_dumping_the_whole_config_does_not_print_any_key(): void
    {
        $config = LlmConfig::fromArray([
            'LLM_ANTHROPIC_API_KEY' => Make::ANTHROPIC_KEY, 'LLM_OPENAI_API_KEY' => Make::OPENAI_KEY, 'LLM_GEMINI_API_KEY' => Make::GEMINI_KEY,
        ]);

        self::assertNoKey(self::everyRepresentation($config), 'dumping the config');
    }

    public function test_a_secret_prints_as_a_mask_in_every_form_and_refuses_serialisation(): void
    {
        $secret = new Secret('hunter2-very-secret');

        self::assertSame('[redacted]', (string) $secret);
        self::assertSame('[redacted]', sprintf('%s', $secret));
        self::assertSame('"[redacted]"', json_encode($secret));
        self::assertStringNotContainsString('hunter2', print_r($secret, true));
        self::assertStringNotContainsString('hunter2', var_export($secret, true));
        self::assertSame('hunter2-very-secret', $secret->reveal(), 'reveal() is the one door');
        $this->expectException(\LogicException::class);
        serialize($secret);
    }

    public function test_an_empty_secret_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Secret('');
    }

    #[DataProvider('hosted')]
    public function test_a_transport_error_that_quotes_the_key_is_scrubbed_and_not_chained(string $provider, string $key): void
    {
        $http = (new RecordingHttpClient())->failWith(
            new GatewayException("Outbound HTTP request to [https://x/?token={$key}] failed: bad header {$key}", layer: 'gateway.http_client'),
        );

        try {
            Make::gateway($provider, $http)->generate(LlmRequest::prompt('hi'));
            self::fail('expected a GatewayException');
        } catch (GatewayException $e) {
            self::assertNoKey($e->getMessage(), 'the exception message');
            self::assertNoKey(self::everyRepresentation($e->context), 'the exception context');
            self::assertNull($e->getPrevious(), 'the transport exception is not chained: its message is where a key hides');
            self::assertSame('gateway.llm.' . $provider, $e->layer);
            self::assertStringContainsString('[redacted]', $e->getMessage());
        }
    }

    public function test_openai_masked_key_in_a_401_body_is_not_forwarded(): void
    {
        // OpenAI answers a bad key with "Incorrect API key provided: sk-live-ABCD****WXYZ".
        // A masked key is still part of the key.
        $http = RecordingHttpClient::fixture('openai_bad_key', 401);

        try {
            Make::gateway('openai', $http)->generate(LlmRequest::prompt('hi'));
            self::fail('expected a GatewayException');
        } catch (GatewayException $e) {
            self::assertStringNotContainsString('sk-live', $e->getMessage());
            self::assertStringNotContainsString('sk-live', (string) json_encode($e->context));
            self::assertStringNotContainsString('ABCD', $e->getMessage() . json_encode($e->context));
            self::assertSame(401, $e->context['status']);
            self::assertSame('invalid_api_key', $e->context['error'] ?? null, 'the error CODE is safe and useful');
            self::assertArrayNotHasKey('provider_message', $e->context);
        }
    }

    public function test_a_provider_message_that_echoes_the_key_is_scrubbed_on_non_auth_errors(): void
    {
        $body = json_encode(['error' => ['type' => 'invalid_request_error', 'message' => 'bad param near ' . Make::OPENAI_KEY]]);

        try {
            Make::gateway('openai', RecordingHttpClient::answering((string) $body, 400))->generate(LlmRequest::prompt('hi'));
            self::fail('expected a GatewayException');
        } catch (GatewayException $e) {
            self::assertStringContainsString('[redacted]', $e->context['provider_message']);
            self::assertNoKey((string) json_encode($e->context) . $e->getMessage(), 'a provider error message');
        }
    }

    public function test_the_error_text_that_is_kept_is_bounded(): void
    {
        $body = (string) json_encode(['error' => ['type' => 'x', 'message' => str_repeat('é', 5000)]]);

        try {
            Make::gateway('anthropic', RecordingHttpClient::answering($body, 400))->generate(LlmRequest::prompt('hi'));
            self::fail('expected a GatewayException');
        } catch (GatewayException $e) {
            self::assertLessThanOrEqual(300, \strlen($e->context['provider_message']));
            self::assertTrue(mb_check_encoding($e->context['provider_message'], 'UTF-8'), 'a cut must not split a character');
        }
    }

    public function test_a_credential_url_in_the_base_url_marks_the_provider_unconfigured(): void
    {
        foreach (['https://user:pass@api.example.com', 'https://api.example.com/?key=abc', 'https://api.example.com/#frag', 'http://api.example.com', 'ftp://x'] as $url) {
            $s = Make::settings('openai', ['LLM_OPENAI_BASE_URL' => $url]);
            self::assertFalse($s->isConfigured(), $url);
            self::assertStringContainsString('LLM_OPENAI_BASE_URL', implode(' ', $s->missing));
            self::assertStringNotContainsString('pass', implode(' ', $s->missing), 'the bad URL itself is not echoed');
        }
    }

    public function test_a_provider_with_no_key_is_unconfigured_and_a_blank_key_is_no_key(): void
    {
        $config = LlmConfig::fromArray(['LLM_OPENAI_API_KEY' => '   ', 'LLM_OPENAI_MODEL' => 'm']);

        self::assertFalse($config->providers['openai']->isConfigured());
        self::assertSame(['LLM_OPENAI_API_KEY'], $config->providers['openai']->missing);
    }

    public function test_the_describe_only_http_client_cannot_send(): void
    {
        $registry = GatewayFactory::registry(
            LlmConfig::fromArray(['LLM_ANTHROPIC_API_KEY' => Make::ANTHROPIC_KEY]),
            static fn () => new NoHttpClient(),
        );

        self::assertNoKey(self::everyRepresentation($registry->describe()), 'registry describe');

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('only describes providers');
        $registry->get('anthropic')->generate(LlmRequest::prompt('hi'));
    }

    public static function hosted(): iterable
    {
        yield 'anthropic' => ['anthropic', Make::ANTHROPIC_KEY];
        yield 'openai' => ['openai', Make::OPENAI_KEY];
        yield 'gemini' => ['gemini', Make::GEMINI_KEY];
    }
}
