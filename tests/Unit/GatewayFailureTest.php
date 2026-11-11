<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Unit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\Application\Exceptions\LlmException;
use Tests\Plugins\Llm\Support\Make;
use Tests\Plugins\Llm\Support\RecordingHttpClient;

/**
 * No vendor or transport failure may leave a gateway as anything but a
 * GatewayException, and a malformed 200 must not become an empty answer.
 */
final class GatewayFailureTest extends TestCase
{
    public function test_a_rate_limit_is_a_gateway_exception_with_the_status_and_error_code(): void
    {
        try {
            Make::gateway('anthropic', RecordingHttpClient::fixture('anthropic_error_rate_limit', 429))->generate(LlmRequest::prompt('x'));
            self::fail();
        } catch (GatewayException $e) {
            self::assertSame('LLM provider [anthropic] returned HTTP 429 (rate_limit_error)', $e->getMessage());
            self::assertSame(['provider' => 'anthropic', 'status' => 429, 'error' => 'rate_limit_error'], array_diff_key($e->context, ['provider_message' => 1]));
            self::assertStringContainsString('per-minute', $e->context['provider_message']);
        }
    }

    #[DataProvider('everyProvider')]
    public function test_a_failing_status_with_a_hostile_body_is_not_echoed(string $provider): void
    {
        $http = RecordingHttpClient::answering('<html>' . str_repeat('A', 100_000) . '</html>', 502);

        try {
            Make::gateway($provider, $http)->generate(LlmRequest::prompt('x'));
            self::fail();
        } catch (GatewayException $e) {
            self::assertSame("LLM provider [{$provider}] returned HTTP 502", $e->getMessage());
            self::assertLessThan(500, \strlen((string) json_encode($e->context)));
        }
    }

    #[DataProvider('everyProvider')]
    public function test_a_redirect_is_a_failure_not_followed_and_not_parsed(string $provider): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('returned HTTP 302');
        Make::gateway($provider, RecordingHttpClient::answering('{}', 302))->generate(LlmRequest::prompt('x'));
    }

    #[DataProvider('everyProvider')]
    public function test_a_200_that_is_not_json_is_a_failure(string $provider): void
    {
        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('not a JSON object');
        Make::gateway($provider, RecordingHttpClient::answering('OK', 200))->generate(LlmRequest::prompt('x'));
    }

    /** @param string $body */
    #[DataProvider('malformedBodies')]
    public function test_a_200_with_the_wrong_shape_is_a_gateway_failure_never_an_empty_answer(string $provider, string $body): void
    {
        $this->expectException(GatewayException::class);
        Make::gateway($provider, RecordingHttpClient::answering($body))->generate(LlmRequest::prompt('x'));
    }

    public static function malformedBodies(): iterable
    {
        yield 'anthropic: no content' => ['anthropic', '{"stop_reason":"end_turn"}'];
        yield 'anthropic: tool_use without a name' => ['anthropic', '{"content":[{"type":"tool_use","id":"t","input":{}}],"stop_reason":"tool_use"}'];
        yield 'openai: no choices' => ['openai', '{"choices":[]}'];
        yield 'openai: tool call without a name' => ['openai', '{"choices":[{"message":{"tool_calls":[{"id":"c","function":{"arguments":"{}"}}]},"finish_reason":"tool_calls"}]}'];
        yield 'openai: arguments not an object' => ['openai', '{"choices":[{"message":{"tool_calls":[{"id":"c","function":{"name":"x","arguments":"[1"}}]},"finish_reason":"tool_calls"}]}'];
        yield 'gemini: no candidates and no block reason' => ['gemini', '{"usageMetadata":{}}'];
        yield 'gemini: function call without a name' => ['gemini', '{"candidates":[{"content":{"parts":[{"functionCall":{"args":{}}}]}}]}'];
        yield 'ollama: no message' => ['ollama', '{"done":true}'];
        yield 'ollama: tool call without a name' => ['ollama', '{"message":{"tool_calls":[{"function":{"arguments":{}}}]}}'];
        yield 'text where an object belongs (openai)' => ['openai', '{"choices":["x"]}'];
        yield 'text where an object belongs (gemini)' => ['gemini', '{"candidates":["x"]}'];
        yield 'text where an object belongs (ollama)' => ['ollama', '{"message":"x"}'];
        yield 'text where an object belongs (anthropic)' => ['anthropic', '{"content":["x"]}'];
    }

    #[DataProvider('everyProvider')]
    public function test_a_transport_failure_is_a_gateway_exception_in_the_providers_layer(string $provider): void
    {
        $http = (new RecordingHttpClient())->failWith(new \RuntimeException('cURL error 28: Operation timed out'));

        try {
            Make::gateway($provider, $http)->generate(LlmRequest::prompt('x'));
            self::fail();
        } catch (GatewayException $e) {
            self::assertSame("gateway.llm.{$provider}", $e->layer);
            self::assertStringContainsString('timed out', $e->getMessage());
            self::assertSame(\RuntimeException::class, $e->context['cause']);
        }
    }

    public function test_a_model_that_is_not_configured_names_the_variable_and_sends_nothing(): void
    {
        $http = RecordingHttpClient::fixture('openai_text');
        $gateway = Make::gateway('openai', $http, ['LLM_OPENAI_MODEL' => '']);

        try {
            $gateway->generate(LlmRequest::prompt('x'));
            self::fail();
        } catch (LlmException $e) {
            self::assertStringContainsString('LLM_OPENAI_MODEL', $e->getMessage());
        }
        self::assertSame([], $http->calls);
    }

    public static function everyProvider(): iterable
    {
        foreach (['anthropic', 'openai', 'gemini', 'ollama'] as $p) {
            yield $p => [$p];
        }
    }
}
