<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Tools;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Llm\API\Parts\ToolCallPart;
use Plugins\Llm\API\Tool;

/**
 * THE decision about whether a call the MODEL chose may run, made by the
 * APPLICATION, for the CALLER's identity.
 *
 * A tool is application code reached from model output, and model output is
 * steered by everything in the context window — including text the user did
 * not write (a fetched web page, a document, a database row, an earlier tool
 * result). "The model decided to call it" is therefore not a reason to run it.
 * The authorizer is where "should this user's request be able to do this, with
 * these arguments" is answered, in the same terms any other write path in the
 * application would answer it.
 *
 * Required by {@see ToolBox}'s constructor and with no default, so a tool
 * cannot be made runnable without someone having decided. This plugin ships no
 * allow-everything implementation, on purpose.
 *
 * Implementations must be FAIL-CLOSED: an exception from here is treated as a
 * refusal, and so is a return value that is not an allowed {@see ToolDecision}.
 */
interface ToolAuthorizer
{
    public function authorize(ToolCallPart $call, Tool $tool, Identity $identity): ToolDecision;
}
