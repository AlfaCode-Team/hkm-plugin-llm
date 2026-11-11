<?php

declare(strict_types=1);

namespace Plugins\Llm\Infrastructure\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientResponse;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\PendingRequestContract;

/**
 * The HTTP client for code that only DESCRIBES providers (`llm:providers`).
 *
 * Describing a provider builds its gateway, and a gateway needs a client. A
 * client that refuses to send is what lets the command run with no network
 * and — importantly — in a CLI process where `http.client` is not wired: the
 * alternative is a command that silently fails to register.
 */
final class NoHttpClient implements HttpClientPort
{
    public function request(string $method, string $url, array $options = []): HttpClientResponse
    {
        throw new \LogicException('This HTTP client only describes providers; it never sends.');
    }

    public function get(string $url, array $query = []): HttpClientResponse
    {
        return $this->request('GET', $url);
    }

    public function post(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('POST', $url);
    }

    public function put(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('PUT', $url);
    }

    public function patch(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('PATCH', $url);
    }

    public function delete(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('DELETE', $url);
    }

    public function pending(): PendingRequestContract
    {
        throw new \LogicException('This HTTP client only describes providers; it never sends.');
    }
}
