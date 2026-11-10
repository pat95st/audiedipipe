<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http;

/**
 * Immutable HTTP request value object.
 */
final class Request
{
    /** @var array<string, string> */
    private array $attributes = [];

    /**
     * @param array<string, mixed>  $query
     * @param array<string, mixed>  $post
     * @param array<string, mixed>  $files
     * @param array<string, string> $headers  lower-case header names
     * @param array<string, mixed>  $server
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly array $server = [],
    ) {
    }

    public static function fromGlobals(string $basePath = ''): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $path = '/' . ltrim($path, '/');
        if ($path !== '/') {
            $path = rtrim($path, '/');
        }

        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }

        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $body = '';
        if (in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            $contentType = $headers['content-type'] ?? '';
            if (!str_starts_with($contentType, 'multipart/form-data')) {
                $body = (string) file_get_contents('php://input');
            }
        }

        /** @var array<string, mixed> $query */
        $query = $_GET;
        /** @var array<string, mixed> $post */
        $post = $_POST;
        /** @var array<string, mixed> $files */
        $files = $_FILES;
        /** @var array<string, mixed> $server */
        $server = $_SERVER;

        return new self($method, $path, $query, $post, $files, $headers, $body, $server);
    }

    public function withAttribute(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->attributes[$name] = $value;

        return $clone;
    }

    public function attribute(string $name, string $default = ''): string
    {
        return $this->attributes[$name] ?? $default;
    }

    public function intAttribute(string $name): int
    {
        return (int) ($this->attributes[$name] ?? 0);
    }

    public function input(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, $this->post)) {
            return $this->post[$key];
        }
        $json = $this->json();
        if (array_key_exists($key, $json)) {
            return $json[$key];
        }

        return $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->input($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key): bool
    {
        $value = $this->input($key, false);

        return in_array($value, [true, 1, '1', 'on', 'true', 'yes'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        static $cache = [];
        if ($this->body === '') {
            return [];
        }
        $hash = md5($this->body);
        if (!array_key_exists($hash, $cache)) {
            $decoded = json_decode($this->body, true);
            $cache[$hash] = is_array($decoded) ? $decoded : [];
        }

        /** @var array<string, mixed> */
        return $cache[$hash];
    }

    public function header(string $name, string $default = ''): string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function isJson(): bool
    {
        return str_contains($this->header('content-type'), 'application/json')
            || str_contains($this->header('accept'), 'application/json');
    }

    /**
     * @return array{name: string, type: string, tmp_name: string, error: int, size: int}|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;
        if (!is_array($file) || !isset($file['tmp_name']) || is_array($file['tmp_name'])) {
            return null;
        }

        return [
            'name' => (string) ($file['name'] ?? ''),
            'type' => (string) ($file['type'] ?? ''),
            'tmp_name' => (string) $file['tmp_name'],
            'error' => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($file['size'] ?? 0),
        ];
    }

    public function isSecure(): bool
    {
        $https = (string) ($this->server['HTTPS'] ?? '');

        return ($https !== '' && $https !== 'off') || $this->header('x-forwarded-proto') === 'https';
    }

    public function host(): string
    {
        $host = $this->header('host', (string) ($this->server['SERVER_NAME'] ?? 'localhost'));

        return (string) preg_replace('/[^a-zA-Z0-9.:\-\[\]]/', '', $host);
    }

    public function origin(): string
    {
        return ($this->isSecure() ? 'https' : 'http') . '://' . $this->host();
    }

    public function clientIp(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }
}
