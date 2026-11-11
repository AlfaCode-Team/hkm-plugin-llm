<?php

declare(strict_types=1);

namespace Tests\Plugins\Llm\Support;

use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientPort;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\HttpClientResponse;
use AlfacodeTeam\PhpServicePlatform\Kernel\Ports\PendingRequestContract;

/**
 * An HttpClientPort that answers what the test queued and records what it was
 * asked — which is the point. The URL, headers, JSON body and the hardening
 * options (`block_private`, `retry`, `max_response_bytes`) are what a provider
 * adapter is FOR, and they are invisible in any test that only looks at the
 * parsed result. No socket is ever opened.
 */
final class RecordingHttpClient implements HttpClientPort
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    public array $calls = [];

    /** @var list<HttpClientResponse|\Throwable> */
    private array $queue = [];

    public static function answering(string $body, int $status = 200): self
    {
        return (new self())->queue($body, $status);
    }

    public static function fixture(string $name, int $status = 200): self
    {
        return self::answering(self::read($name), $status);
    }

    public static function read(string $name): string
    {
        return (string) file_get_contents(__DIR__ . '/../fixtures/' . $name . '.json');
    }

    public function queue(string $body, int $status = 200): self
    {
        $this->queue[] = new HttpClientResponse($status, $body, ['content-type' => 'application/json']);

        return $this;
    }

    public function queueFixture(string $name, int $status = 200): self
    {
        return $this->queue(self::read($name), $status);
    }

    public function failWith(\Throwable $e): self
    {
        $this->queue[] = $e;

        return $this;
    }

    /** @return array<string, mixed> the body the last call sent, decoded */
    public function sent(): array
    {
        $json = $this->calls[\count($this->calls) - 1]['options']['json'] ?? null;

        return (array) json_decode((string) json_encode($json), true);
    }

    /** The last call's JSON body exactly as it would be put on the wire. */
    public function wire(): string
    {
        return (string) json_encode($this->calls[\count($this->calls) - 1]['options']['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** @return array<string, string> */
    public function headers(): array
    {
        return $this->calls[\count($this->calls) - 1]['options']['headers'] ?? [];
    }

    public function url(): string
    {
        return $this->calls[\count($this->calls) - 1]['url'];
    }

    public function request(string $method, string $url, array $options = []): HttpClientResponse
    {
        $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

        $next = array_shift($this->queue) ?? throw new \LogicException('RecordingHttpClient has no queued response.');
        if ($next instanceof \Throwable) {
            throw $next;
        }

        return $next;
    }

    public function get(string $url, array $query = []): HttpClientResponse
    {
        return $this->request('GET', $url, ['query' => $query]);
    }

    public function post(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('POST', $url, ['json' => $data]);
    }

    public function put(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('PUT', $url, ['json' => $data]);
    }

    public function patch(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('PATCH', $url, ['json' => $data]);
    }

    public function delete(string $url, array $data = []): HttpClientResponse
    {
        return $this->request('DELETE', $url, ['json' => $data]);
    }

    public function pending(): PendingRequestContract
    {
        throw new \LogicException('Nothing in this plugin uses the fluent builder.');
    }
}
