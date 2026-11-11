<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

final readonly class Usage
{
    public function __construct(
        public int $input = 0,
        public int $output = 0,
        public int $cacheRead = 0,
        public int $reasoning = 0,
    ) {}

    public function total(): int
    {
        return $this->input + $this->output;
    }

    public function add(self $other): self
    {
        return new self(
            $this->input + $other->input,
            $this->output + $other->output,
            $this->cacheRead + $other->cacheRead,
            $this->reasoning + $other->reasoning,
        );
    }
}
