<?php

declare(strict_types=1);

namespace Plugins\Llm\API\Contracts;

use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\LlmResponse;
use Plugins\Llm\API\LlmRun;
use Plugins\Llm\API\ProviderInfo;
use Plugins\Llm\API\Tools\ToolBox;

/**
 * Published contract for `llm.inference`.
 *
 * This is a PLUGIN contract, deliberately not a kernel port: the surface of
 * model APIs is still moving (tool use, reasoning, caching, batch), and a kernel
 * port is a promise the kernel then has to keep. It stays here until a second
 * consumer shows which parts of the shape are real.
 *
 * Not offered: streaming. See the README for why that is a property of
 * `HttpClientPort` and not an omission.
 */
interface LlmContract
{
    /**
     * ONE model call. Never runs a tool: if the model asks for any, they come
     * back as tool-call parts on the response and the caller owns what happens
     * next. A caller that executes those itself has taken on the authorization
     * duty {@see ToolBox} otherwise enforces.
     *
     * When `$request->schema` is set the returned response carries the
     * validated `output` and the `structuredMode` it was obtained in.
     *
     * @param ?string $provider a configured provider name; null = the default
     *
     * @throws \Plugins\Llm\Application\Exceptions\LlmException  provider unconfigured, capability unsupported, or output not schema-valid
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException the provider or transport failed
     */
    public function complete(LlmRequest $request, ?string $provider = null): LlmResponse;

    /**
     * The tool loop: call the model, run the tool calls it makes through
     * `$tools` (validated, authorized, then executed), feed the results back,
     * and repeat until the model answers without tools or `$maxSteps` is hit.
     *
     * `$request->tools` is replaced by `$tools->definitions()`: what the model
     * is told about and what can run are one list, so a tool cannot be offered
     * without being governed.
     *
     * @throws \Plugins\Llm\Application\Exceptions\LlmException
     * @throws \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException
     */
    public function run(LlmRequest $request, ToolBox $tools, ?string $provider = null, int $maxSteps = 8): LlmRun;

    /**
     * The configured providers and what each can do. Carries no credential.
     *
     * @return list<ProviderInfo>
     */
    public function providers(): array;
}
