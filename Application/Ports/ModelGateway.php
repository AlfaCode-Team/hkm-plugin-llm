<?php

declare(strict_types=1);

namespace Plugins\Llm\Application\Ports;

use Plugins\Llm\API\LlmRequest;
use Plugins\Llm\API\LlmResponse;
use Plugins\Llm\API\ProviderInfo;
use Plugins\Llm\API\Schema;

/**
 * One provider, behind the neutral vocabulary. Implemented in
 * Infrastructure/Gateways; the service only ever sees this.
 */
interface ModelGateway
{
    public function name(): string;

    /** Credential-free by construction. */
    public function describe(): ProviderInfo;

    /**
     * Whether this provider will CONSTRAIN its output to $schema — not merely
     * be told about it. The question is per schema because it depends on the
     * schema: a provider that constrains decoding only accepts closed schemas.
     *
     * @return string|null null = yes; otherwise the reason it cannot
     */
    public function cannotConstrain(Schema $schema): ?string;

    /**
     * One call. Throws {@see \Plugins\Llm\Application\Exceptions\LlmException}
     * for a request this provider cannot honour (before anything is sent), and
     * {@see \AlfacodeTeam\PhpServicePlatform\Kernel\Exceptions\GatewayException}
     * for every provider or transport failure — no other exception type
     * leaves a gateway.
     *
     * The returned response carries NO structured output: validating and
     * decoding it is the service's job, identically for every provider.
     */
    public function generate(LlmRequest $request): LlmResponse;
}
