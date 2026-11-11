<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Tools;

/**
 * An authorizer's answer. Only `allow()` lets a call run; there is no
 * "maybe", and anything that is not an allowed decision is a refusal.
 */
final readonly class ToolDecision
{
    private function __construct(public bool $allowed, public string $reason) {}

    public static function allow(): self
    {
        return new self(true, '');
    }

    /** @param string $reason for the application's logs; it is NEVER shown to the model */
    public static function deny(string $reason = 'denied'): self
    {
        return new self(false, $reason);
    }
}
