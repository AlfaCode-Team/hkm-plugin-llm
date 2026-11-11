<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

/**
 * What the model is TOLD about a tool: a name, a description and the shape of
 * its arguments. It carries no code. The code that runs, and the decision
 * whether it may, live in {@see Tools\ToolBox} — so a Tool can be put in a
 * request for a single model call without anything having been made runnable.
 */
final readonly class Tool
{
    public function __construct(
        public string $name,
        public string $description,
        public Schema $input,
    ) {
        // The intersection of what the four providers accept.
        if (preg_match('/\A[A-Za-z][A-Za-z0-9_-]{0,63}\z/', $name) !== 1) {
            throw new \InvalidArgumentException(
                'A tool name must start with a letter and be 1-64 characters of letters, digits, _ or -.',
            );
        }
        if ($description === '') {
            throw new \InvalidArgumentException("Tool [{$name}] needs a description: the model chooses it by that text.");
        }
        if (!$input->isObject()) {
            throw new \InvalidArgumentException("Tool [{$name}] takes an object: its input schema must have type 'object'.");
        }
    }
}
