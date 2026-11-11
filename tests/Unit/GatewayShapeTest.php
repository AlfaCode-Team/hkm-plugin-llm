<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Plugins\Llm\API\Effort;
use Plugins\Llm\API\FinishReason;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\Message;
use Plugins\Llm\API\Parts\ReasoningPart;
use Plugins\Llm\API\Parts\TextPart;
use Plugins\Llm\API\Parts\ToolCallPart;
use Plugins\Llm\API\ToolChoice;
use Plugins\Llm\Application\Exceptions\LlmException;
use Tests\Plugins\Llm\Support\Make;
use Tests\Plugins\Llm\Support\RecordingHttpClient;

/**
 * What each adapter PUTS ON THE WIRE and what it makes of the reply.
 *
 * The expected arrays are written out in full from each provider's documented
 * request format — see the class docblock of each gateway — and the reply
 * fixtures in tests/fixtures are hand-written to the documented response
 * shape. NOTHING HERE WAS RECORDED FROM A LIVE PROVIDER. What these tests pin
 * is that the adapter does what its docblock says; whether the docblock
 * matches the provider today is the one thing only a live call can show.
 */
final class GatewayShapeTest extends TestCase
{
    private const TOOLS_JSON = [
        'get_invoice' => ['type' => 'object', 'properties' => ['id' => ['type' => 'string']], 'required' => ['id'], 'additionalProperties' => false],
        'ping' => ['type' => 'object', 'properties' => [], 'required' => [], 'additionalProperties' => false],
    ];

    // ── Anthropic ───────────────────────────────────────────────────────────

    public function test_anthropic_builds_the_messages_api_request(): void
    {
        $http = RecordingHttpClient::fixture('anthropic_text');
        Make::gateway('anthropic', $http)->generate(Make::toolConversation());

        self::assertSame('POST', $http->calls[0]['method']);
        self::assertSame('https://api.anthropic.com/v1/messages', $http->url());
        self::assertSame(['x-api-key' => Make::ANTHROPIC_KEY, 'anthropic-version' => '2023-06-01'], $http->headers());

        self::assertEquals([
            'model' => 'claude-sonnet-5-5',
            'max_tokens' => 500,
            'system' => 'Be brief.',
            'messages' => [
                ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Invoice 7?']]],
                ['role' => 'assistant', 'content' => [
                    ['type' => 'text', 'text' => 'Checking.'],
                    ['type' => 'tool_use', 'id' => 'c1', 'name' => 'get_invoice', 'input' => ['id' => 'inv-7']],
                    ['type' => 'tool_use', 'id' => 'c2', 'name' => 'ping', 'input' => []],
                ]],
                // tool results travel in a USER turn, all of one step together
                ['role' => 'user', 'content' => [
                    ['type' => 'tool_result', 'tool_use_id' => 'c1', 'content' => '{"total":100}', 'is_error' => false],
                    ['type' => 'tool_result', 'tool_use_id' => 'c2', 'content' => 'pong', 'is_error' => false],
                ]],
            ],
            'tools' => [
                ['name' => 'get_invoice', 'description' => 'Fetch one invoice by id.', 'input_schema' => self::TOOLS_JSON['get_invoice']],
                ['name' => 'ping', 'description' => 'Check the service is up.', 'input_schema' => self::TOOLS_JSON['ping']],
            ],
            'tool_choice' => ['type' => 'auto'],
            'temperature' => 0.2,
            'stop_sequences' => ['END'],
        ], $http->sent());
    }

    public function test_empty_tool_arguments_are_sent_as_a_json_object_not_an_array(): void
    {
        // PHP's [] would serialise as `[]`, which every provider rejects as a tool input.
        $http = RecordingHttpClient::fixture('anthropic_text');
        Make::gateway('anthropic', $http)->generate(Make::toolConversation());

        self::assertStringContainsString('"name":"ping","input":{}', $http->wire());
        self::assertStringContainsString('"properties":{},"required":[]', $http->wire());
    }

    public function test_anthropic_parses_text_usage_and_finish(): void
    {
        $r = Make::gateway('anthropic', RecordingHttpClient::fixture('anthropic_text'))->generate(LlmRequest::prompt('hi'));

        self::assertSame('Hello there.', $r->text());
        self::assertSame(FinishReason::Stop, $r->finish);
        self::assertSame('anthropic', $r->provider);
        self::assertSame(12, $r->usage->input);
        self::assertSame(5, $r->usage->output);
        self::assertSame(3, $r->usage->cacheRead);
    }

    public function test_anthropic_parses_tool_use_and_keeps_reasoning_for_the_round_trip(): void
    {
        $r = Make::gateway('anthropic', RecordingHttpClient::fixture('anthropic_tool_use'))->generate(LlmRequest::prompt('hi'));

        self::assertSame(FinishReason::ToolCalls, $r->finish);
        $calls = $r->toolCalls();
        self::assertCount(2, $calls);
        self::assertSame(['toolu_01', 'get_invoice', ['id' => 'inv-7']], [$calls[0]->id, $calls[0]->name, $calls[0]->arguments]);
        self::assertSame([], $calls[1]->arguments);
        self::assertSame('I should look it up.', $r->message->reasoning());

        // Echoed back verbatim on the next request, signature included.
        $http = RecordingHttpClient::fixture('anthropic_text');
        Make::gateway('anthropic', $http)->generate(new LlmRequest([Message::user('hi'), $r->message]));
        self::assertSame(
            ['type' => 'thinking', 'thinking' => 'I should look it up.', 'signature' => 'sig-abc'],
            $http->sent()['messages'][1]['content'][0],
        );
    }

    public function test_a_reasoning_block_from_another_provider_is_dropped_not_forwarded(): void
    {
        $http = RecordingHttpClient::fixture('anthropic_text');
        $foreign = Message::assistant([new ReasoningPart('thoughts', ['type' => 'openai-thing']), new TextPart('answer')]);
        Make::gateway('anthropic', $http)->generate(new LlmRequest([Message::user('hi'), $foreign, Message::user('more')]));

        self::assertSame([['type' => 'text', 'text' => 'answer']], $http->sent()['messages'][1]['content']);
    }

    public function test_anthropic_structured_output_and_effort(): void
    {
        $http = RecordingHttpClient::fixture('anthropic_text');
        Make::gateway('anthropic', $http)->generate(
            LlmRequest::prompt('hi')->withSchema(Make::personSchema())->withEffort(Effort::Medium),
        );
        $sent = $http->sent();

        self::assertSame(['type' => 'json_schema', 'schema' => Make::personSchema()->toJsonSchema()], $sent['output_config']['format']);
        self::assertSame(['type' => 'enabled', 'budget_tokens' => 8192], $sent['thinking']);
        self::assertSame(8192 + 4096, $sent['max_tokens'], 'max_tokens must exceed the thinking budget');
    }

    public function test_anthropic_refuses_what_it_cannot_do_before_sending(): void
    {
        $http = RecordingHttpClient::fixture('anthropic_text');
        $gw = Make::gateway('anthropic', $http);

        foreach ([
            'temperature' => new LlmRequest([Message::user('x')], temperature: 0.5, effort: Effort::Low),
            'forced tool' => new LlmRequest([Message::user('x')], [Make::pingTool()], ToolChoice::required(), effort: Effort::Low),
            'maxTokens' => new LlmRequest([Message::user('x')], maxTokens: 1000, effort: Effort::Medium),
        ] as $label => $request) {
            try {
                $gw->generate($request);
                self::fail("{$label} was not refused");
            } catch (LlmException $e) {
                self::assertStringContainsString('cannot honour', $e->getMessage(), $label);
            }
        }
        self::assertSame([], $http->calls, 'a refusal must happen before anything is sent');
    }

    #[DataProvider('anthropicChoices')]
    public function test_anthropic_tool_choice_mapping(ToolChoice $choice, array $expected): void
    {
        $http = RecordingHttpClient::fixture('anthropic_text');
        Make::gateway('anthropic', $http)->generate(new LlmRequest([Message::user('x')], [Make::pingTool()], $choice));

        self::assertSame($expected, $http->sent()['tool_choice']);
    }

    public static function anthropicChoices(): iterable
    {
        yield 'auto' => [ToolChoice::auto(), ['type' => 'auto']];
        yield 'none' => [ToolChoice::none(), ['type' => 'none']];
        yield 'required' => [ToolChoice::required(), ['type' => 'any']];
        yield 'named' => [ToolChoice::tool('ping'), ['type' => 'tool', 'name' => 'ping']];
    }

    // ── OpenAI ──────────────────────────────────────────────────────────────

    public function test_openai_builds_the_chat_completions_request(): void
    {
        $http = RecordingHttpClient::fixture('openai_text');
        Make::gateway('openai', $http)->generate(Make::toolConversation());

        self::assertSame('https://api.openai.com/v1/chat/completions', $http->url());
        self::assertSame(['Authorization' => 'Bearer ' . Make::OPENAI_KEY], $http->headers());

        self::assertEquals([
            'model' => 'gpt-test-2026',
            'messages' => [
                ['role' => 'system', 'content' => 'Be brief.'],
                ['role' => 'user', 'content' => 'Invoice 7?'],
                ['role' => 'assistant', 'content' => 'Checking.', 'tool_calls' => [
                    // arguments is a JSON STRING
                    ['id' => 'c1', 'type' => 'function', 'function' => ['name' => 'get_invoice', 'arguments' => '{"id":"inv-7"}']],
                    ['id' => 'c2', 'type' => 'function', 'function' => ['name' => 'ping', 'arguments' => '{}']],
                ]],
                // one tool message PER result
                ['role' => 'tool', 'tool_call_id' => 'c1', 'content' => '{"total":100}'],
                ['role' => 'tool', 'tool_call_id' => 'c2', 'content' => 'pong'],
            ],
            'tools' => [
                ['type' => 'function', 'function' => ['name' => 'get_invoice', 'description' => 'Fetch one invoice by id.', 'parameters' => self::TOOLS_JSON['get_invoice']]],
                ['type' => 'function', 'function' => ['name' => 'ping', 'description' => 'Check the service is up.', 'parameters' => self::TOOLS_JSON['ping']]],
            ],
            'tool_choice' => 'auto',
            'max_completion_tokens' => 500,
            'temperature' => 0.2,
            'stop' => ['END'],
        ], $http->sent());
    }

    public function test_openai_structured_output_is_strict_json_schema_and_effort_is_reasoning_effort(): void
    {
        $http = RecordingHttpClient::fixture('openai_text');
        Make::gateway('openai', $http)->generate(LlmRequest::prompt('hi')->withSchema(Make::personSchema())->withEffort(Effort::High));
        $sent = $http->sent();

        self::assertSame([
            'type' => 'json_schema',
            'json_schema' => ['name' => 'person', 'schema' => Make::personSchema()->toJsonSchema(), 'strict' => true],
        ], $sent['response_format']);
        self::assertSame('high', $sent['reasoning_effort']);
    }

    public function test_openai_named_tool_choice(): void
    {
        $http = RecordingHttpClient::fixture('openai_text');
        Make::gateway('openai', $http)->generate(new LlmRequest([Message::user('x')], [Make::pingTool()], ToolChoice::tool('ping')));

        self::assertSame(['type' => 'function', 'function' => ['name' => 'ping']], $http->sent()['tool_choice']);
    }

    public function test_openai_parses_text_usage_and_reasoning_tokens(): void
    {
        $r = Make::gateway('openai', RecordingHttpClient::fixture('openai_text'))->generate(LlmRequest::prompt('hi'));

        self::assertSame('Hello there.', $r->text());
        self::assertSame(FinishReason::Stop, $r->finish);
        self::assertSame([11, 4, 2, 1], [$r->usage->input, $r->usage->output, $r->usage->cacheRead, $r->usage->reasoning]);
    }

    public function test_openai_decodes_tool_call_arguments_from_their_json_string(): void
    {
        $r = Make::gateway('openai', RecordingHttpClient::fixture('openai_tool_calls'))->generate(LlmRequest::prompt('hi'));

        self::assertSame(FinishReason::ToolCalls, $r->finish);
        self::assertSame(['id' => 'inv-7'], $r->toolCalls()[0]->arguments);
        self::assertSame('call_abc', $r->toolCalls()[0]->id);
        self::assertSame([], $r->toolCalls()[1]->arguments);
    }

    public function test_openai_refusal_is_a_content_filter_finish_with_the_refusal_text(): void
    {
        $r = Make::gateway('openai', RecordingHttpClient::fixture('openai_refusal'))->generate(LlmRequest::prompt('hi'));

        self::assertSame(FinishReason::ContentFilter, $r->finish);
        self::assertSame("I can't help with that.", $r->text());
    }

    public function test_openai_tool_arguments_that_are_not_json_are_a_gateway_failure_not_a_guess(): void
    {
        $body = str_replace('{\"id\":\"inv-7\"}', 'not json at all', RecordingHttpClient::read('openai_tool_calls'));

        $this->expectException(\AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException::class);
        $this->expectExceptionMessage('arguments are not a JSON object');
        Make::gateway('openai', RecordingHttpClient::answering($body))->generate(LlmRequest::prompt('hi'));
    }

    // ── Gemini ──────────────────────────────────────────────────────────────

    public function test_gemini_builds_the_generate_content_request(): void
    {
        $http = RecordingHttpClient::fixture('gemini_text');
        Make::gateway('gemini', $http)->generate(Make::toolConversation());

        self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/gemini-test-2026:generateContent', $http->url());
        self::assertSame(['x-goog-api-key' => Make::GEMINI_KEY], $http->headers());
        self::assertStringNotContainsString(Make::GEMINI_KEY, $http->url(), 'the key must never be in the URL');

        $obj = static fn (array $props, array $required): array => ['type' => 'OBJECT', 'properties' => $props, 'required' => $required];

        self::assertEquals([
            'systemInstruction' => ['parts' => [['text' => 'Be brief.']]],
            'contents' => [
                ['role' => 'user', 'parts' => [['text' => 'Invoice 7?']]],
                ['role' => 'model', 'parts' => [
                    ['text' => 'Checking.'],
                    ['functionCall' => ['name' => 'get_invoice', 'args' => ['id' => 'inv-7']]],
                    ['functionCall' => ['name' => 'ping', 'args' => []]],
                ]],
                // a result is matched by function NAME, and `response` must be an object
                ['role' => 'user', 'parts' => [
                    ['functionResponse' => ['name' => 'get_invoice', 'response' => ['result' => '{"total":100}']]],
                    ['functionResponse' => ['name' => 'ping', 'response' => ['result' => 'pong']]],
                ]],
            ],
            'tools' => [['functionDeclarations' => [
                ['name' => 'get_invoice', 'description' => 'Fetch one invoice by id.', 'parameters' => $obj(['id' => ['type' => 'STRING']], ['id'])],
                ['name' => 'ping', 'description' => 'Check the service is up.', 'parameters' => $obj([], [])],
            ]]],
            'toolConfig' => ['functionCallingConfig' => ['mode' => 'AUTO']],
            'generationConfig' => ['maxOutputTokens' => 500, 'temperature' => 0.2, 'stopSequences' => ['END']],
        ], $http->sent());
        self::assertStringContainsString('"name":"ping","args":{}', $http->wire());
    }

    public function test_gemini_structured_output_uses_an_openapi_subset_schema(): void
    {
        $http = RecordingHttpClient::fixture('gemini_text');
        Make::gateway('gemini', $http)->generate(LlmRequest::prompt('hi')->withSchema(Make::personSchema())->withEffort(Effort::Low));
        $g = $http->sent()['generationConfig'];

        self::assertSame('application/json', $g['responseMimeType']);
        self::assertSame(
            ['type' => 'OBJECT', 'properties' => ['name' => ['type' => 'STRING'], 'age' => ['type' => 'INTEGER']], 'required' => ['name', 'age']],
            $g['responseSchema'],
        );
        self::assertSame(['thinkingBudget' => 1024], $g['thinkingConfig']);
    }

    public function test_gemini_nullable_types_and_a_forced_tool(): void
    {
        $schema = new \Plugins\Llm\API\Schema('s', ['type' => 'object', 'properties' => ['n' => ['type' => ['string', 'null']]]]);
        $http = RecordingHttpClient::fixture('gemini_text');
        Make::gateway('gemini', $http)->generate(
            (new LlmRequest([Message::user('x')], [Make::pingTool()], ToolChoice::tool('ping')))->withSchema($schema),
        );

        self::assertSame(['type' => 'STRING', 'nullable' => true], $http->sent()['generationConfig']['responseSchema']['properties']['n']);
        self::assertSame(
            ['mode' => 'ANY', 'allowedFunctionNames' => ['ping']],
            $http->sent()['toolConfig']['functionCallingConfig'],
        );
    }

    public function test_gemini_parses_function_calls_and_synthesises_ids(): void
    {
        $r = Make::gateway('gemini', RecordingHttpClient::fixture('gemini_function_call'))->generate(LlmRequest::prompt('hi'));

        // Gemini says STOP for a turn that ends in a function call.
        self::assertSame(FinishReason::ToolCalls, $r->finish);
        self::assertSame(['call_0', 'call_1'], array_map(static fn (ToolCallPart $c): string => $c->id, $r->toolCalls()));
        self::assertSame(['id' => 'inv-7'], $r->toolCalls()[0]->arguments);
        self::assertSame('Checking.', $r->text());
    }

    public function test_gemini_parses_text_usage_and_a_blocked_prompt(): void
    {
        $r = Make::gateway('gemini', RecordingHttpClient::fixture('gemini_text'))->generate(LlmRequest::prompt('hi'));
        self::assertSame([10, 4, 1, 2], [$r->usage->input, $r->usage->output, $r->usage->cacheRead, $r->usage->reasoning]);
        self::assertSame(FinishReason::Stop, $r->finish);

        $blocked = Make::gateway('gemini', RecordingHttpClient::fixture('gemini_blocked'))->generate(LlmRequest::prompt('hi'));
        self::assertSame(FinishReason::ContentFilter, $blocked->finish);
    }

    public function test_gemini_model_id_is_url_encoded_into_the_path(): void
    {
        $http = RecordingHttpClient::fixture('gemini_text');
        Make::gateway('gemini', $http)->generate(LlmRequest::prompt('hi')->withModel('tuned/x:y'));

        self::assertSame('https://generativelanguage.googleapis.com/v1beta/models/tuned%2Fx%3Ay:generateContent', $http->url());
    }

    // ── Ollama ──────────────────────────────────────────────────────────────

    public function test_ollama_builds_the_chat_request_without_streaming(): void
    {
        $http = RecordingHttpClient::fixture('ollama_text');
        Make::gateway('ollama', $http)->generate(Make::toolConversation());

        self::assertSame('http://127.0.0.1:11434/api/chat', $http->url());
        self::assertSame([], $http->headers(), 'Ollama is unauthenticated: no credential header at all');

        self::assertEquals([
            'model' => 'llama-test',
            'stream' => false,
            'messages' => [
                ['role' => 'system', 'content' => 'Be brief.'],
                ['role' => 'user', 'content' => 'Invoice 7?'],
                // arguments is an OBJECT here, unlike OpenAI's string
                ['role' => 'assistant', 'content' => 'Checking.', 'tool_calls' => [
                    ['function' => ['name' => 'get_invoice', 'arguments' => ['id' => 'inv-7']]],
                    ['function' => ['name' => 'ping', 'arguments' => []]],
                ]],
                ['role' => 'tool', 'tool_name' => 'get_invoice', 'content' => '{"total":100}'],
                ['role' => 'tool', 'tool_name' => 'ping', 'content' => 'pong'],
            ],
            'tools' => [
                ['type' => 'function', 'function' => ['name' => 'get_invoice', 'description' => 'Fetch one invoice by id.', 'parameters' => self::TOOLS_JSON['get_invoice']]],
                ['type' => 'function', 'function' => ['name' => 'ping', 'description' => 'Check the service is up.', 'parameters' => self::TOOLS_JSON['ping']]],
            ],
            'options' => ['num_predict' => 500, 'temperature' => 0.2, 'stop' => ['END']],
        ], $http->sent());
        self::assertStringContainsString('"arguments":{}', $http->wire());
    }

    public function test_ollama_format_carries_the_schema_itself(): void
    {
        $http = RecordingHttpClient::fixture('ollama_text');
        Make::gateway('ollama', $http)->generate(LlmRequest::prompt('hi')->withSchema(Make::personSchema()));

        self::assertSame(Make::personSchema()->toJsonSchema(), $http->sent()['format']);
    }

    public function test_ollama_parses_text_and_tool_calls(): void
    {
        $text = Make::gateway('ollama', RecordingHttpClient::fixture('ollama_text'))->generate(LlmRequest::prompt('hi'));
        self::assertSame('Hello there.', $text->text());
        self::assertSame([14, 6], [$text->usage->input, $text->usage->output]);

        $calls = Make::gateway('ollama', RecordingHttpClient::fixture('ollama_tool_calls'))->generate(LlmRequest::prompt('hi'));
        self::assertSame(FinishReason::ToolCalls, $calls->finish);
        self::assertSame(['call_0', 'call_1'], array_map(static fn (ToolCallPart $c): string => $c->id, $calls->toolCalls()));
        self::assertSame(['id' => 'inv-7'], $calls->toolCalls()[0]->arguments);
    }

    public function test_ollama_refuses_effort_and_forced_tools_but_honours_none_by_not_sending_tools(): void
    {
        $http = RecordingHttpClient::fixture('ollama_text');
        $gw = Make::gateway('ollama', $http);

        foreach ([
            new LlmRequest([Message::user('x')], effort: Effort::High),
            new LlmRequest([Message::user('x')], [Make::pingTool()], ToolChoice::required()),
            new LlmRequest([Message::user('x')], [Make::pingTool()], ToolChoice::tool('ping')),
        ] as $request) {
            try {
                $gw->generate($request);
                self::fail('not refused');
            } catch (LlmException $e) {
                self::assertStringContainsString('cannot honour', $e->getMessage());
            }
        }
        self::assertSame([], $http->calls);

        $gw->generate(new LlmRequest([Message::user('x')], [Make::pingTool()], ToolChoice::none()));
        self::assertArrayNotHasKey('tools', $http->sent());
    }

    // ── all of them ─────────────────────────────────────────────────────────

    #[DataProvider('providers')]
    public function test_every_call_asks_the_http_client_for_its_hardening(string $provider, bool $blockPrivate): void
    {
        $http = RecordingHttpClient::fixture($provider . '_text');
        Make::gateway($provider, $http)->generate(LlmRequest::prompt('hi'));
        $o = $http->calls[0]['options'];

        self::assertSame($blockPrivate, $o['block_private'], 'SSRF guard');
        self::assertSame(0, $o['retry'], 'a completion costs money and is not idempotent');
        self::assertSame(1_000_000, $o['max_response_bytes']);
        self::assertSame(30, $o['timeout']);
        self::assertArrayNotHasKey('stream', $o, 'HttpClientPort cannot stream, and nothing here pretends it can');
    }

    public static function providers(): iterable
    {
        yield 'anthropic' => ['anthropic', true];
        yield 'openai' => ['openai', true];
        yield 'gemini' => ['gemini', true];
        yield 'ollama (a local server IS a private address)' => ['ollama', false];
    }

    #[DataProvider('providers')]
    public function test_a_per_request_model_overrides_the_configured_one(string $provider, bool $unused): void
    {
        $http = RecordingHttpClient::fixture($provider . '_text');
        Make::gateway($provider, $http)->generate(LlmRequest::prompt('hi')->withModel('other-model-1'));

        self::assertStringContainsString('other-model-1', $provider === 'gemini' ? $http->url() : $http->wire());
    }

    public function test_the_base_url_is_configurable_and_a_trailing_slash_is_dropped(): void
    {
        $http = RecordingHttpClient::fixture('anthropic_text');
        Make::gateway('anthropic', $http, ['LLM_ANTHROPIC_BASE_URL' => 'https://llm-proxy.example.com/'])->generate(LlmRequest::prompt('hi'));

        self::assertSame('https://llm-proxy.example.com/v1/messages', $http->url());
    }
}
