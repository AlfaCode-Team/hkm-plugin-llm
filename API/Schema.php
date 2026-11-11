<?php

declare(strict_types=1);

namespace Plugins\Llm\API;

/**
 * A JSON Schema this plugin can both SEND and CHECK.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * WHY THE KEYWORD LIST IS CLOSED
 * ════════════════════════════════════════════════════════════════════════════
 *
 * Structured output is only worth having if "schema-valid" is something this
 * plugin verifies rather than hopes. Every response that asked for a schema is
 * run through {@see validate()} before the caller sees it, in every mode, so
 * the validator has to understand every keyword the schema uses. A keyword it
 * cannot check (`pattern`, `$ref`, `oneOf`, `format`…) would be sent to the
 * provider and then silently NOT enforced locally — a guarantee that reads as
 * stronger than it is. So the constructor refuses them, by name, instead.
 *
 * Supported: type, properties, required, additionalProperties (boolean only),
 * items, enum, const, minimum, maximum, minLength, maxLength, minItems,
 * maxItems, anyOf, and the annotations description / title.
 *
 * `{}` decodes to an empty PHP array, indistinguishable from `[]`, so an empty
 * array satisfies BOTH `object` and `array`. That is a property of
 * `json_decode(…, true)`, not of this class.
 */
final readonly class Schema
{
    private const KEYWORDS = [
        'type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'const',
        'minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems', 'anyOf',
        'description', 'title',
    ];

    private const TYPES = ['null', 'boolean', 'integer', 'number', 'string', 'array', 'object'];

    private const MAX_DEPTH = 16;

    /** @param array<string, mixed> $definition */
    public function __construct(public string $name, public array $definition)
    {
        // The intersection of what OpenAI (the strictest) accepts as a name.
        if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $name) !== 1) {
            throw new \InvalidArgumentException('A schema name must be 1-64 characters of letters, digits, _ or -.');
        }
        self::assertDefinition($definition, '$', 0);
    }

    /**
     * @param array<string, array<string, mixed>> $properties property name => schema definition
     * @param list<string>|null                   $required   null = every property
     */
    public static function object(string $name, array $properties, ?array $required = null, string $description = ''): self
    {
        $definition = [
            'type' => 'object',
            'properties' => $properties,
            'required' => $required ?? array_map('strval', array_keys($properties)),
            'additionalProperties' => false,
        ];
        if ($description !== '') {
            $definition['description'] = $description;
        }

        return new self($name, $definition);
    }

    public function isObject(): bool
    {
        return self::typeIncludes($this->definition, 'object') && !self::typeIncludes($this->definition, 'array');
    }

    /**
     * What a provider is sent. An empty `properties` map is re-encoded as an
     * object: PHP's `[]` would serialise as a JSON array, which every provider
     * rejects there.
     *
     * @return array<string, mixed>
     */
    public function toJsonSchema(): array
    {
        /** @var array<string, mixed> */
        return self::normalise($this->definition);
    }

    /**
     * True when every object in the schema is "closed and fully required":
     * `additionalProperties: false` and every declared property listed in
     * `required`. That is the form OpenAI's strict mode and Anthropic's
     * structured outputs accept; anything looser cannot be CONSTRAINED, only
     * requested.
     */
    public function isClosed(): bool
    {
        return self::closed($this->definition);
    }

    /**
     * True when the schema uses a value-range keyword (numeric bounds, string
     * lengths, array sizes). Providers that constrain decoding to a schema
     * commonly accept only a subset, and these are the keywords that subset
     * most often omits; a gateway that is not sure refuses them.
     */
    public function hasRangeConstraints(): bool
    {
        return self::ranged($this->definition);
    }

    /**
     * Check a decoded JSON value. Returns every violation, with a path; an
     * empty list means valid.
     *
     * @return list<string>
     */
    public function validate(mixed $value): array
    {
        $errors = [];
        self::check($this->definition, $value, '$', $errors, 0);

        return $errors;
    }

    // ── definition checks ───────────────────────────────────────────────────

    /** @param array<string, mixed> $d */
    private static function assertDefinition(array $d, string $path, int $depth): void
    {
        if ($depth > self::MAX_DEPTH) {
            throw new \InvalidArgumentException("Schema nests deeper than " . self::MAX_DEPTH . " levels at {$path}.");
        }

        foreach (array_keys($d) as $keyword) {
            if (!\in_array($keyword, self::KEYWORDS, true)) {
                throw new \InvalidArgumentException(
                    "Schema keyword [{$keyword}] at {$path} is not supported. This plugin validates every "
                    . 'response against the schema it sends, so it only accepts keywords it can check: '
                    . implode(', ', self::KEYWORDS) . '.',
                );
            }
        }

        if (isset($d['type'])) {
            foreach ((array) $d['type'] as $t) {
                if (!\is_string($t) || !\in_array($t, self::TYPES, true)) {
                    throw new \InvalidArgumentException("Schema type at {$path} must be one of " . implode(', ', self::TYPES) . '.');
                }
            }
        }

        if (isset($d['properties'])) {
            if (!\is_array($d['properties'])) {
                throw new \InvalidArgumentException("Schema 'properties' at {$path} must be a map.");
            }
            foreach ($d['properties'] as $name => $sub) {
                if (!\is_array($sub)) {
                    throw new \InvalidArgumentException("Schema property [{$name}] at {$path} must be a schema definition.");
                }
                self::assertDefinition($sub, "{$path}.{$name}", $depth + 1);
            }
        }

        if (isset($d['required'])) {
            if (!\is_array($d['required']) || !array_is_list($d['required']) || array_filter($d['required'], 'is_string') !== $d['required']) {
                throw new \InvalidArgumentException("Schema 'required' at {$path} must be a list of property names.");
            }
        }

        if (isset($d['additionalProperties']) && !\is_bool($d['additionalProperties'])) {
            throw new \InvalidArgumentException(
                "Schema 'additionalProperties' at {$path} must be true or false; a schema there cannot be sent portably.",
            );
        }

        if (isset($d['items'])) {
            if (!\is_array($d['items'])) {
                throw new \InvalidArgumentException("Schema 'items' at {$path} must be a schema definition.");
            }
            self::assertDefinition($d['items'], "{$path}[]", $depth + 1);
        }

        if (isset($d['anyOf'])) {
            if (!\is_array($d['anyOf']) || $d['anyOf'] === [] || !array_is_list($d['anyOf'])) {
                throw new \InvalidArgumentException("Schema 'anyOf' at {$path} must be a non-empty list.");
            }
            foreach ($d['anyOf'] as $i => $sub) {
                if (!\is_array($sub)) {
                    throw new \InvalidArgumentException("Schema 'anyOf' entry {$i} at {$path} must be a schema definition.");
                }
                self::assertDefinition($sub, "{$path}|{$i}", $depth + 1);
            }
        }

        if (isset($d['enum']) && (!\is_array($d['enum']) || $d['enum'] === [] || !array_is_list($d['enum']))) {
            throw new \InvalidArgumentException("Schema 'enum' at {$path} must be a non-empty list.");
        }

        foreach (['minimum', 'maximum'] as $k) {
            if (isset($d[$k]) && !\is_int($d[$k]) && !\is_float($d[$k])) {
                throw new \InvalidArgumentException("Schema '{$k}' at {$path} must be a number.");
            }
        }
        foreach (['minLength', 'maxLength', 'minItems', 'maxItems'] as $k) {
            if (isset($d[$k]) && (!\is_int($d[$k]) || $d[$k] < 0)) {
                throw new \InvalidArgumentException("Schema '{$k}' at {$path} must be a non-negative integer.");
            }
        }
    }

    /** @param array<string, mixed> $d */
    private static function typeIncludes(array $d, string $type): bool
    {
        return isset($d['type']) && \in_array($type, (array) $d['type'], true);
    }

    // ── sending ─────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $d
     * @return array<string, mixed>
     */
    private static function normalise(array $d): array
    {
        if (isset($d['properties'])) {
            $props = [];
            foreach ($d['properties'] as $name => $sub) {
                $props[$name] = self::normalise($sub);
            }
            $d['properties'] = $props === [] ? new \stdClass() : $props;
        }
        if (isset($d['items'])) {
            $d['items'] = self::normalise($d['items']);
        }
        if (isset($d['anyOf'])) {
            $d['anyOf'] = array_map(static fn (array $s): array => self::normalise($s), $d['anyOf']);
        }

        return $d;
    }

    /** @param array<string, mixed> $d */
    private static function closed(array $d): bool
    {
        $isObject = self::typeIncludes($d, 'object') || isset($d['properties']);
        if ($isObject) {
            $props = $d['properties'] ?? [];
            if (($d['additionalProperties'] ?? true) !== false) {
                return false;
            }
            $required = $d['required'] ?? [];
            foreach (array_keys($props) as $name) {
                if (!\in_array((string) $name, $required, true)) {
                    return false;
                }
            }
            foreach ($props as $sub) {
                if (!self::closed($sub)) {
                    return false;
                }
            }
        }
        if (isset($d['items']) && !self::closed($d['items'])) {
            return false;
        }
        foreach ($d['anyOf'] ?? [] as $sub) {
            if (!self::closed($sub)) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $d */
    private static function ranged(array $d): bool
    {
        foreach (['minimum', 'maximum', 'minLength', 'maxLength', 'minItems', 'maxItems'] as $k) {
            if (isset($d[$k])) {
                return true;
            }
        }
        foreach ($d['properties'] ?? [] as $sub) {
            if (self::ranged($sub)) {
                return true;
            }
        }
        if (isset($d['items']) && self::ranged($d['items'])) {
            return true;
        }
        foreach ($d['anyOf'] ?? [] as $sub) {
            if (self::ranged($sub)) {
                return true;
            }
        }

        return false;
    }

    // ── validation ──────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $d
     * @param list<string>         $errors
     */
    private static function check(array $d, mixed $v, string $path, array &$errors, int $depth): void
    {
        if ($depth > 64) {
            $errors[] = "{$path}: nested too deeply";

            return;
        }

        if (isset($d['anyOf'])) {
            $matched = false;
            foreach ($d['anyOf'] as $sub) {
                $sink = [];
                self::check($sub, $v, $path, $sink, $depth + 1);
                if ($sink === []) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $errors[] = "{$path}: matches none of the anyOf alternatives";
            }
        }

        if (isset($d['type'])) {
            $ok = false;
            foreach ((array) $d['type'] as $t) {
                if (self::isType($t, $v)) {
                    $ok = true;
                    break;
                }
            }
            if (!$ok) {
                $errors[] = "{$path}: expected " . implode('|', (array) $d['type']) . ', got ' . self::describe($v);

                return; // the remaining keywords assume the type
            }
        }

        if (isset($d['enum']) && !self::inList($v, $d['enum'])) {
            $errors[] = "{$path}: not one of the allowed values";
        }
        if (\array_key_exists('const', $d) && !self::same($v, $d['const'])) {
            $errors[] = "{$path}: does not equal the required constant";
        }

        if (\is_int($v) || \is_float($v)) {
            if (isset($d['minimum']) && $v < $d['minimum']) {
                $errors[] = "{$path}: below the minimum {$d['minimum']}";
            }
            if (isset($d['maximum']) && $v > $d['maximum']) {
                $errors[] = "{$path}: above the maximum {$d['maximum']}";
            }
        }

        if (\is_string($v)) {
            $len = mb_strlen($v, 'UTF-8');
            if (isset($d['minLength']) && $len < $d['minLength']) {
                $errors[] = "{$path}: shorter than {$d['minLength']} characters";
            }
            if (isset($d['maxLength']) && $len > $d['maxLength']) {
                $errors[] = "{$path}: longer than {$d['maxLength']} characters";
            }
        }

        if (\is_array($v) && (array_is_list($v)) && isset($d['items'])) {
            foreach ($v as $i => $item) {
                self::check($d['items'], $item, "{$path}[{$i}]", $errors, $depth + 1);
            }
        }
        if (\is_array($v) && array_is_list($v)) {
            if (isset($d['minItems']) && \count($v) < $d['minItems']) {
                $errors[] = "{$path}: fewer than {$d['minItems']} items";
            }
            if (isset($d['maxItems']) && \count($v) > $d['maxItems']) {
                $errors[] = "{$path}: more than {$d['maxItems']} items";
            }
        }

        if (\is_array($v) && ($v === [] || !array_is_list($v)) && (isset($d['properties']) || isset($d['required']) || isset($d['additionalProperties']))) {
            foreach ($d['required'] ?? [] as $name) {
                if (!\array_key_exists($name, $v)) {
                    $errors[] = "{$path}.{$name}: required but missing";
                }
            }
            foreach ($v as $name => $value) {
                if (isset($d['properties'][$name])) {
                    self::check($d['properties'][$name], $value, "{$path}.{$name}", $errors, $depth + 1);
                } elseif (($d['additionalProperties'] ?? true) === false) {
                    $errors[] = "{$path}.{$name}: not allowed (additionalProperties is false)";
                }
            }
        }
    }

    private static function isType(string $type, mixed $v): bool
    {
        return match ($type) {
            'null' => $v === null,
            'boolean' => \is_bool($v),
            'integer' => \is_int($v) || (\is_float($v) && is_finite($v) && floor($v) === $v),
            'number' => \is_int($v) || (\is_float($v) && is_finite($v)),
            'string' => \is_string($v),
            'array' => \is_array($v) && array_is_list($v),
            'object' => \is_array($v) && ($v === [] || !array_is_list($v)),
            default => false,
        };
    }

    private static function describe(mixed $v): string
    {
        return match (true) {
            $v === null => 'null',
            \is_bool($v) => 'boolean',
            \is_int($v) => 'integer',
            \is_float($v) => 'number',
            \is_string($v) => 'string',
            \is_array($v) => array_is_list($v) ? 'array' : 'object',
            default => get_debug_type($v),
        };
    }

    /** @param list<mixed> $list */
    private static function inList(mixed $v, array $list): bool
    {
        foreach ($list as $candidate) {
            if (self::same($v, $candidate)) {
                return true;
            }
        }

        return false;
    }

    private static function same(mixed $a, mixed $b): bool
    {
        if ((\is_int($a) || \is_float($a)) && (\is_int($b) || \is_float($b))) {
            return $a == $b; // 1 and 1.0 are the same JSON number
        }

        return $a === $b;
    }
}
