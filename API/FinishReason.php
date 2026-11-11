<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

enum FinishReason: string
{
    /** The model finished its answer. */
    case Stop = 'stop';
    /** Cut off by the token limit — the text may be incomplete, and structured output usually is. */
    case Length = 'length';
    /** The model wants tools run; nothing has been run yet. */
    case ToolCalls = 'tool-calls';
    /** The provider declined to answer. */
    case ContentFilter = 'content-filter';
    case Other = 'other';
}
