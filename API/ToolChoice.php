<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

final readonly class ToolChoice
{
    public const AUTO = 'auto';
    public const NONE = 'none';
    public const REQUIRED = 'required';
    public const TOOL = 'tool';

    private function __construct(public string $type, public ?string $name = null) {}

    /** The model decides. */
    public static function auto(): self
    {
        return new self(self::AUTO);
    }

    /** The model must not call a tool. */
    public static function none(): self
    {
        return new self(self::NONE);
    }

    /** The model must call SOME tool. */
    public static function required(): self
    {
        return new self(self::REQUIRED);
    }

    /** The model must call this tool. */
    public static function tool(string $name): self
    {
        return new self(self::TOOL, $name);
    }
}
