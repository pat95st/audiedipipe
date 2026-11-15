<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Support;

use AudiEdiPipe\Infrastructure\Http\HttpClient;
use AudiEdiPipe\Infrastructure\Http\HttpResponse;
use RuntimeException;

/**
 * Scripted HTTP client: responses are returned in the order they were queued
 * and every request is recorded for assertions.
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: string|array<string, mixed>|null, options: array<string, mixed>}> */
    public array $requests = [];

    /** @var list<array{HttpResponse, ?string}> */
    private array $queue = [];

    /**
     * @param array<mixed>|string   $body    arrays are JSON encoded
     * @param array<string, string> $headers
     * @param string|null           $file    content written to the download_to target
     */
    public function queue(int $status, array|string $body = '', array $headers = [], ?string $file = null): self
    {
        $this->queue[] = [new HttpResponse($status, is_array($body) ? (string) json_encode($body) : $body, $headers), $file];

        return $this;
    }

    public function request(string $method, string $url, array $headers = [], array|string|null $body = null, array $options = []): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'options' => $options];
        $next = array_shift($this->queue);
        if ($next === null) {
            throw new RuntimeException(sprintf('Unexpected request: %s %s', $method, $url));
        }
        [$response, $file] = $next;
        if (isset($options['download_to']) && is_string($options['download_to'])) {
            file_put_contents($options['download_to'], $file ?? $response->body);
        }

        return $response;
    }

    public function remaining(): int
    {
        return count($this->queue);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonBody(int $index): array
    {
        $body = $this->requests[$index]['body'] ?? null;
        $data = is_string($body) ? json_decode($body, true) : null;

        return is_array($data) ? $data : [];
    }
}
