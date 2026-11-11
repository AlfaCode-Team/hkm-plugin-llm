<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Tools;

enum ToolOutcome: string
{
    /** Authorized, ran, returned. */
    case Executed = 'executed';
    /** The authorizer said no (or failed to say yes). The handler never ran. */
    case Refused = 'refused';
    /** The arguments did not match the tool's schema. The authorizer and handler never ran. */
    case Invalid = 'invalid';
    /** The model named a tool that is not registered. */
    case Unknown = 'unknown';
    /** Authorized, ran, and threw. */
    case Failed = 'failed';
}
