<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

use Plugins\Llm\API\Tools\ToolExecution;

/** One pass of the tool loop: a model call, and what was done about the calls it made. */
final readonly class Step
{
    /** @param list<ToolExecution> $executions one per tool call in the response, in order */
    public function __construct(public LlmResponse $response, public array $executions = []) {}
}
