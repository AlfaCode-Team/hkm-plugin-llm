<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Tools;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;

/**
 * The code behind a tool. Runs only after the arguments validated against the
 * tool's schema AND the authorizer allowed the call.
 *
 * Take the tenant and the user from `$identity`, never from `$arguments`: the
 * arguments are model output. A tool whose schema has a `tenant_id` or
 * `user_id` property is a tool the model can point at someone else's data, and
 * an authorizer is the last thing standing between that and a leak.
 *
 * Return a string, or anything `json_encode()` accepts.
 */
interface ToolHandler
{
    /** @param array<string, mixed> $arguments */
    public function handle(array $arguments, Identity $identity): mixed;
}
