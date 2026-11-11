<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

use Plugins\Llm\API\Parts\ToolCallPart;

/**
 * What ONE model call returned.
 *
 * `output` is meaningful only when `structuredMode` is not null — a schema was
 * requested, and the value has been validated against it. `null` is a valid
 * output (`{"type": "null"}`), which is why presence is carried by the mode and
 * not by `output !== null`.
 */
final readonly class LlmResponse
{
    public function __construct(
        public string $provider,
        public string $model,
        public Message $message,
        public FinishReason $finish,
        public Usage $usage = new Usage(),
        public ?StructuredMode $structuredMode = null,
        public mixed $output = null,
    ) {}

    public function text(): string
    {
        return $this->message->text();
    }

    /** @return list<ToolCallPart> */
    public function toolCalls(): array
    {
        return $this->message->toolCalls();
    }

    public function withOutput(StructuredMode $mode, mixed $output): self
    {
        return new self($this->provider, $this->model, $this->message, $this->finish, $this->usage, $mode, $output);
    }
}
