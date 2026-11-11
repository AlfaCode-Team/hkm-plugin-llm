<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Llm\API\Schema;

final class SchemaTest extends TestCase
{
    private function person(): Schema
    {
        return Schema::object('person', ['name' => ['type' => 'string', 'minLength' => 1], 'age' => ['type' => 'integer', 'minimum' => 0]]);
    }

    public function test_a_valid_value_has_no_violations(): void
    {
        self::assertSame([], $this->person()->validate(['name' => 'Ada', 'age' => 36]));
    }

    /** @param list<string> $expected fragments, one per violation */
    #[DataProvider('invalid')]
    public function test_every_violation_is_reported_with_its_path(mixed $value, array $expected): void
    {
        $errors = $this->person()->validate($value);

        self::assertCount(\count($expected), $errors, implode(' | ', $errors));
        foreach ($expected as $i => $fragment) {
            self::assertStringContainsString($fragment, $errors[$i]);
        }
    }

    public static function invalid(): iterable
    {
        yield 'missing' => [['name' => 'Ada'], ['$.age: required but missing']];
        yield 'extra' => [['name' => 'Ada', 'age' => 1, 'x' => 1], ['$.x: not allowed']];
        yield 'wrong type' => [['name' => 'Ada', 'age' => '36'], ['$.age: expected integer, got string']];
        yield 'range' => [['name' => '', 'age' => -1], ['shorter than 1', 'below the minimum 0']];
        yield 'not an object' => ['text', ['$: expected object, got string']];
        yield 'a list is not an object' => [[1, 2], ['$: expected object, got array']];
        yield 'two at once' => [['name' => 5, 'age' => 'x'], ['$.name', '$.age']];
    }

    public function test_numbers_follow_json_rules(): void
    {
        $int = new Schema('n', ['type' => 'integer']);
        self::assertSame([], $int->validate(3.0), '3.0 is an integer in JSON');
        self::assertNotSame([], $int->validate(3.5));
        self::assertNotSame([], $int->validate(true), 'a boolean is not a number');
        self::assertNotSame([], $int->validate(NAN));
        self::assertSame([], (new Schema('n', ['type' => 'number']))->validate(3));
    }

    public function test_nested_arrays_enums_consts_and_anyof(): void
    {
        $s = new Schema('t', [
            'type' => 'object',
            'properties' => [
                'tags' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => ['a', 'b']], 'minItems' => 1, 'maxItems' => 2],
                'kind' => ['const' => 'x'],
                'v' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
            ],
            'required' => ['tags'],
        ]);

        self::assertSame([], $s->validate(['tags' => ['a'], 'kind' => 'x', 'v' => null, 'extra' => 1]));
        self::assertNotSame([], $s->validate(['tags' => []]));
        self::assertNotSame([], $s->validate(['tags' => ['a', 'b', 'a']]));
        self::assertStringContainsString('$.tags[1]', $s->validate(['tags' => ['a', 'z']])[0]);
        self::assertNotSame([], $s->validate(['tags' => ['a'], 'kind' => 'y']));
        self::assertStringContainsString('anyOf', $s->validate(['tags' => ['a'], 'v' => 5])[0]);
    }

    public function test_type_lists_allow_null(): void
    {
        $s = new Schema('t', ['type' => ['string', 'null']]);

        self::assertSame([], $s->validate(null));
        self::assertSame([], $s->validate('x'));
        self::assertNotSame([], $s->validate(1));
    }

    public function test_length_is_counted_in_characters_not_bytes(): void
    {
        $s = new Schema('t', ['type' => 'string', 'maxLength' => 2]);

        self::assertSame([], $s->validate('éé'), 'two characters, four bytes');
        self::assertNotSame([], $s->validate('ééé'));
    }

    #[DataProvider('unsupportedKeywords')]
    public function test_a_keyword_it_cannot_check_is_refused_by_name(string $keyword, mixed $value): void
    {
        // Sent to the provider but never checked locally would be a guarantee
        // that reads stronger than it is.
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("[{$keyword}]");
        new Schema('t', ['type' => 'string', $keyword => $value]);
    }

    public static function unsupportedKeywords(): iterable
    {
        yield 'pattern' => ['pattern', '^a'];
        yield 'format' => ['format', 'email'];
        yield '$ref' => ['$ref', '#/x'];
        yield 'oneOf' => ['oneOf', []];
        yield 'allOf' => ['allOf', []];
        yield 'not' => ['not', []];
        yield 'multipleOf' => ['multipleOf', 2];
    }

    public function test_an_unsupported_keyword_is_refused_at_any_depth(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('[pattern] at $.a.b');
        new Schema('t', ['type' => 'object', 'properties' => ['a' => ['type' => 'object', 'properties' => ['b' => ['type' => 'string', 'pattern' => 'x']]]]]);
    }

    /** @param array<string, mixed> $definition */
    #[DataProvider('malformed')]
    public function test_malformed_definitions_are_refused(array $definition): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Schema('t', $definition);
    }

    public static function malformed(): iterable
    {
        yield 'unknown type' => [['type' => 'text']];
        yield 'required not strings' => [['type' => 'object', 'required' => [1]]];
        yield 'schema-valued additionalProperties' => [['type' => 'object', 'additionalProperties' => ['type' => 'string']]];
        yield 'empty enum' => [['enum' => []]];
        yield 'empty anyOf' => [['anyOf' => []]];
        yield 'negative length' => [['type' => 'string', 'minLength' => -1]];
        yield 'non-numeric minimum' => [['type' => 'number', 'minimum' => 'zero']];
    }

    public function test_a_name_must_be_plain_and_a_trailing_newline_does_not_pass(): void
    {
        foreach (['ok_name-1', 'a'] as $good) {
            self::assertSame($good, (new Schema($good, ['type' => 'string']))->name);
        }
        foreach (['', "name\n", 'has space', str_repeat('a', 65), 'dot.ted'] as $bad) {
            try {
                new Schema($bad, ['type' => 'string']);
                self::fail('accepted ' . json_encode($bad));
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('schema name', $e->getMessage());
            }
        }
    }

    public function test_nesting_is_capped(): void
    {
        $deep = ['type' => 'string'];
        for ($i = 0; $i < 20; $i++) {
            $deep = ['type' => 'object', 'properties' => ['a' => $deep]];
        }

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('deeper than');
        new Schema('t', $deep);
    }

    public function test_closedness_and_range_detection(): void
    {
        self::assertTrue(Schema::object('p', ['a' => ['type' => 'string']])->isClosed());
        self::assertFalse(Schema::object('p', ['a' => ['type' => 'string']], required: [])->isClosed(), 'an optional property is not "fully required"');
        self::assertFalse((new Schema('p', ['type' => 'object', 'properties' => ['a' => ['type' => 'string']], 'required' => ['a']]))->isClosed(), 'additionalProperties defaults to open');

        $nested = new Schema('p', ['type' => 'object', 'additionalProperties' => false, 'required' => ['i'], 'properties' => [
            'i' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['x' => ['type' => 'string']], 'required' => ['x']]],
        ]]);
        self::assertFalse($nested->isClosed(), 'an open object inside items is found');

        self::assertFalse(Schema::object('p', ['a' => ['type' => 'string']])->hasRangeConstraints());
        self::assertTrue($this->person()->hasRangeConstraints());
    }

    public function test_empty_properties_are_sent_as_an_object(): void
    {
        $json = (string) json_encode(Schema::object('empty', [])->toJsonSchema());

        self::assertStringContainsString('"properties":{}', $json);
    }

    public function test_an_empty_array_satisfies_object_and_array_because_json_decode_cannot_tell(): void
    {
        self::assertSame([], (new Schema('o', ['type' => 'object']))->validate([]));
        self::assertSame([], (new Schema('a', ['type' => 'array']))->validate([]));
    }
}
