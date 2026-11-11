<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Parts;

/**
 * Thinking the model did before answering.
 *
 * It exists because a provider that reasons and calls tools in the same turn
 * requires its own reasoning block back, byte for byte, on the next request —
 * Anthropic signs it. `opaque` is that block, carried untouched and never
 * interpreted. A consumer displays `text`; it must not edit either field.
 */
final readonly class ReasoningPart implements Part
{
    /** @param array<string, mixed> $opaque provider-specific, to be echoed back verbatim */
    public function __construct(public string $text, public array $opaque = []) {}
}
