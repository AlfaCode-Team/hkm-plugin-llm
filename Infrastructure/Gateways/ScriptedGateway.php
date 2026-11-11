<?php

declare(strict_types=1);

namespace Plugins\Llm\Infrastructure\Gateways;

use Plugins\Llm\API\FinishReason;
use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\LlmResponse;
use Plugins\Llm\API\Message;
use Plugins\Llm\API\Parts\Part;
use Plugins\Llm\API\Parts\TextPart;
use Plugins\Llm\API\Parts\ToolCallPart;
use Plugins\Llm\API\ProviderInfo;
use Plugins\Llm\API\Schema;
use Plugins\Llm\API\Usage;
use Plugins\Llm\Application\Ports\ModelGateway;

/**
 * A deterministic provider: it returns what it was told to, in order, and
 * records every request it was asked.
 *
 * Shipped (not hidden under tests/) because a consumer's own tests need to
 * drive the tool loop and the structured-output path without a network. It is
 * never selected by configuration — you construct it and hand it to a
 * `GatewayRegistry`.
 *
 * It does no schema work of its own: like every real gateway it returns text
 * and leaves validation to the service, so a test that feeds it a bad JSON
 * string exercises the same path a bad model would.
 */
final class ScriptedGateway implements ModelGateway
{
    /** @var list<LlmRequest> every request received, oldest first */
    public array $requests = [];

    /** @var list<LlmResponse|\Closure(LlmRequest): LlmResponse> */
    private array $script = [];

    public function __construct(
        private readonly string $name = 'scripted',
        private readonly ?string $cannotConstrain = null,
    ) {}

    /** @param list<Part>|string $content */
    public function then(string|array $content, FinishReason $finish = FinishReason::Stop, ?Usage $usage = null): self
    {
        $this->script[] = new LlmResponse($this->name, 'scripted-model', Message::assistant($content), $finish, $usage ?? new Usage(1, 1));

        return $this;
    }

    /** @param array<string, mixed> $arguments */
    public function thenCall(string $tool, array $arguments = [], string $id = 'call_1'): self
    {
        return $this->then([new ToolCallPart($id, $tool, $arguments)], FinishReason::ToolCalls);
    }

    /** @param \Closure(LlmRequest): LlmResponse $responder */
    public function thenRespond(\Closure $responder): self
    {
        $this->script[] = $responder;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function describe(): ProviderInfo
    {
        return new ProviderInfo($this->name, 'scripted-model', 'localhost', [
            'structured_output' => $this->cannotConstrain === null, 'tool_use' => true, 'effort' => true, 'required_tool_choice' => true,
        ]);
    }

    public function cannotConstrain(Schema $schema): ?string
    {
        return $this->cannotConstrain;
    }

    public function generate(LlmRequest $request): LlmResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->script)
            ?? throw new \LogicException('ScriptedGateway was asked for more responses than were scripted.');

        return $next instanceof \Closure ? $next($request) : $next;
    }
}
