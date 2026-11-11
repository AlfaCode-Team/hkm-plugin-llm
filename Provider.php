<?php

declare(strict_types=1);

namespace Plugins\Llm;

use AlfacodeTeam\PhpServicePlatform\Kernel\Container\ModuleContainer;
use AlfacodeTeam\PhpServicePlatform\Kernel\Contracts\ModuleContract;
use AlfacodeTeam\PhpServicePlatform\Kernel\Events\EventBus;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Cli\CliPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Http\HttpPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Pipelines\Worker\WorkerPipeline;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\LoggerPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Security\Identity;
use Plugins\Llm\API\Contracts\LlmContract;
use Plugins\Llm\Application\Services\GatewayRegistry;
use Plugins\Llm\Application\Services\LlmService;
use Plugins\Llm\Infrastructure\Cli\ProvidersCommand;
use Plugins\Llm\Infrastructure\Config\LlmConfig;
use Plugins\Llm\Infrastructure\Gateways\GatewayFactory;
use Plugins\Llm\Infrastructure\Support\NoHttpClient;

/**
 * Llm — wires the 'llm.inference' domain.
 *
 * `http.client` IS in requires[]: every real provider call goes through
 * HttpClientPort, so the always-on path needs it, and that is also what puts
 * the client's SSRF and DNS-rebind guard under every request.
 *
 * NO ROUTES. A route that accepted a prompt is an unauthenticated way to spend
 * money and to reach whatever tools are registered.
 *
 * NO JOBS AND NO LISTENERS. Nothing here is queued and nothing subscribes, so
 * there is no job-name registry entry to forget (a manifest `jobs[]` does not
 * register a handler; `$worker->job()` does) and no listener to bind in the
 * wrong scope. A consumer that runs completions in a worker registers its own
 * job and binds it with bind().
 */
final class Provider implements ModuleContract
{
    public function solves(): string
    {
        return 'llm.inference';
    }

    /** @return list<string> */
    public function requires(): array
    {
        return ['http.client'];
    }

    /** @return list<class-string> */
    public function exposes(): array
    {
        return [LlmContract::class];
    }

    public function register(ModuleContainer $container): void
    {
        $container->bindInternal(
            GatewayRegistry::class,
            static fn (ModuleContainer $c): GatewayRegistry => GatewayFactory::registry(
                LlmConfig::fromEnv(),
                static fn (): HttpClientPort => $c->make(HttpClientPort::class),
            ),
        );

        $container->bind(
            LlmContract::class,
            static fn (ModuleContainer $c): LlmService => new LlmService(
                gateways: $c->make(GatewayRegistry::class),
                identity: $c->make(Identity::class),
                logger: $c->has(LoggerPort::class) ? $c->make(LoggerPort::class) : null,
            ),
        );
    }

    public function boot(HttpPipeline $http, CliPipeline $cli, WorkerPipeline $worker, EventBus $events): void
    {
        // Deferred, and an INSTANCE: a command registered by class-string is
        // built from the CoreContainer, where this plugin's bindings do not
        // exist, and a command that cannot be built is silently absent from
        // `list`. It only describes providers, so it gets a client that
        // refuses to send rather than depending on `http.client` being wired.
        $cli->defer(static function (CliPipeline $cli): void {
            $cli->command(new ProvidersCommand(GatewayFactory::registry(
                LlmConfig::fromEnv(),
                static fn (): HttpClientPort => new NoHttpClient(),
            )));
        });
    }
}
