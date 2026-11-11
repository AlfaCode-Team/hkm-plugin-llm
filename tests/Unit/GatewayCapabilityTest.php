<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Unit;

use PHPUnit\Framework\TestCase;
use Plugins\Llm\API\Schema;
use Plugins\Llm\Application\Ports\ModelGateway;
use Tests\Plugins\Llm\Support\Make;
use Tests\Plugins\Llm\Support\RecordingHttpClient;

/**
 * "Can this provider CONSTRAIN its output to this schema?" — the question the
 * service uses to decide between native, refuse and prompt fallback. A wrong
 * `null` here is a schema silently not enforced by the provider, so each
 * reason is pinned individually and by its text.
 */
final class GatewayCapabilityTest extends TestCase
{
    private function gw(string $provider, array $env = []): ModelGateway
    {
        return Make::gateway($provider, new RecordingHttpClient(), $env);
    }

    public function test_a_closed_object_schema_is_honoured_natively_by_all_four(): void
    {
        foreach (['anthropic', 'openai', 'gemini', 'ollama'] as $p) {
            self::assertNull($this->gw($p)->cannotConstrain(Make::personSchema()), $p);
        }
    }

    public function test_anthropic_and_openai_refuse_what_constrained_decoding_cannot_take(): void
    {
        $open = new Schema('s', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']]);
        $ranged = Schema::object('s', ['a' => ['type' => 'integer', 'minimum' => 1]]);
        $notObject = new Schema('s', ['type' => 'array', 'items' => ['type' => 'string']]);

        foreach (['anthropic', 'openai'] as $p) {
            self::assertStringContainsString('closed schema', (string) $this->gw($p)->cannotConstrain($open), "{$p}: open");
            self::assertStringContainsString('range keywords', (string) $this->gw($p)->cannotConstrain($ranged), "{$p}: ranged");
            self::assertStringContainsString('root of the schema must be an object', (string) $this->gw($p)->cannotConstrain($notObject), "{$p}: root");
        }
    }

    public function test_the_native_switch_turns_a_provider_off_for_every_schema(): void
    {
        foreach (['anthropic', 'openai', 'gemini', 'ollama'] as $p) {
            $why = $this->gw($p, ['LLM_' . strtoupper($p) . '_NATIVE_SCHEMA' => 'false'])->cannotConstrain(Make::personSchema());

            self::assertStringContainsString('LLM_' . strtoupper($p) . '_NATIVE_SCHEMA', (string) $why, $p);
            self::assertFalse($this->gw($p, ['LLM_' . strtoupper($p) . '_NATIVE_SCHEMA' => '0'])->describe()->capabilities['structured_output'], $p);
        }
    }

    public function test_gemini_refuses_a_node_with_several_non_null_types(): void
    {
        $multi = new Schema('s', ['type' => 'object', 'properties' => ['a' => ['type' => ['string', 'integer']]]]);

        self::assertStringContainsString('one non-null type', (string) $this->gw('gemini')->cannotConstrain($multi));
        self::assertNull($this->gw('ollama')->cannotConstrain($multi), 'Ollama takes the JSON schema itself');
    }

    public function test_describe_reports_capabilities_per_provider(): void
    {
        self::assertSame(
            ['structured_output' => true, 'tool_use' => true, 'effort' => false, 'required_tool_choice' => false],
            $this->gw('ollama')->describe()->capabilities,
        );
        self::assertTrue($this->gw('anthropic')->describe()->capabilities['effort']);
        self::assertSame('api.anthropic.com', $this->gw('anthropic')->describe()->host);
    }
}
