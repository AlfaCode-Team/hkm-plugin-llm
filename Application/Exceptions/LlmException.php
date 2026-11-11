<?php

declare(strict_types=1);

namespace Plugins\Llm\Application\Exceptions;

use AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\ServiceException;

/**
 * Everything that is the CALLER's to fix — as opposed to a provider that
 * failed, which is a GatewayException.
 *
 * Messages are written to be safe in a log: they name providers, variables
 * and capabilities, and never a credential or a prompt.
 *
 * `$violations` is set when a model's structured output failed validation,
 * and `$rawOutput` is the text it produced. Both are properties and not part
 * of the message, because the text is model output — unbounded, and possibly
 * containing whatever the prompt did.
 */
final class LlmException extends ServiceException
{
    /** @var list<string> */
    public array $violations = [];

    public string $rawOutput = '';

    public static function unknownProvider(string $name, array $known): self
    {
        return new self(
            "Unknown LLM provider [{$name}]. Known: " . implode(', ', $known) . '.',
            layer: 'service.llm',
            context: ['provider' => $name],
        );
    }

    /** @param list<string> $missing environment variable names */
    public static function notConfigured(string $provider, array $missing): self
    {
        return new self(
            "LLM provider [{$provider}] is not configured. Set: " . implode(', ', $missing) . '.',
            layer: 'service.llm',
            context: ['provider' => $provider, 'missing' => $missing],
        );
    }

    public static function unsupported(string $provider, string $what): self
    {
        return new self(
            "LLM provider [{$provider}] cannot honour {$what}. It was refused rather than ignored.",
            layer: 'service.llm',
            context: ['provider' => $provider, 'unsupported' => $what],
        );
    }

    public static function schemaNotHonoured(string $provider, string $why): self
    {
        return new self(
            "LLM provider [{$provider}] cannot constrain its output to this schema natively ({$why}). "
            . 'Pass StructuredFallback::PromptAndValidate to accept a prompt-only fallback (the response will '
            . 'say StructuredMode::Prompted), or use a provider that can.',
            layer: 'service.llm',
            context: ['provider' => $provider, 'reason' => $why],
        );
    }

    /** @param list<string> $violations */
    public static function invalidOutput(string $provider, string $why, array $violations, string $raw): self
    {
        $e = new self(
            "LLM provider [{$provider}] returned structured output that is not valid: {$why}",
            layer: 'service.llm',
            context: ['provider' => $provider, 'violations' => \array_slice($violations, 0, 10)],
        );
        $e->violations = $violations;
        $e->rawOutput = $raw;

        return $e;
    }

    public static function invalidRequest(string $why): self
    {
        return new self($why, layer: 'service.llm');
    }
}
