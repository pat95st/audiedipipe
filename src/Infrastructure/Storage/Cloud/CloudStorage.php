<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Http\HttpClient;
use AudiEdiPipe\Infrastructure\Http\HttpResponse;
use AudiEdiPipe\Infrastructure\Storage\StorageAdapter;
use AudiEdiPipe\Infrastructure\Storage\StorageException;
use Closure;
use stdClass;

/**
 * Base class for REST based cloud storage APIs authorised with OAuth 2.0 bearer tokens.
 */
abstract class CloudStorage implements StorageAdapter
{
    /** @var Closure(int): void */
    private Closure $sleep;

    public function __construct(
        protected readonly HttpClient $http,
        protected readonly TokenSession $tokens,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $seconds): void {
            sleep($seconds);
        };
    }

    /**
     * Login / e-mail of the connected account, shown in the UI.
     */
    abstract public function account(): string;

    public function test(string $path): void
    {
        $this->listFiles($path);
    }

    /**
     * Authorised request. Refreshes the access token once on 401 and backs off on rate limits.
     *
     * @param array<string, string>            $headers
     * @param string|array<string, mixed>|null $body
     * @param array<string, mixed>             $options
     */
    protected function call(string $method, string $url, array $headers = [], array|string|null $body = null, array $options = []): HttpResponse
    {
        $refreshed = false;
        for ($attempt = 1; ; ++$attempt) {
            $response = $this->http->request(
                $method,
                $url,
                ['Authorization' => 'Bearer ' . $this->tokens->accessToken()] + $headers,
                $body,
                $options + ['timeout' => 3600],
            );
            if ($response->status === 401 && !$refreshed) {
                $refreshed = true;
                $this->tokens->refresh();

                continue;
            }
            if (in_array($response->status, [429, 503], true) && $attempt < 4) {
                ($this->sleep)($this->retryAfter($response, $attempt));

                continue;
            }

            return $response;
        }
    }

    /**
     * JSON request/response helper; throws on non-2xx responses.
     *
     * @param array<mixed>|null     $payload
     * @param array<string, string> $headers
     *
     * @return array<mixed>
     */
    protected function callJson(string $method, string $url, ?array $payload, string $action, string $path, array $headers = []): array
    {
        $response = $this->call(
            $method,
            $url,
            $payload === null ? $headers : ['Content-Type' => 'application/json'] + $headers,
            $payload === null ? null : (string) json_encode($payload === [] ? new stdClass() : $payload, JSON_UNESCAPED_SLASHES),
        );
        if (!$response->ok()) {
            $this->fail($action, $response, $path);
        }

        return $response->json();
    }

    protected function fail(string $action, HttpResponse $response, string $path): never
    {
        throw new StorageException(sprintf('%s %s failed (%d): %s%s', $this->tokens->provider(), $action, $response->status, $path, self::errorDetail($response)));
    }

    /**
     * Download into a local file, removing partial files on failure.
     *
     * @param array<string, string> $headers
     */
    protected function downloadTo(string $url, string $localPath, string $path, array $headers = [], string $method = 'GET', ?string $body = null): void
    {
        $response = $this->call($method, $url, $headers, $body, ['download_to' => $localPath]);
        if (!$response->ok()) {
            @unlink($localPath);
            $this->fail('download', $response, $path);
        }
    }

    protected static function normalize(string $path): string
    {
        $segments = array_filter(explode('/', str_replace('\\', '/', $path)), static fn (string $s): bool => $s !== '' && $s !== '.');
        if (in_array('..', $segments, true)) {
            throw new StorageException('Invalid path: ' . $path);
        }

        return '/' . implode('/', $segments);
    }

    protected static function join(string $directory, string $name): string
    {
        return rtrim($directory, '/') . '/' . $name;
    }

    protected static function timestamp(mixed $value): int
    {
        $time = is_string($value) && $value !== '' ? strtotime($value) : false;

        return $time === false ? 0 : $time;
    }

    /**
     * Read the next $length bytes of an open file.
     *
     * @param resource $handle
     */
    protected static function readChunk($handle, int $length): string
    {
        $data = stream_get_contents($handle, $length);
        if ($data === false) {
            throw new StorageException('Cannot read local file.');
        }

        return $data;
    }

    /**
     * @return resource
     */
    protected static function open(string $localPath)
    {
        $handle = is_file($localPath) ? fopen($localPath, 'rb') : false;
        if ($handle === false) {
            throw new StorageException('Cannot read ' . $localPath);
        }

        return $handle;
    }

    private function retryAfter(HttpResponse $response, int $attempt): int
    {
        $header = $response->headers['retry-after'] ?? '';

        return max(1, min(30, is_numeric($header) ? (int) $header : 2 ** $attempt));
    }

    private static function errorDetail(HttpResponse $response): string
    {
        $data = $response->json();
        $candidates = [
            $data['error_summary'] ?? null,                 // Dropbox
            $data['error']['message'] ?? null,              // Google, Microsoft Graph
            $data['message'] ?? null,                       // Box
            $data['error_description'] ?? null,
            is_string($data['error'] ?? null) ? $data['error'] : null,
        ];
        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $candidate !== '') {
                return ' - ' . mb_substr($candidate, 0, 300);
            }
        }

        return '';
    }
}
