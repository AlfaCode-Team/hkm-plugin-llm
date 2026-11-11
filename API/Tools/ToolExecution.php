<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Tools;

use Plugins\Llm\API\Parts\ToolCallPart;
use Plugins\Llm\API\Parts\ToolResultPart;

/**
 * What happened to one tool call.
 *
 * `reason` is for the application's logs and audit trail. It never reaches the
 * model: a refusal that explains the policy ("denied: user lacks invoice:read")
 * is a map of the policy handed to whatever is steering the model.
 */
final readonly class ToolExecution
{
    public function __construct(
        public ToolCallPart $call,
        public ToolOutcome $outcome,
        public ToolResultPart $result,
        public string $reason = '',
    ) {}
}
