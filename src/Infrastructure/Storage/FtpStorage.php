<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

use FTP\Connection;

/**
 * FTP / explicit FTPS (ext-ftp).
 */
final class FtpStorage implements StorageAdapter
{
    private ?Connection $connection = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $ssl = false,
        private readonly bool $passive = true,
    ) {
    }

    public function __destruct()
    {
        if ($this->connection !== null) {
            @ftp_close($this->connection);
        }
    }

    public function listFiles(string $path, bool $recursive = false): array
    {
        $ftp = $this->connect();
        $files = [];
        $entries = @ftp_mlsd($ftp, $path);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                $name = (string) ($entry['name'] ?? '');
                $type = strtolower((string) ($entry['type'] ?? ''));
                if ($name === '' || $type === 'cdir' || $type === 'pdir') {
                    continue;
                }
                $full = rtrim($path, '/') . '/' . $name;
                if ($type === 'dir') {
                    if ($recursive) {
                        array_push($files, ...$this->listFiles($full, true));
                    }

                    continue;
                }
                $modify = (string) ($entry['modify'] ?? '');
                $mtime = $modify !== '' ? (int) strtotime(substr($modify, 0, 14) . ' UTC') : 0;
                $files[] = new RemoteFile($full, (int) ($entry['size'] ?? 0), $mtime);
            }

            return $files;
        }
        $raw = @ftp_rawlist($ftp, $path);
        if ($raw === false) {
            throw new StorageException('Cannot list FTP directory ' . $path);
        }
        foreach (self::parseRawList(array_values($raw), time()) as $entry) {
            $full = rtrim($path, '/') . '/' . $entry['name'];
            if ($entry['dir']) {
                if ($recursive) {
                    array_push($files, ...$this->listFiles($full, true));
                }

                continue;
            }
            $files[] = new RemoteFile($full, $entry['size'], $entry['mtime']);
        }

        return $files;
    }

    /**
     * Parse UNIX style "ls -l" output as returned by most FTP servers.
     *
     * @param list<string> $lines
     *
     * @return list<array{name: string, size: int, mtime: int, dir: bool}>
     */
    public static function parseRawList(array $lines, int $now): array
    {
        $result = [];
        $pattern = '/^([\-dlrwxsStT]{10})\s+\d+\s+\S+\s+\S+\s+(\d+)\s+(\w{3})\s+(\d{1,2})\s+(\d{1,2}:\d{2}|\d{4})\s+(.+)$/';
        foreach ($lines as $line) {
            if (preg_match($pattern, trim($line), $m) !== 1) {
                continue;
            }
            $name = $m[6];
            if ($name === '.' || $name === '..') {
                continue;
            }
            if (str_contains($m[5], ':')) {
                $year = (int) gmdate('Y', $now);
                $time = strtotime(sprintf('%s %s %d %s UTC', $m[4], $m[3], $year, $m[5]));
                if ($time !== false && $time > $now + 86400) {
                    $time = strtotime(sprintf('%s %s %d %s UTC', $m[4], $m[3], $year - 1, $m[5]));
                }
            } else {
                $time = strtotime(sprintf('%s %s %s UTC', $m[4], $m[3], $m[5]));
            }
            $result[] = [
                'name' => $m[1][0] === 'l' ? explode(' -> ', $name)[0] : $name,
                'size' => (int) $m[2],
                'mtime' => $time === false ? 0 : $time,
                'dir' => $m[1][0] === 'd',
            ];
        }

        return $result;
    }

    public function download(string $remotePath, string $localPath): void
    {
        if (!@ftp_get($this->connect(), $localPath, $remotePath, FTP_BINARY)) {
            throw new StorageException('FTP download failed: ' . $remotePath);
        }
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $ftp = $this->connect();
        $this->mkdirs($ftp, dirname($remotePath));
        if (!@ftp_put($ftp, $remotePath, $localPath, FTP_BINARY)) {
            throw new StorageException('FTP upload failed: ' . $remotePath);
        }
    }

    public function delete(string $remotePath): void
    {
        if (!@ftp_delete($this->connect(), $remotePath)) {
            throw new StorageException('FTP delete failed: ' . $remotePath);
        }
    }

    public function test(string $path): void
    {
        $ftp = $this->connect();
        if (@ftp_chdir($ftp, $path === '' ? '/' : $path) === false) {
            throw new StorageException('FTP directory not found: ' . $path);
        }
    }

    private function connect(): Connection
    {
        if ($this->connection !== null) {
            return $this->connection;
        }
        if (!function_exists('ftp_connect')) {
            throw new StorageException('PHP extension "ftp" is not installed.');
        }
        $connection = $this->ssl
            ? @ftp_ssl_connect($this->host, $this->port, 20)
            : @ftp_connect($this->host, $this->port, 20);
        if ($connection === false) {
            throw new StorageException('Cannot connect to FTP server ' . $this->host);
        }
        if (!@ftp_login($connection, $this->username, $this->password)) {
            throw new StorageException('FTP login failed for ' . $this->username);
        }
        ftp_pasv($connection, $this->passive);
        $this->connection = $connection;

        return $connection;
    }

    private function mkdirs(Connection $ftp, string $dir): void
    {
        $parts = array_filter(explode('/', $dir), static fn (string $p): bool => $p !== '');
        $current = str_starts_with($dir, '/') ? '' : '.';
        foreach ($parts as $part) {
            $current .= '/' . $part;
            if (@ftp_chdir($ftp, $current) === false) {
                @ftp_mkdir($ftp, $current);
            }
        }
        @ftp_chdir($ftp, '/');
    }
}
