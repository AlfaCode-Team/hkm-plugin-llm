<?php

declare(strict_types=1);

namespace Plugins\Llm\Infrastructure\Support;

/**
 * A credential that does not survive being printed.
 *
 * Four providers' API keys sit in objects that get `var_dump`ed in a debugger,
 * `print_r`ed into a log context, `json_encode`d into a response and caught in
 * an exception trace. A bare string property is exposed to every one of those.
 * This one holds the value in a closure and answers them all with a constant:
 *
 *   var_dump / print_r   __debugInfo()        → [redacted]
 *   var_export           the Closure exports empty — no value
 *   json_encode          JsonSerializable     → "[redacted]"
 *   (string) / sprintf   __toString()         → [redacted]
 *   serialize            refused
 *
 * The one way out is {@see reveal()}, whose call sites are the places a key
 * has to be put on the wire, and {@see scrub()} removes it from text that is
 * about to become an error message.
 *
 * It cannot protect a string already copied out of it, or the arguments an
 * HTTP client's own stack frames hold while a request is in flight. Run
 * production with `zend.exception_ignore_args=On` (the php.ini-production
 * default) so a trace does not record them.
 */
final class Secret implements \JsonSerializable
{
    public const MASK = '[redacted]';

    private readonly \Closure $reveal;

    public function __construct(#[\SensitiveParameter] string $value)
    {
        if ($value === '') {
            throw new \InvalidArgumentException('A secret cannot be empty.');
        }
        $this->reveal = static fn (): string => $value;
    }

    public function reveal(): string
    {
        return ($this->reveal)();
    }

    /** Remove this secret from text that is about to be shown or logged. */
    public function scrub(string $text): string
    {
        return str_replace($this->reveal(), self::MASK, $text);
    }

    public function __toString(): string
    {
        return self::MASK;
    }

    /** @return array<string, string> */
    public function __debugInfo(): array
    {
        return ['value' => self::MASK];
    }

    public function jsonSerialize(): string
    {
        return self::MASK;
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new \LogicException('A Secret cannot be serialized.');
    }
}
