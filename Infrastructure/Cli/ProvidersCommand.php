<?php

declare(strict_types=1);

namespace Plugins\Llm\Infrastructure\Cli;

use AlfacodeTeam\PhpIoCli\AbstractCommand;
use Plugins\Llm\Application\Services\GatewayRegistry;

/**
 * `llm:providers` — which providers are configured, what each can do, and what
 * the unconfigured ones are missing.
 *
 * It makes no request and needs no network. It prints a model id and a HOST,
 * never a key or a full base URL: the output is meant to be pasted into a
 * ticket.
 */
final class ProvidersCommand extends AbstractCommand
{
    public function __construct(private readonly GatewayRegistry $gateways)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->name = 'llm:providers';
        $this->description = 'List the configured LLM providers and what each can do. Makes no request.';
    }

    protected function handle(): int
    {
        $rows = [];
        foreach ($this->gateways->describe() as $info) {
            $caps = [];
            foreach ($info->capabilities as $name => $supported) {
                $caps[] = ($supported ? '+' : '-') . $name;
            }
            $rows[] = [$info->name, $info->model, $info->host, implode(' ', $caps)];
        }

        if ($rows === []) {
            $this->warning('No LLM provider is configured.');
        } else {
            $this->table()->headers(['Provider', 'Model', 'Host', 'Capabilities'])->rows($rows)->render();
        }

        foreach ($this->gateways->unconfigured() as $name => $missing) {
            $this->muted("{$name}: not configured, missing " . implode(', ', $missing));
        }

        return $rows === [] ? self::FAILURE : self::SUCCESS;
    }
}
