<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Unit;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use PHPUnit\Framework\TestCase;
use Plugins\Llm\API\Parts\ToolCallPart;
use Plugins\Llm\API\Tools\CallbackAuthorizer;
use Plugins\Llm\API\Tools\ToolAuthorizer;
use Plugins\Llm\API\Tools\ToolBox;
use Plugins\Llm\API\Tools\ToolDecision;
use Plugins\Llm\API\Tools\ToolHandler;
use Plugins\Llm\API\Tools\ToolOutcome;
use Tests\Plugins\Llm\Support\Make;

/**
 * The security boundary: a call the MODEL chose does not run unless the
 * APPLICATION allows it, for the CALLER's identity.
 */
final class ToolBoxTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $ran = [];

    private function box(bool|\Closure $decide, int $max = 65536): ToolBox
    {
        $authorizer = new CallbackAuthorizer($decide instanceof \Closure ? $decide : static fn (): bool => $decide);

        return (new ToolBox($authorizer, $max))
            ->register(Make::invoiceTool(), function (array $args, Identity $who): array {
                $this->ran[] = ['args' => $args, 'user' => $who->userId, 'tenant' => $who->tenantId];

                return ['total' => 100];
            })
            ->register(Make::pingTool(), static fn (): string => 'pong');
    }

    private function call(string $name = 'get_invoice', array $args = ['id' => 'inv-7']): ToolCallPart
    {
        return new ToolCallPart('c1', $name, $args);
    }

    private function who(): Identity
    {
        return Identity::asUser('alice', 'tenant-a');
    }

    public function test_an_authorized_call_runs_with_the_callers_identity_and_returns_json(): void
    {
        $e = $this->box(true)->execute($this->call(), $this->who());

        self::assertSame(ToolOutcome::Executed, $e->outcome);
        self::assertSame('{"total":100}', $e->result->content);
        self::assertFalse($e->result->isError);
        self::assertSame('c1', $e->result->callId);
        self::assertSame([['args' => ['id' => 'inv-7'], 'user' => 'alice', 'tenant' => 'tenant-a']], $this->ran);
    }

    public function test_a_tool_call_is_refused_without_authorization_and_the_handler_never_runs(): void
    {
        $e = $this->box(false)->execute($this->call(), $this->who());

        self::assertSame(ToolOutcome::Refused, $e->outcome);
        self::assertTrue($e->result->isError);
        self::assertSame([], $this->ran, 'the handler must be unreachable without an allow');
    }

    public function test_the_model_is_told_nothing_about_why(): void
    {
        $box = $this->box(static fn (): ToolDecision => ToolDecision::deny('user lacks invoice:read on tenant-b'));
        $e = $box->execute($this->call(), $this->who());

        self::assertSame('This tool call was not permitted.', $e->result->content);
        self::assertStringNotContainsString('invoice:read', $e->result->content);
        self::assertSame('user lacks invoice:read on tenant-b', $e->reason, 'the reason is for the application');
    }

    public function test_the_authorizer_sees_the_call_the_tool_and_the_identity(): void
    {
        $seen = null;
        $this->box(function ($call, $tool, $who) use (&$seen): bool {
            $seen = [$call->name, $call->arguments, $tool->name, $who->userId];

            return true;
        })->execute($this->call(), $this->who());

        self::assertSame(['get_invoice', ['id' => 'inv-7'], 'get_invoice', 'alice'], $seen);
    }

    public function test_the_authorizer_can_decide_by_arguments(): void
    {
        $box = $this->box(static fn ($call): bool => ($call->arguments['id'] ?? '') === 'inv-7');

        self::assertSame(ToolOutcome::Executed, $box->execute($this->call(args: ['id' => 'inv-7']), $this->who())->outcome);
        self::assertSame(ToolOutcome::Refused, $box->execute($this->call(args: ['id' => 'inv-OTHER-TENANT']), $this->who())->outcome);
    }

    public function test_an_authorizer_that_throws_is_a_refusal(): void
    {
        $e = $this->box(static fn () => throw new \RuntimeException('policy store down'))->execute($this->call(), $this->who());

        self::assertSame(ToolOutcome::Refused, $e->outcome);
        self::assertSame([], $this->ran);
        self::assertStringNotContainsString('policy store', $e->result->content);
    }

    public function test_anything_but_true_from_a_callback_is_a_refusal(): void
    {
        foreach ([1, 'yes', 'true', null, [], 0, 'allow'] as $answer) {
            $e = $this->box(static fn () => $answer)->execute($this->call(), $this->who());
            self::assertSame(ToolOutcome::Refused, $e->outcome, var_export($answer, true));
        }
        self::assertSame([], $this->ran);
    }

    public function test_a_custom_authorizer_returning_a_denied_decision_is_a_refusal(): void
    {
        $box = (new ToolBox(new class implements ToolAuthorizer {
            public function authorize(ToolCallPart $call, \Plugins\Llm\API\Tool $tool, Identity $identity): ToolDecision
            {
                return ToolDecision::deny();
            }
        }))->register(Make::pingTool(), static fn (): string => 'pong');

        self::assertSame(ToolOutcome::Refused, $box->execute($this->call('ping', []), $this->who())->outcome);
    }

    public function test_an_authorizer_is_required_and_has_no_default(): void
    {
        $param = (new \ReflectionMethod(ToolBox::class, '__construct'))->getParameters()[0];

        self::assertSame(ToolAuthorizer::class, (string) $param->getType());
        self::assertFalse($param->isOptional(), 'a ToolBox without an authorizer must not be constructible');
        self::assertFalse($param->allowsNull());
    }

    public function test_no_allow_everything_authorizer_ships(): void
    {
        $shipped = array_map('basename', glob(\dirname(__DIR__, 2) . '/API/Tools/*.php') ?: []);

        foreach ($shipped as $file) {
            self::assertDoesNotMatchRegularExpression('/AllowAll|Permissive|Unsafe/i', $file);
        }
    }

    public function test_an_unknown_tool_runs_nothing(): void
    {
        $e = $this->box(true)->execute($this->call('drop_all_tables', []), $this->who());

        self::assertSame(ToolOutcome::Unknown, $e->outcome);
        self::assertSame([], $this->ran);
    }

    public function test_invalid_arguments_stop_before_the_authorizer_and_the_handler(): void
    {
        $asked = false;
        $box = $this->box(function () use (&$asked): bool {
            $asked = true;

            return true;
        });

        foreach ([['id' => 7], [], ['id' => 'x', 'tenant_id' => 'other']] as $args) {
            $e = $box->execute($this->call(args: $args), $this->who());
            self::assertSame(ToolOutcome::Invalid, $e->outcome, json_encode($args));
            self::assertStringStartsWith('Invalid arguments:', $e->result->content);
        }
        self::assertFalse($asked, 'the authorizer must only ever see well-formed arguments');
        self::assertSame([], $this->ran);
    }

    public function test_a_handler_exception_becomes_a_generic_failure_and_is_not_forwarded(): void
    {
        $box = (new ToolBox(new CallbackAuthorizer(static fn (): bool => true)))
            ->register(Make::pingTool(), static fn () => throw new \PDOException('SQLSTATE[HY000] password=hunter2'));

        $e = $box->execute($this->call('ping', []), $this->who());

        self::assertSame(ToolOutcome::Failed, $e->outcome);
        self::assertSame('The tool failed.', $e->result->content);
        self::assertTrue($e->result->isError);
        self::assertSame(\PDOException::class, $e->reason);
        self::assertStringNotContainsString('hunter2', $e->result->content . $e->reason);
    }

    public function test_a_result_that_cannot_be_encoded_is_a_failure(): void
    {
        $box = (new ToolBox(new CallbackAuthorizer(static fn (): bool => true)))
            ->register(Make::pingTool(), static fn (): array => ['bad' => "\xB1\x31"]);

        self::assertSame(ToolOutcome::Failed, $box->execute($this->call('ping', []), $this->who())->outcome);
    }

    public function test_a_handler_object_works_and_a_large_result_is_truncated_on_a_character_boundary(): void
    {
        $handler = new class implements ToolHandler {
            public function handle(array $arguments, Identity $identity): mixed
            {
                return str_repeat('é', 1000);
            }
        };
        $box = (new ToolBox(new CallbackAuthorizer(static fn (): bool => true), 256))->register(Make::pingTool(), $handler);

        $content = $box->execute($this->call('ping', []), $this->who())->result->content;

        self::assertStringEndsWith(' [truncated]', $content);
        self::assertLessThanOrEqual(256 + 12, \strlen($content));
        self::assertTrue(mb_check_encoding($content, 'UTF-8'));
    }

    public function test_a_handler_object_receives_the_callers_identity_not_one_from_the_arguments(): void
    {
        $received = [];
        $handler = new class ($received) implements ToolHandler {
            public function __construct(private array &$received) {}

            public function handle(array $arguments, Identity $identity): mixed
            {
                $this->received[] = [$identity->userId, $identity->tenantId, $arguments];

                return 'ok';
            }
        };
        $box = (new ToolBox(new CallbackAuthorizer(static fn (): bool => true)))->register(Make::invoiceTool(), $handler);

        // The model tries to name someone else; the schema (closed) refuses the extra key,
        // and the identity the handler sees is the caller's regardless.
        self::assertSame(ToolOutcome::Invalid, $box->execute($this->call(args: ['id' => 'x', 'user_id' => 'admin']), $this->who())->outcome);
        $box->execute($this->call(args: ['id' => 'x']), $this->who());

        self::assertSame([['alice', 'tenant-a', ['id' => 'x']]], $received);
    }

    public function test_definitions_are_what_the_model_is_told_and_names_cannot_repeat(): void
    {
        $box = $this->box(true);

        self::assertSame(['get_invoice', 'ping'], array_map(static fn ($t): string => $t->name, $box->definitions()));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already registered');
        $box->register(Make::pingTool(), static fn (): string => 'again');
    }
}
