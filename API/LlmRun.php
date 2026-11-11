<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

/**
 * The outcome of {@see Contracts\LlmContract::run()}.
 *
 * `finished` is false when the step limit was reached while the model still
 * wanted tools run. Those last calls were NOT executed — a limit that ran one
 * more round "to be tidy" would be a limit that did not hold — so the final
 * response has tool calls and no usable answer. `response()` throws in that
 * case rather than hand back text that looks complete.
 */
final readonly class LlmRun
{
    /**
     * @param list<Step>    $steps
     * @param list<Message> $transcript every message produced by the run, in order
     */
    public function __construct(
        public array $steps,
        public array $transcript,
        public bool $finished,
        public Usage $usage,
    ) {}

    /** @throws \LogicException when the run stopped at its step limit */
    public function response(): LlmResponse
    {
        if (!$this->finished) {
            throw new \LogicException('The run stopped at its step limit with tool calls pending; there is no final answer.');
        }

        return $this->steps[\count($this->steps) - 1]->response;
    }

    public function text(): string
    {
        return $this->response()->text();
    }

    /** The validated structured output of the final response, when a schema was requested. */
    public function output(): mixed
    {
        return $this->response()->output;
    }
}
