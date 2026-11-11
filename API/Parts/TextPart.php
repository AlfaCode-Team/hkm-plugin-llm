<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Parts;

final readonly class TextPart implements Part
{
    public function __construct(public string $text) {}
}
