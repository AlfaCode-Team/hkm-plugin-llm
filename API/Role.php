<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

/**
 * Who a message is from.
 *
 * `Tool` is its own role rather than a `User` message carrying results,
 * because that is what the providers disagree about: Anthropic puts a tool
 * result in a user turn, OpenAI gives it a `tool` role, Gemini a function
 * turn. The neutral form keeps the distinction and each gateway maps it.
 */
enum Role: string
{
    case System = 'system';
    case User = 'user';
    case Assistant = 'assistant';
    case Tool = 'tool';
}
