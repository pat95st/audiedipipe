<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http;

/**
 * HTTP response. Body may be a string or a callable that streams output.
 */
final class Response
{
    /** @var callable|null */
    private $stream;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        public string $body = '',
        public int $status = 200,
        public array $headers = [],
    ) {
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }

    public static function json(mixed $data, int $status = 200): self
    {
        return new self(
            (string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            $status,
            ['Content-Type' => 'application/json; charset=utf-8'],
        );
    }

    public static function redirect(string $location, int $status = 302): self
    {
        return new self('', $status, ['Location' => $location]);
    }

    public static function text(string $text, int $status = 200, string $contentType = 'text/plain; charset=utf-8'): self
    {
        return new self($text, $status, ['Content-Type' => $contentType]);
    }

    public static function notFound(string $message = 'Not found'): self
    {
        return self::text($message, 404);
    }

    public static function forbidden(string $message = 'Forbidden'): self
    {
        return self::text($message, 403);
    }

    /**
     * Stream a local file with HTTP range support (needed for audio seeking).
     */
    public static function file(string $path, string $contentType, ?string $rangeHeader = null, ?string $downloadName = null): self
    {
        if (!is_file($path)) {
            return self::notFound();
        }
        $size = (int) filesize($path);
        $start = 0;
        $end = $size - 1;
        $status = 200;
        if ($rangeHeader !== null && preg_match('/bytes=(\d*)-(\d*)/', $rangeHeader, $m) === 1) {
            if ($m[1] === '' && $m[2] !== '') {
                $start = max(0, $size - (int) $m[2]);
            } else {
                $start = (int) $m[1];
                if ($m[2] !== '') {
                    $end = min($end, (int) $m[2]);
                }
            }
            if ($start > $end || $start >= $size) {
                return new self('', 416, ['Content-Range' => 'bytes */' . $size]);
            }
            $status = 206;
        }
        $length = $end - $start + 1;
        $headers = [
            'Content-Type' => $contentType,
            'Accept-Ranges' => 'bytes',
            'Content-Length' => (string) $length,
            'Cache-Control' => 'private, max-age=0',
        ];
        if ($status === 206) {
            $headers['Content-Range'] = sprintf('bytes %d-%d/%d', $start, $end, $size);
        }
        if ($downloadName !== null) {
            $headers['Content-Disposition'] = 'attachment; filename="' . addcslashes(basename($downloadName), '"\\') . '"';
        }
        $response = new self('', $status, $headers);
        $response->stream = static function () use ($path, $start, $length): void {
            $handle = fopen($path, 'rb');
            if ($handle === false) {
                return;
            }
            fseek($handle, $start);
            $remaining = $length;
            while ($remaining > 0 && !feof($handle)) {
                $chunk = fread($handle, (int) min(65536, $remaining));
                if ($chunk === false) {
                    break;
                }
                echo $chunk;
                $remaining -= strlen($chunk);
                flush();
            }
            fclose($handle);
        };

        return $response;
    }

    public function withHeader(string $name, string $value): self
    {
        $clone = clone $this;
        $clone->headers[$name] = $value;

        return $clone;
    }

    public function isStream(): bool
    {
        return $this->stream !== null;
    }

    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
            if ($this->stream === null && !isset($this->headers['Content-Length'])) {
                header('Content-Length: ' . strlen($this->body));
            }
        }
        if ($this->stream !== null) {
            ($this->stream)();

            return;
        }
        echo $this->body;
        if (ob_get_level() > 0) {
            @ob_end_flush();
        }
        flush();
    }
}
