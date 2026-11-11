<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Llm\API\Effort;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\Message;
use Plugins\Llm\API\Role;
use Plugins\Llm\API\Schema;
use Plugins\Llm\API\Tool;
use Plugins\Llm\API\ToolChoice;
use Tests\Plugins\Llm\Support\Make;

final class LlmRequestTest extends TestCase
{
    /** @param \Closure(): mixed $make */
    #[DataProvider('invalid')]
    public function test_an_invalid_request_is_refused_at_construction(\Closure $make, string $why): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($why);
        $make();
    }

    public static function invalid(): iterable
    {
        $u = [Message::user('x')];
        yield 'no messages' => [static fn () => new LlmRequest([]), 'non-system message'];
        yield 'only system' => [static fn () => new LlmRequest([Message::system('s')]), 'non-system message'];
        yield 'maxTokens' => [static fn () => new LlmRequest($u, maxTokens: 0), 'maxTokens'];
        yield 'temperature' => [static fn () => new LlmRequest($u, temperature: 2.5), 'temperature'];
        yield 'model with a newline' => [static fn () => new LlmRequest($u, model: "gpt\nx"), 'plain model id'];
        yield 'model with a query' => [static fn () => new LlmRequest($u, model: 'x?key=1'), 'plain model id'];
        yield 'duplicate tool' => [static fn () => new LlmRequest($u, [Make::pingTool(), Make::pingTool()]), 'twice'];
        yield 'tool choice without tools' => [static fn () => new LlmRequest($u, toolChoice: ToolChoice::required()), 'at least one tool'];
        yield 'named tool absent' => [static fn () => new LlmRequest($u, [Make::pingTool()], ToolChoice::tool('nope')), 'not among the tools'];
    }

    public function test_with_methods_return_copies_and_keep_everything_else(): void
    {
        $a = new LlmRequest([Message::user('x')], maxTokens: 9, temperature: 0.1, stop: ['s']);
        $b = $a->withEffort(Effort::High)->withModel('m-1')->withSchema(Make::personSchema());

        self::assertNull($a->effort);
        self::assertNull($a->schema);
        self::assertSame(Effort::High, $b->effort);
        self::assertSame([9, 0.1, ['s'], 'm-1'], [$b->maxTokens, $b->temperature, $b->stop, $b->model]);
    }

    public function test_system_messages_are_joined_and_removed_from_the_conversation(): void
    {
        $r = new LlmRequest([Message::system('one'), Message::user('u'), Message::system('two')]);

        self::assertSame("one\n\ntwo", $r->system());
        self::assertCount(1, $r->conversation());
        self::assertSame(Role::User, $r->conversation()[0]->role);
    }

    public function test_a_tool_needs_a_plain_name_a_description_and_an_object_input(): void
    {
        foreach ([
            ['has space', 'd', Make::pingTool()->input],
            ["name\n", 'd', Make::pingTool()->input],
            ['1leading', 'd', Make::pingTool()->input],
            ['ok', '', Make::pingTool()->input],
            ['ok', 'd', new Schema('s', ['type' => 'string'])],
        ] as [$name, $description, $input]) {
            try {
                new Tool($name, $description, $input);
                self::fail(json_encode([$name, $description]));
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_a_message_needs_parts(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Message(Role::User, []);
    }
}
