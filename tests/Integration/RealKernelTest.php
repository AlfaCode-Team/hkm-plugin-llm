<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Integration;

use AlfacodeTeam\Ground\BootedGround;
use AlfacodeTeam\Ground\PluginGround;
use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ScopeViolationException;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\TestCase;
use Plugins\HttpClient\Provider as HttpClientProvider;
use Plugins\Llm\API\Contracts\LlmContract;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\Tool;
use Plugins\Llm\API\Tools\CallbackAuthorizer;
use Plugins\Llm\API\Tools\ToolBox;
use Plugins\Llm\Application\Services\GatewayRegistry;
use Plugins\Llm\Provider;
use Tests\Plugins\Llm\Support\Make;
use Tests\Plugins\Llm\Support\RecordingHttpClient;

/**
 * The plugin on the REAL kernel: module.json compiled by the boot pipeline,
 * bindings resolved through real scoped containers, the real http.client
 * provider in the graph, and the command registered through the real CLI
 * pipeline. Only the transport is replaced — by a recording client — so no
 * request leaves the process.
 *
 * Not covered: a real cURL request, and any real provider.
 */
final class RealKernelTest extends TestCase
{
    private ?BootedGround $g = null;
    private RecordingHttpClient $http;

    protected function setUp(): void
    {
        $this->http = new RecordingHttpClient();
        $this->g = PluginGround::for(Provider::class, HttpClientProvider::class)
            ->port(HttpClientPort::class, $this->http)
            ->env([
                'LLM_ANTHROPIC_API_KEY' => Make::ANTHROPIC_KEY,
                'LLM_OPENAI_API_KEY' => Make::OPENAI_KEY,
                'LLM_OPENAI_MODEL' => 'gpt-test-2026',
                'LLM_OLLAMA_MODEL' => 'llama-test',
            ])
            ->as(Identity::asUser('alice', 'tenant-a'))
            ->boot();
    }

    protected function tearDown(): void
    {
        $this->g?->destroy();
    }

    private function llm(): LlmContract
    {
        $llm = $this->g?->service(LlmContract::class);
        self::assertInstanceOf(LlmContract::class, $llm);

        return $llm;
    }

    public function test_the_published_contract_resolves_and_completes_through_the_real_container(): void
    {
        $this->http->queueFixture('anthropic_text');

        $r = $this->llm()->complete(LlmRequest::prompt('hi'));

        self::assertSame('Hello there.', $r->text());
        self::assertSame('https://api.anthropic.com/v1/messages', $this->http->url());
        self::assertSame(Make::ANTHROPIC_KEY, $this->http->headers()['x-api-key']);
    }

    public function test_the_provider_can_be_chosen_per_call(): void
    {
        $this->http->queueFixture('openai_text');

        $r = $this->llm()->complete(LlmRequest::prompt('hi'), 'openai');

        self::assertSame('openai', $r->provider);
        self::assertSame('https://api.openai.com/v1/chat/completions', $this->http->url());
    }

    public function test_an_unconfigured_provider_throws_naming_the_variables(): void
    {
        $this->expectExceptionMessage('LLM_GEMINI_API_KEY, LLM_GEMINI_MODEL');
        $this->llm()->complete(LlmRequest::prompt('hi'), 'gemini');
    }

    public function test_the_tool_loop_runs_with_the_identity_the_kernel_resolved_for_the_request(): void
    {
        $this->http->queueFixture('openai_tool_calls')->queueFixture('openai_text');
        $seen = [];

        $box = (new ToolBox(new CallbackAuthorizer(static fn ($call, $tool, Identity $who): bool => $who->userId === 'alice' && $call->name === 'get_invoice')))
            ->register(Make::invoiceTool(), function (array $a, Identity $who) use (&$seen): array {
                $seen[] = [$a, $who->userId, $who->tenantId];

                return ['total' => 100];
            })
            ->register(Make::pingTool(), static fn (): string => 'pong');

        $run = $this->llm()->run(LlmRequest::prompt('Invoice 7?'), $box, 'openai');

        self::assertTrue($run->finished);
        self::assertSame([[['id' => 'inv-7'], 'alice', 'tenant-a']], $seen, 'get_invoice ran for alice; ping was refused by policy');
        self::assertSame('refused', $run->steps[0]->executions[1]->outcome->value);
        // the second request carries both results back
        $second = $this->http->calls[1]['options']['json']['messages'];
        self::assertSame('tool', $second[2]['role']);
        self::assertSame('tool', $second[3]['role']);
        self::assertSame('This tool call was not permitted.', $second[3]['content']);
    }

    public function test_providers_lists_what_is_configured_without_a_key(): void
    {
        $names = array_map(static fn ($p) => $p->name, $this->llm()->providers());

        self::assertSame(['anthropic', 'openai', 'ollama'], $names);
        self::assertStringNotContainsString('TESTKEY', (string) json_encode($this->llm()->providers()));
    }

    public function test_the_registry_is_internal_to_the_module(): void
    {
        $this->expectException(ScopeViolationException::class);
        $this->g?->container()->make(GatewayRegistry::class);
    }

    public function test_the_command_is_registered_through_the_real_cli_pipeline_and_runs(): void
    {
        self::assertTrue($this->g?->hasCommand('llm:providers'), 'a command that cannot be built from the core is silently absent from `list`');

        // Table::render() writes straight to STDOUT, so capture that too.
        ob_start();
        $result = $this->g->cli('llm:providers');
        $output = (string) preg_replace('/\e\[[0-9;]*m/', '', ob_get_clean() . $result->output());

        self::assertSame(0, $result->exitCode);
        self::assertStringContainsString('claude-sonnet-5-5', $output);
        self::assertStringContainsString('openai', $output);
        self::assertStringNotContainsString('TESTKEY', $output);
        self::assertStringNotContainsString('sk-live', $output);
    }

    public function test_the_manifest_declares_no_routes_and_requires_the_http_client(): void
    {
        $m = json_decode((string) file_get_contents(\dirname(__DIR__, 2) . '/module.json'), true);

        self::assertSame([], $m['routes']);
        self::assertSame(['http.client'], $m['requires']);
        self::assertSame([], $this->g?->routes());
        self::assertSame($m['exposes'], [LlmContract::class]);
    }
}
