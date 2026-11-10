<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

use AudiEdiPipe\Infrastructure\Http\HttpClient;
use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * WebDAV (RFC 4918) storage. Paths are relative to the configured base URL.
 */
class WebDavStorage implements StorageAdapter
{
    public function __construct(
        protected readonly HttpClient $http,
        protected readonly string $baseUrl,
        protected readonly string $username,
        protected readonly string $password,
        protected readonly bool $verifyTls = true,
    ) {
    }

    public function listFiles(string $path, bool $recursive = false): array
    {
        $response = $this->http->request('PROPFIND', $this->url($path, true), [
            'Depth' => '1',
            'Content-Type' => 'application/xml; charset=utf-8',
        ], '<?xml version="1.0"?><d:propfind xmlns:d="DAV:"><d:prop><d:getcontentlength/><d:getlastmodified/><d:resourcetype/></d:prop></d:propfind>', $this->options());
        if ($response->status !== 207) {
            throw new StorageException(sprintf('WebDAV PROPFIND failed (%d) for %s', $response->status, $path));
        }
        $basePath = (string) parse_url($this->baseUrl, PHP_URL_PATH);
        $files = [];
        foreach (self::parseMultiStatus($response->body) as $entry) {
            $relative = self::relativePath($entry['href'], $basePath);
            if (rtrim($relative, '/') === rtrim('/' . ltrim($path, '/'), '/')) {
                continue; // the directory itself
            }
            if ($entry['dir']) {
                if ($recursive) {
                    array_push($files, ...$this->listFiles($relative, true));
                }
                continue;
            }
            $files[] = new RemoteFile($relative, $entry['size'], $entry['mtime']);
        }

        return $files;
    }

    /**
     * @return list<array{href: string, size: int, mtime: int, dir: bool}>
     */
    public static function parseMultiStatus(string $xml): array
    {
        $doc = new DOMDocument();
        if (@$doc->loadXML($xml, LIBXML_NONET) === false) {
            throw new StorageException('Invalid WebDAV response.');
        }
        $xpath = new DOMXPath($doc);
        $xpath->registerNamespace('d', 'DAV:');
        $result = [];
        $responses = $xpath->query('//d:response');
        if ($responses === false) {
            return [];
        }
        foreach ($responses as $response) {
            if (!$response instanceof DOMElement) {
                continue;
            }
            $href = rawurldecode(trim((string) $xpath->evaluate('string(d:href)', $response)));
            $size = (int) $xpath->evaluate('string(d:propstat/d:prop/d:getcontentlength)', $response);
            $modified = trim((string) $xpath->evaluate('string(d:propstat/d:prop/d:getlastmodified)', $response));
            $isDir = (float) $xpath->evaluate('count(d:propstat/d:prop/d:resourcetype/d:collection)', $response) > 0;
            $mtime = $modified !== '' ? strtotime($modified) : false;
            $result[] = ['href' => $href, 'size' => $size, 'mtime' => $mtime === false ? 0 : $mtime, 'dir' => $isDir];
        }

        return $result;
    }

    public function download(string $remotePath, string $localPath): void
    {
        $response = $this->http->request('GET', $this->url($remotePath), [], null, $this->options() + ['download_to' => $localPath]);
        if (!$response->ok()) {
            @unlink($localPath);
            throw new StorageException(sprintf('WebDAV download failed (%d): %s', $response->status, $remotePath));
        }
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $this->mkcol(dirname($remotePath));
        $response = $this->http->request('PUT', $this->url($remotePath), [], null, $this->options() + ['upload_file' => $localPath]);
        if (!$response->ok()) {
            throw new StorageException(sprintf('WebDAV upload failed (%d): %s', $response->status, $remotePath));
        }
    }

    public function delete(string $remotePath): void
    {
        $response = $this->http->request('DELETE', $this->url($remotePath), [], null, $this->options());
        if (!$response->ok() && $response->status !== 404) {
            throw new StorageException(sprintf('WebDAV delete failed (%d): %s', $response->status, $remotePath));
        }
    }

    public function test(string $path): void
    {
        $this->listFiles($path);
    }

    protected function url(string $path, bool $directory = false): string
    {
        $segments = array_map('rawurlencode', array_filter(explode('/', $path), static fn (string $s): bool => $s !== ''));
        $url = rtrim($this->baseUrl, '/') . '/' . implode('/', $segments);

        return $directory ? rtrim($url, '/') . '/' : $url;
    }

    /**
     * @return array<string, mixed>
     */
    protected function options(): array
    {
        return ['auth' => [$this->username, $this->password], 'verify' => $this->verifyTls];
    }

    private function mkcol(string $dir): void
    {
        $current = '';
        foreach (array_filter(explode('/', $dir), static fn (string $s): bool => $s !== '' && $s !== '.') as $segment) {
            $current .= '/' . $segment;
            $this->http->request('MKCOL', $this->url($current, true), [], null, $this->options());
        }
    }

    private static function relativePath(string $href, string $basePath): string
    {
        $path = (string) (parse_url($href, PHP_URL_PATH) ?? $href);
        $basePath = rtrim(rawurldecode($basePath), '/');
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }

        return '/' . ltrim($path, '/');
    }
}
