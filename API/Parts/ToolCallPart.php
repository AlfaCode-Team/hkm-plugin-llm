<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Parts;

/**
 * The model asking for a tool to be run.
 *
 * EVERY field here is model output and therefore untrusted input: the name
 * may not exist, the arguments may not match the schema, and both may have been
 * influenced by text an attacker placed in the conversation. Nothing runs
 * because this exists; see {@see \Plugins\Llm\API\Tools\ToolBox}.
 */
final readonly class ToolCallPart implements Part
{
    /** @param array<string, mixed> $arguments */
    public function __construct(public string $id, public string $name, public array $arguments = []) {}
}
