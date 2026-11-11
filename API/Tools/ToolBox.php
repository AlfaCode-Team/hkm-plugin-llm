<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Tools;

use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Llm\API\Parts\ToolCallPart;
use Plugins\Llm\API\Parts\ToolResultPart;
use Plugins\Llm\API\Tool;

/**
 * The tools a run may use, and the only place a model's tool call becomes code
 * running.
 *
 * ════════════════════════════════════════════════════════════════════════════
 * THE SECURITY BOUNDARY
 * ════════════════════════════════════════════════════════════════════════════
 *
 * For every call the model makes, in this order, and stopping at the first
 * failure:
 *
 *   1. the tool must be REGISTERED               else Unknown
 *   2. arguments must match its input schema     else Invalid
 *   3. the {@see ToolAuthorizer} must ALLOW it   else Refused  (fail-closed)
 *   4. the handler runs; an exception is caught  else Failed
 *
 * Steps 1-3 are not optional and cannot be reordered: validation happens
 * first so the authorizer sees well-formed arguments, and the handler is
 * unreachable except through step 3. The constructor demands an authorizer
 * with no default, so a ToolBox without one cannot exist.
 *
 * What the model is told on a failure is generic ("not permitted", "failed")
 * — the specific reason is in {@see ToolExecution::$reason}, for the
 * application. An exception message from a handler is not forwarded: it can
 * carry a SQL fragment, a path or a secret, and the model's output is not a
 * place to put those.
 *
 * What this does NOT do: decide what is safe. A tool that deletes records is
 * as dangerous as the authorizer is permissive. Gate by the caller's
 * permissions, by arguments (which record?), and by the tool's blast radius.
 */
final class ToolBox
{
    /** @var array<string, array{0: Tool, 1: ToolHandler|\Closure}> */
    private array $tools = [];

    public function __construct(
        private readonly ToolAuthorizer $authorizer,
        private readonly int $maxResultBytes = 65536,
    ) {
        if ($maxResultBytes < 256) {
            throw new \InvalidArgumentException('maxResultBytes must be at least 256.');
        }
    }

    /** @param ToolHandler|\Closure(array<string, mixed>, Identity): mixed $handler */
    public function register(Tool $tool, ToolHandler|\Closure $handler): static
    {
        if (isset($this->tools[$tool->name])) {
            throw new \InvalidArgumentException("Tool [{$tool->name}] is already registered.");
        }
        $this->tools[$tool->name] = [$tool, $handler];

        return $this;
    }

    /** @return list<Tool> what the model is told about */
    public function definitions(): array
    {
        return array_values(array_map(static fn (array $t): Tool => $t[0], $this->tools));
    }

    public function execute(ToolCallPart $call, Identity $identity): ToolExecution
    {
        $entry = $this->tools[$call->name] ?? null;
        if ($entry === null) {
            return $this->fail($call, ToolOutcome::Unknown, 'Unknown tool.', 'no tool registered under that name');
        }
        [$tool, $handler] = $entry;

        $errors = $tool->input->validate($call->arguments);
        if ($errors !== []) {
            $detail = mb_strcut(implode('; ', $errors), 0, 500, 'UTF-8');

            return $this->fail($call, ToolOutcome::Invalid, 'Invalid arguments: ' . $detail, $detail);
        }

        try {
            $decision = $this->authorizer->authorize($call, $tool, $identity);
        } catch (\Throwable $e) {
            return $this->fail($call, ToolOutcome::Refused, 'This tool call was not permitted.', 'authorizer threw ' . $e::class);
        }
        if (!$decision instanceof ToolDecision || $decision->allowed !== true) {
            $reason = $decision instanceof ToolDecision ? $decision->reason : 'authorizer returned no decision';

            return $this->fail($call, ToolOutcome::Refused, 'This tool call was not permitted.', $reason);
        }

        try {
            $returned = $handler instanceof ToolHandler
                ? $handler->handle($call->arguments, $identity)
                : $handler($call->arguments, $identity);
            $content = $this->encode($returned);
        } catch (\Throwable $e) {
            return $this->fail($call, ToolOutcome::Failed, 'The tool failed.', $e::class);
        }

        return new ToolExecution($call, ToolOutcome::Executed, new ToolResultPart($call->id, $call->name, $content));
    }

    private function fail(ToolCallPart $call, ToolOutcome $outcome, string $toModel, string $reason): ToolExecution
    {
        return new ToolExecution($call, $outcome, new ToolResultPart($call->id, $call->name, $toModel, true), $reason);
    }

    private function encode(mixed $returned): string
    {
        $content = \is_string($returned)
            ? $returned
            : json_encode($returned, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        if (\strlen($content) > $this->maxResultBytes) {
            $content = mb_strcut($content, 0, $this->maxResultBytes, 'UTF-8') . ' [truncated]';
        }

        return $content;
    }
}
