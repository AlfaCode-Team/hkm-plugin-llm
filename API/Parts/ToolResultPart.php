<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Parts;

final readonly class ToolResultPart implements Part
{
    public function __construct(
        public string $callId,
        public string $name,
        public string $content,
        public bool $isError = false,
    ) {}
}
