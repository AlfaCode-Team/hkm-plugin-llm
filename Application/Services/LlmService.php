<?php

declare(strict_types=1);

namespace Plugins\Llm\Application\Services;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Llm\API\Contracts\LlmContract;
use Plugins\Llm\API\FinishReason;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\LlmResponse;
use Plugins\Llm\API\LlmRun;
use Plugins\Llm\API\Message;
use Plugins\Llm\API\Schema;
use Plugins\Llm\API\Step;
use Plugins\Llm\API\StructuredFallback;
use Plugins\Llm\API\StructuredMode;
use Plugins\Llm\API\Tools\ToolBox;
use Plugins\Llm\API\Tools\ToolOutcome;
use Plugins\Llm\API\Usage;
use Plugins\Llm\Application\Exceptions\LlmException;
use Plugins\Llm\Application\Ports\ModelGateway;

/**
 * Provider-neutral inference.
 *
 * No transaction, no collector and no event dispatch: nothing here persists
 * and nothing is emitted, so the mandatory begin/commit/discard shape has no
 * state to protect. If a consumer stores prompts or completions, that write
 * is the consumer's, and it follows the shape there.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * TWO RULES THAT HOLD FOR EVERY PROVIDER, SO THEY LIVE HERE AND NOT IN A GATEWAY
 * ════════════════════════════════════════════════════════════════════════════
 *
 * 1. A schema is honoured natively or refused — or the caller has named the
 *    fallback. A gateway says whether it can constrain a given schema; this
 *    class decides what that means for the request.
 *
 * 2. Structured output is VALIDATED here, always. "The provider constrains its
 *    decoding" is the provider's claim; "this value satisfies the schema" is
 *    checked. A caller receives a validated `output` or an exception, never a
 *    string it must hope is JSON.
 */
final class LlmService implements LlmContract
{
    private const MAX_STEPS = 50;

    public function __construct(
        private readonly GatewayRegistry $gateways,
        private readonly Identity $identity,
        private readonly ?LoggerPort $logger = null,
    ) {}

    public function complete(LlmRequest $request, ?string $provider = null): LlmResponse
    {
        $gateway = $this->gateways->get($provider);

        [$outgoing, $mode] = $this->applySchemaPolicy($gateway, $request);

        $response = $gateway->generate($outgoing);

        $this->logger?->debug('llm.completed', [
            'provider' => $response->provider,
            'model' => $response->model,
            'finish' => $response->finish->value,
            'input_tokens' => $response->usage->input,
            'output_tokens' => $response->usage->output,
        ]);

        return $mode === null || $request->schema === null
            ? $response
            : $this->validateOutput($response, $request->schema, $mode);
    }

    public function run(LlmRequest $request, ToolBox $tools, ?string $provider = null, int $maxSteps = 8): LlmRun
    {
        if ($maxSteps < 1 || $maxSteps > self::MAX_STEPS) {
            throw LlmException::invalidRequest('maxSteps must be between 1 and ' . self::MAX_STEPS . '.');
        }

        // What the model is told about is what can run — one list.
        $base = $request->withTools($tools->definitions(), $request->toolChoice);

        $transcript = [];
        $steps = [];
        $usage = new Usage();
        $finished = false;

        for ($n = 1; ; $n++) {
            // A forced tool choice applies to the FIRST call only. Forced on
            // every step it would be forced again after the result came back,
            // and the loop could end only at $maxSteps.
            $current = $n === 1 ? $base : $base->withTools($tools->definitions(), null);
            $response = $this->complete($current->withMessages([...$request->messages, ...$transcript]), $provider);

            $usage = $usage->add($response->usage);
            $transcript[] = $response->message;
            $calls = $response->toolCalls();

            if ($calls === []) {
                $steps[] = new Step($response);
                $finished = true;
                break;
            }

            if ($response->finish === FinishReason::Length) {
                // A tool call cut off by the token limit has truncated
                // arguments. Validation would usually catch it; "usually" is
                // not a reason to run it.
                throw LlmException::invalidRequest(
                    'The model was cut off by the token limit in the middle of a tool call; nothing was executed. Raise maxTokens.',
                );
            }

            if ($n >= $maxSteps) {
                $steps[] = new Step($response); // pending calls are NOT run
                break;
            }

            $executions = [];
            foreach ($calls as $call) {
                $execution = $tools->execute($call, $this->identity);
                $executions[] = $execution;

                if ($execution->outcome !== ToolOutcome::Executed) {
                    // Arguments are not logged: they are model output and may
                    // carry whatever the conversation held.
                    $this->logger?->warning('llm.tool.' . $execution->outcome->value, [
                        'tool' => $call->name,
                        'reason' => $execution->reason,
                        'user' => $this->identity->userId,
                        'tenant' => $this->identity->tenantId,
                    ]);
                }
            }

            $transcript[] = Message::toolResults(array_map(static fn ($e) => $e->result, $executions));
            $steps[] = new Step($response, $executions);
        }

        return new LlmRun($steps, $transcript, $finished, $usage);
    }

    public function providers(): array
    {
        return $this->gateways->describe();
    }

    // ── structured output ───────────────────────────────────────────────────

    /** @return array{0: LlmRequest, 1: ?StructuredMode} */
    private function applySchemaPolicy(ModelGateway $gateway, LlmRequest $request): array
    {
        $schema = $request->schema;
        if ($schema === null) {
            return [$request, null];
        }

        $why = $gateway->cannotConstrain($schema);
        if ($why === null) {
            return [$request, StructuredMode::Native];
        }

        if ($request->structuredFallback !== StructuredFallback::PromptAndValidate) {
            throw LlmException::schemaNotHonoured($gateway->name(), $why);
        }

        $instruction = "Respond with a single JSON value and nothing else: no prose, no code fence.\n"
            . "It must conform to this JSON Schema:\n"
            . json_encode($schema->toJsonSchema(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return [
            $request->withSchema(null)->withMessages([Message::system($instruction), ...$request->messages]),
            StructuredMode::Prompted,
        ];
    }

    private function validateOutput(LlmResponse $response, Schema $schema, StructuredMode $mode): LlmResponse
    {
        // The model wants tools run first; there is no answer to check yet.
        if ($response->toolCalls() !== []) {
            return $response;
        }

        $raw = $response->text();

        if ($response->finish === FinishReason::Length) {
            throw LlmException::invalidOutput($response->provider, 'the response was cut off by the token limit', [], $raw);
        }
        if ($response->finish === FinishReason::ContentFilter) {
            throw LlmException::invalidOutput($response->provider, 'the provider declined to answer', [], $raw);
        }

        $json = trim($raw);
        if ($mode === StructuredMode::Prompted) {
            $json = self::stripFence($json);
        }

        try {
            $decoded = json_decode($json, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw LlmException::invalidOutput($response->provider, 'not valid JSON (' . $e->getMessage() . ')', [], $raw);
        }

        // The check that makes `output` mean something, in EVERY mode — see the class docblock.
        $violations = $schema->validate($decoded);
        if ($violations !== []) {
            throw LlmException::invalidOutput(
                $response->provider,
                'does not match the schema: ' . implode('; ', \array_slice($violations, 0, 5)),
                $violations,
                $raw,
            );
        }

        return $response->withOutput($mode, $decoded);
    }

    private static function stripFence(string $text): string
    {
        if (preg_match('/\A```[A-Za-z]*\R(.*?)\R?```\z/s', $text, $m) === 1) {
            return trim($m[1]);
        }

        return $text;
    }
}
