<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

/**
 * One model call, described neutrally. Immutable; `with*` returns a copy.
 *
 * Every field a provider might not support is here as an explicit, nullable
 * field rather than an `options` bag — so that "this provider ignored my
 * temperature" is a refusal at the gateway, not a surprise in the bill.
 */
final readonly class LlmRequest
{
    /**
     * @param list<Message> $messages
     * @param list<Tool>    $tools   definitions the model may call; nothing here makes them runnable
     * @param list<string>  $stop
     * @param ?string       $model   overrides the provider's configured model for this call
     */
    public function __construct(
        public array $messages,
        public array $tools = [],
        public ?ToolChoice $toolChoice = null,
        public ?Schema $schema = null,
        public StructuredFallback $structuredFallback = StructuredFallback::Refuse,
        public ?int $maxTokens = null,
        public ?float $temperature = null,
        public ?Effort $effort = null,
        public array $stop = [],
        public ?string $model = null,
    ) {
        $roles = array_map(static fn (Message $m): Role => $m->role, $messages);
        if ($messages === [] || array_filter($roles, static fn (Role $r): bool => $r !== Role::System) === []) {
            throw new \InvalidArgumentException('A request needs at least one non-system message.');
        }
        if ($maxTokens !== null && $maxTokens < 1) {
            throw new \InvalidArgumentException('maxTokens must be at least 1.');
        }
        if ($temperature !== null && ($temperature < 0.0 || $temperature > 2.0)) {
            throw new \InvalidArgumentException('temperature must be between 0 and 2.');
        }
        if ($model !== null && preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:\/-]{0,127}\z/', $model) !== 1) {
            // The model id is interpolated into a URL path by one provider.
            throw new \InvalidArgumentException('model must be a plain model id (letters, digits, . _ : / -).');
        }

        $names = [];
        foreach ($tools as $tool) {
            if (isset($names[$tool->name])) {
                throw new \InvalidArgumentException("Tool [{$tool->name}] is declared twice.");
            }
            $names[$tool->name] = true;
        }
        if ($toolChoice !== null && $toolChoice->type !== ToolChoice::NONE && $tools === []) {
            throw new \InvalidArgumentException('A tool choice other than none needs at least one tool.');
        }
        if ($toolChoice?->type === ToolChoice::TOOL && !isset($names[(string) $toolChoice->name])) {
            throw new \InvalidArgumentException("Tool choice names [{$toolChoice->name}], which is not among the tools.");
        }
    }

    public static function prompt(string $user, ?string $system = null): self
    {
        return new self([...($system !== null ? [Message::system($system)] : []), Message::user($user)]);
    }

    /** @param list<Message> $messages */
    public function withMessages(array $messages): self
    {
        return $this->copy(['messages' => $messages]);
    }

    public function withSchema(?Schema $schema, StructuredFallback $fallback = StructuredFallback::Refuse): self
    {
        return $this->copy(['schema' => $schema, 'structuredFallback' => $fallback]);
    }

    /** @param list<Tool> $tools */
    public function withTools(array $tools, ?ToolChoice $choice = null): self
    {
        return $this->copy(['tools' => $tools, 'toolChoice' => $choice]);
    }

    public function withEffort(?Effort $effort): self
    {
        return $this->copy(['effort' => $effort]);
    }

    public function withModel(?string $model): self
    {
        return $this->copy(['model' => $model]);
    }

    /** The system messages joined, for providers that take one system field. */
    public function system(): string
    {
        $parts = [];
        foreach ($this->messages as $m) {
            if ($m->role === Role::System) {
                $parts[] = $m->text();
            }
        }

        return implode("\n\n", $parts);
    }

    /** @return list<Message> everything except the system messages */
    public function conversation(): array
    {
        return array_values(array_filter($this->messages, static fn (Message $m): bool => $m->role !== Role::System));
    }

    /** @param array<string, mixed> $changes */
    private function copy(array $changes): self
    {
        return new self(...array_merge(get_object_vars($this), $changes));
    }
}
