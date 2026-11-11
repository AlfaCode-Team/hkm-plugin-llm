<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Tools;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Llm\API\Parts\ToolCallPart;
use Plugins\Llm\API\Tool;

/**
 * An authorizer written as a closure:
 *
 *     new CallbackAuthorizer(fn (ToolCallPart $c, Tool $t, Identity $i): bool
 *         => $i->hasPermission("llm-tool:{$t->name}"));
 *
 * A closure returning anything other than `true` or an allowed ToolDecision is
 * a refusal — `1`, `'yes'` and `null` do not pass.
 */
final readonly class CallbackAuthorizer implements ToolAuthorizer
{
    /** @param \Closure(ToolCallPart, Tool, Identity): (bool|ToolDecision) $decide */
    public function __construct(private \Closure $decide) {}

    public function authorize(ToolCallPart $call, Tool $tool, Identity $identity): ToolDecision
    {
        $answer = ($this->decide)($call, $tool, $identity);

        if ($answer instanceof ToolDecision) {
            return $answer;
        }

        return $answer === true ? ToolDecision::allow() : ToolDecision::deny('callback did not return true');
    }
}
