<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

use Plugins\Llm\API\Parts\Part;
use Plugins\Llm\API\Parts\ReasoningPart;
use Plugins\Llm\API\Parts\TextPart;
use Plugins\Llm\API\Parts\ToolCallPart;
use Plugins\Llm\API\Parts\ToolResultPart;

/**
 * A role and its parts. Immutable.
 *
 * Messages are what a CONVERSATION is made of; what one model call returned
 * is a {@see LlmResponse} and one pass of the tool loop is a {@see Step}. The
 * three are kept apart because they answer different questions — what was
 * said, what it cost and why it stopped, and what was done about it.
 */
final readonly class Message
{
    /** @param list<Part> $parts */
    public function __construct(public Role $role, public array $parts)
    {
        if ($parts === []) {
            throw new \InvalidArgumentException('A message needs at least one part.');
        }
        foreach ($parts as $part) {
            if (!$part instanceof Part) {
                throw new \InvalidArgumentException('A message part must implement ' . Part::class . '.');
            }
        }
    }

    public static function system(string $text): self
    {
        return new self(Role::System, [new TextPart($text)]);
    }

    public static function user(string $text): self
    {
        return new self(Role::User, [new TextPart($text)]);
    }

    /** @param string|list<Part> $content */
    public static function assistant(string|array $content): self
    {
        return new self(Role::Assistant, \is_string($content) ? [new TextPart($content)] : $content);
    }

    /** @param list<ToolResultPart> $results every result of ONE step, together */
    public static function toolResults(array $results): self
    {
        return new self(Role::Tool, $results);
    }

    public function text(): string
    {
        $text = '';
        foreach ($this->parts as $part) {
            if ($part instanceof TextPart) {
                $text .= $part->text;
            }
        }

        return $text;
    }

    /** @return list<ToolCallPart> */
    public function toolCalls(): array
    {
        return array_values(array_filter($this->parts, static fn (Part $p): bool => $p instanceof ToolCallPart));
    }

    public function reasoning(): string
    {
        $text = '';
        foreach ($this->parts as $part) {
            if ($part instanceof ReasoningPart) {
                $text .= $part->text;
            }
        }

        return $text;
    }
}
