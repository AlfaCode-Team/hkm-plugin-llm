<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

/**
 * How hard a model is asked to think — explicit, and `null` on a request means
 * "the provider's own default", which is a different statement from `Medium`.
 *
 * There is deliberately no `None`. Whether "no reasoning" exists, and what it
 * is called, differs per model generation; a value that some providers would
 * have to silently translate into "omit the field" would read as honoured and
 * not be. A gateway that cannot honour an effort REFUSES it.
 */
enum Effort: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    /** Reasoning-token budget, for providers that take a number rather than a word. */
    public function budget(): int
    {
        return match ($this) {
            self::Low => 1024,
            self::Medium => 8192,
            self::High => 24576,
        };
    }
}
