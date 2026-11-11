<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Unit;

use AlfacodeTeam\Ground\Fakes\FakeLogger;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\TestCase;
use Plugins\Llm\API\FinishReason;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\LlmResponse;
use Plugins\Llm\API\Message;
use Plugins\Llm\API\Role;
use Plugins\Llm\API\StructuredFallback;
use Plugins\Llm\API\StructuredMode;
use Plugins\Llm\API\ToolChoice;
use Plugins\Llm\API\Tools\CallbackAuthorizer;
use Plugins\Llm\API\Tools\ToolBox;
use Plugins\Llm\API\Tools\ToolOutcome;
use Plugins\Llm\Application\Exceptions\LlmException;
use Plugins\Llm\Application\Services\GatewayRegistry;
use Plugins\Llm\Application\Services\LlmService;
use Plugins\Llm\Infrastructure\Gateways\ScriptedGateway;
use Tests\Plugins\Llm\Support\Make;

final class LlmServiceTest extends TestCase
{
    private ScriptedGateway $gw;
    private FakeLogger $log;

    private function service(?ScriptedGateway $gateway = null): LlmService
    {
        $this->gw = $gateway ?? new ScriptedGateway();
        $gw = $this->gw;
        $this->log = new FakeLogger();

        return new LlmService(
            new GatewayRegistry('scripted', ['scripted' => static fn () => $gw], ['openai' => ['LLM_OPENAI_API_KEY']]),
            Identity::asUser('alice', 'tenant-a'),
            $this->log,
        );
    }

    // ── structured output ───────────────────────────────────────────────────

    public function test_native_output_is_decoded_validated_and_labelled(): void
    {
        $svc = $this->service();
        $this->gw->then('{"name":"Ada","age":36}');

        $r = $svc->complete(LlmRequest::prompt('who?')->withSchema(Make::personSchema()));

        self::assertSame(['name' => 'Ada', 'age' => 36], $r->output);
        self::assertSame(StructuredMode::Native, $r->structuredMode);
        self::assertNotNull($this->gw->requests[0]->schema, 'native: the schema goes to the provider');
    }

    public function test_a_provider_that_cannot_honour_the_schema_refuses_by_default_and_sends_nothing(): void
    {
        $svc = $this->service(new ScriptedGateway(cannotConstrain: 'no constrained decoding on this model'));
        // Scripted so that, were the refusal missing, the call SUCCEEDS and fail() below fires
        // — rather than the test dying on "no response scripted", which would pass for the wrong reason.
        $this->gw->then('{"name":"Ada","age":36}');

        try {
            $svc->complete(LlmRequest::prompt('who?')->withSchema(Make::personSchema()));
            self::fail('a schema the provider cannot honour must not degrade silently');
        } catch (LlmException $e) {
            self::assertStringContainsString('cannot constrain its output', $e->getMessage());
            self::assertStringContainsString('no constrained decoding on this model', $e->getMessage());
            self::assertStringContainsString('PromptAndValidate', $e->getMessage());
        }
        self::assertSame([], $this->gw->requests, 'nothing may be sent to a provider that was going to be refused');
    }

    public function test_the_prompt_fallback_must_be_requested_and_is_visible_in_the_result(): void
    {
        $svc = $this->service(new ScriptedGateway(cannotConstrain: 'no'));
        $this->gw->then('{"name":"Ada","age":36}');

        $r = $svc->complete(LlmRequest::prompt('who?', 'Be brief.')->withSchema(Make::personSchema(), StructuredFallback::PromptAndValidate));

        self::assertSame(StructuredMode::Prompted, $r->structuredMode);
        self::assertSame(['name' => 'Ada', 'age' => 36], $r->output);

        $sent = $this->gw->requests[0];
        self::assertNull($sent->schema, 'prompted: the provider is not handed a schema it cannot honour');
        self::assertSame(Role::System, $sent->messages[0]->role);
        self::assertStringContainsString('"required":["name","age"]', $sent->system());
        self::assertStringContainsString('Be brief.', $sent->system(), 'the caller\'s own system prompt survives');
    }

    public function test_prompted_output_is_validated_too_and_a_code_fence_is_tolerated_only_there(): void
    {
        $svc = $this->service(new ScriptedGateway(cannotConstrain: 'no'));
        $this->gw->then("```json\n{\"name\":\"Ada\",\"age\":36}\n```")->then('{"name":"Ada"}');

        $fallback = LlmRequest::prompt('who?')->withSchema(Make::personSchema(), StructuredFallback::PromptAndValidate);
        self::assertSame(['name' => 'Ada', 'age' => 36], $svc->complete($fallback)->output);

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('$.age: required but missing');
        $svc->complete($fallback);
    }

    public function test_native_output_that_does_not_match_is_an_exception_not_a_value(): void
    {
        $svc = $this->service();
        $this->gw->then('{"name":"Ada","age":"thirty"}');

        try {
            $svc->complete(LlmRequest::prompt('who?')->withSchema(Make::personSchema()));
            self::fail('invalid structured output must never be returned');
        } catch (LlmException $e) {
            self::assertStringContainsString('does not match the schema', $e->getMessage());
            self::assertSame(['$.age: expected integer, got string'], $e->violations);
            self::assertSame('{"name":"Ada","age":"thirty"}', $e->rawOutput);
            self::assertStringNotContainsString('thirty', $e->getMessage() . json_encode($e->context), 'model text stays out of the message');
        }
    }

    public function test_a_code_fence_is_not_tolerated_in_native_mode(): void
    {
        $svc = $this->service();
        $this->gw->then("```json\n{\"name\":\"Ada\",\"age\":36}\n```");

        $this->expectException(LlmException::class);
        $this->expectExceptionMessage('not valid JSON');
        $svc->complete(LlmRequest::prompt('who?')->withSchema(Make::personSchema()));
    }

    public function test_truncated_and_declined_answers_are_not_parsed(): void
    {
        $svc = $this->service();
        $this->gw->then('{"name":"Ad', FinishReason::Length)->then('', FinishReason::ContentFilter);
        $request = LlmRequest::prompt('who?')->withSchema(Make::personSchema());

        foreach (['cut off by the token limit', 'declined to answer'] as $why) {
            try {
                $svc->complete($request);
                self::fail($why);
            } catch (LlmException $e) {
                self::assertStringContainsString($why, $e->getMessage());
            }
        }
    }

    public function test_a_response_that_wants_tools_run_has_no_output_to_check_yet(): void
    {
        $svc = $this->service();
        $this->gw->thenCall('ping');

        $r = $svc->complete(LlmRequest::prompt('x')->withSchema(Make::personSchema())->withTools([Make::pingTool()]));

        self::assertNull($r->structuredMode);
        self::assertCount(1, $r->toolCalls());
    }

    public function test_json_null_is_a_valid_output_and_presence_is_carried_by_the_mode(): void
    {
        $svc = $this->service();
        $this->gw->then('null');

        $r = $svc->complete(LlmRequest::prompt('x')->withSchema(new \Plugins\Llm\API\Schema('n', ['type' => 'null'])));

        self::assertNull($r->output);
        self::assertSame(StructuredMode::Native, $r->structuredMode);
    }

    public function test_no_schema_means_no_structured_mode(): void
    {
        $svc = $this->service();
        $this->gw->then('{"name":"Ada"}');

        $r = $svc->complete(LlmRequest::prompt('x'));

        self::assertNull($r->structuredMode);
        self::assertSame('{"name":"Ada"}', $r->text());
    }

    // ── providers ───────────────────────────────────────────────────────────

    public function test_an_unconfigured_provider_names_the_variables_and_an_unknown_one_the_choices(): void
    {
        $svc = $this->service();

        try {
            $svc->complete(LlmRequest::prompt('x'), 'openai');
            self::fail();
        } catch (LlmException $e) {
            self::assertStringContainsString('LLM_OPENAI_API_KEY', $e->getMessage());
        }
        try {
            $svc->complete(LlmRequest::prompt('x'), 'mistral');
            self::fail();
        } catch (LlmException $e) {
            self::assertStringContainsString('Known: scripted, openai', $e->getMessage());
        }
    }

    // ── the tool loop ───────────────────────────────────────────────────────

    private function box(\Closure $authorize, array &$ran): ToolBox
    {
        return (new ToolBox(new CallbackAuthorizer($authorize)))
            ->register(Make::invoiceTool(), function (array $args, Identity $who) use (&$ran): array {
                $ran[] = [$args, $who->userId, $who->tenantId];

                return ['total' => 100];
            });
    }

    public function test_the_loop_runs_authorized_calls_feeds_results_back_and_stops_on_an_answer(): void
    {
        $svc = $this->service();
        $this->gw->thenCall('get_invoice', ['id' => 'inv-7'])->then('Invoice 7 is 100.');
        $ran = [];

        $run = $svc->run(LlmRequest::prompt('Invoice 7?'), $this->box(static fn (): bool => true, $ran));

        self::assertTrue($run->finished);
        self::assertSame('Invoice 7 is 100.', $run->text());
        self::assertSame([[['id' => 'inv-7'], 'alice', 'tenant-a']], $ran, 'the handler gets the SERVICE\'s identity, not one the model chose');
        self::assertCount(2, $run->steps);
        self::assertSame(ToolOutcome::Executed, $run->steps[0]->executions[0]->outcome);

        // The second request carries the model's tool call and its result.
        $second = $this->gw->requests[1]->messages;
        self::assertSame(Role::Assistant, $second[1]->role);
        self::assertSame(Role::Tool, $second[2]->role);
        self::assertSame('{"total":100}', $second[2]->parts[0]->content);
        self::assertSame(4, $run->usage->total(), 'usage is summed across steps');
    }

    public function test_a_refused_call_is_not_run_the_model_sees_a_generic_refusal_and_the_attempt_is_logged(): void
    {
        $svc = $this->service();
        $this->gw->thenCall('get_invoice', ['id' => 'inv-OTHER'])->then('Sorry, I could not.');
        $ran = [];

        $run = $svc->run(LlmRequest::prompt('Invoice?'), $this->box(static fn (): bool => false, $ran));

        self::assertSame([], $ran);
        self::assertSame(ToolOutcome::Refused, $run->steps[0]->executions[0]->outcome);
        $toModel = $this->gw->requests[1]->messages[2]->parts[0];
        self::assertTrue($toModel->isError);
        self::assertSame('This tool call was not permitted.', $toModel->content);

        self::assertTrue($this->log->logged('warning', 'llm.tool.refused'));
        $record = json_encode($this->log->at('warning'));
        self::assertStringContainsString('alice', (string) $record);
        self::assertStringNotContainsString('inv-OTHER', (string) $record, 'arguments are model output and are not logged');
    }

    public function test_what_the_model_is_told_about_and_what_can_run_are_one_list(): void
    {
        $svc = $this->service();
        $this->gw->then('done');
        $ran = [];

        // A tool smuggled into the request is replaced by the ToolBox's definitions.
        $svc->run(LlmRequest::prompt('x')->withTools([Make::pingTool()]), $this->box(static fn (): bool => true, $ran));

        self::assertSame(['get_invoice'], array_map(static fn ($t) => $t->name, $this->gw->requests[0]->tools));
    }

    public function test_the_step_limit_holds_and_the_last_calls_are_not_executed(): void
    {
        $svc = $this->service();
        $this->gw->thenCall('get_invoice', ['id' => 'a'], 'c1')->thenCall('get_invoice', ['id' => 'b'], 'c2')->thenCall('get_invoice', ['id' => 'c'], 'c3');
        $ran = [];

        $run = $svc->run(LlmRequest::prompt('x'), $this->box(static fn (): bool => true, $ran), maxSteps: 2);

        self::assertFalse($run->finished);
        // maxSteps counts MODEL CALLS: call 1's tools ran, call 2's did not.
        self::assertCount(1, $ran, 'a limit that ran one more round to be tidy would not be a limit');
        self::assertCount(2, $this->gw->requests);
        $this->expectException(\LogicException::class);
        $run->response();
    }

    public function test_a_forced_tool_choice_applies_to_the_first_call_only(): void
    {
        $svc = $this->service();
        $this->gw->thenCall('get_invoice', ['id' => 'a'])->then('done');
        $ran = [];

        $svc->run(
            new LlmRequest([Message::user('x')], [Make::invoiceTool()], ToolChoice::tool('get_invoice')),
            $this->box(static fn (): bool => true, $ran),
        );

        self::assertSame('tool', $this->gw->requests[0]->toolChoice?->type);
        self::assertNull($this->gw->requests[1]->toolChoice, 'forced on every step the loop could only end at the limit');
    }

    public function test_a_tool_call_cut_off_by_the_token_limit_is_not_executed(): void
    {
        $svc = $this->service();
        $this->gw->then([new \Plugins\Llm\API\Parts\ToolCallPart('c1', 'get_invoice', ['id' => 'inv-7'])], FinishReason::Length)->then('done');
        $ran = [];

        try {
            $svc->run(LlmRequest::prompt('x'), $this->box(static fn (): bool => true, $ran));
            self::fail();
        } catch (LlmException $e) {
            self::assertStringContainsString('cut off', $e->getMessage());
        }
        self::assertSame([], $ran);
    }

    public function test_structured_output_is_validated_on_the_final_answer_of_a_run(): void
    {
        $svc = $this->service();
        $this->gw->thenCall('get_invoice', ['id' => 'a'])->then('{"name":"Ada","age":36}');
        $ran = [];

        $run = $svc->run(LlmRequest::prompt('x')->withSchema(Make::personSchema()), $this->box(static fn (): bool => true, $ran));

        self::assertSame(['name' => 'Ada', 'age' => 36], $run->output());
        self::assertSame(StructuredMode::Native, $run->response()->structuredMode);
    }

    public function test_max_steps_is_bounded(): void
    {
        $svc = $this->service();
        $ran = [];

        foreach ([0, 51, -1] as $bad) {
            try {
                $svc->run(LlmRequest::prompt('x'), $this->box(static fn (): bool => true, $ran), maxSteps: $bad);
                self::fail((string) $bad);
            } catch (LlmException $e) {
                self::assertStringContainsString('maxSteps', $e->getMessage());
            }
        }
    }

    public function test_complete_never_runs_a_tool_it_returns_the_calls(): void
    {
        $svc = $this->service();
        $this->gw->thenCall('get_invoice', ['id' => 'a']);

        $r = $svc->complete(LlmRequest::prompt('x')->withTools([Make::invoiceTool()]));

        self::assertSame(FinishReason::ToolCalls, $r->finish);
        self::assertSame('get_invoice', $r->toolCalls()[0]->name);
    }

    public function test_providers_lists_only_configured_ones_without_a_credential(): void
    {
        $info = $this->service()->providers();

        self::assertSame(['scripted'], array_map(static fn ($p) => $p->name, $info));
    }

    public function test_the_service_logs_usage_never_content(): void
    {
        $svc = $this->service();
        $this->gw->then('the secret answer is 42');

        $svc->complete(LlmRequest::prompt('my password is hunter2'));

        $logged = (string) json_encode($this->log->at('debug'));
        self::assertStringContainsString('llm.completed', $logged);
        self::assertStringNotContainsString('hunter2', $logged);
        self::assertStringNotContainsString('42', str_replace('"output_tokens":1', '', $logged));
    }
}
