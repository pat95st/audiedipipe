<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

use AudiEdiPipe\Infrastructure\Process\ProcessRunner;

/**
 * Samba / CIFS shares via the smbclient command line tool. Credentials are
 * passed through a temporary authentication file, never on the command line.
 * Alternative without smbclient: mount the share and use the "local" type.
 */
final class SmbStorage implements StorageAdapter
{
    public function __construct(
        private readonly ProcessRunner $runner,
        private readonly string $host,
        private readonly string $share,
        private readonly string $username,
        private readonly string $password,
        private readonly string $domain = '',
        private readonly string $binary = 'smbclient',
    ) {
    }

    public function listFiles(string $path, bool $recursive = false): array
    {
        $dir = trim($path, '/');
        $output = $this->run('ls "' . self::escape($dir === '' ? '*' : $dir . '/*') . '"');
        $files = [];
        foreach (self::parseListing($output) as $entry) {
            $full = ($dir === '' ? '' : $dir . '/') . $entry['name'];
            if ($entry['dir']) {
                if ($recursive) {
                    array_push($files, ...$this->listFiles($full, true));
                }

                continue;
            }
            $files[] = new RemoteFile('/' . $full, $entry['size'], $entry['mtime']);
        }

        return $files;
    }

    /**
     * @return list<array{name: string, size: int, mtime: int, dir: bool}>
     */
    public static function parseListing(string $output): array
    {
        $result = [];
        $pattern = '/^\s{2}(.+?)\s+([ADHSRNb]*)\s+(\d+)\s+(\w{3}\s+\w{3}\s+\d{1,2}\s+\d{2}:\d{2}:\d{2}\s+\d{4})\s*$/';
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match($pattern, $line, $m) !== 1) {
                continue;
            }
            if ($m[1] === '.' || $m[1] === '..') {
                continue;
            }
            $time = strtotime($m[4]);
            $result[] = [
                'name' => $m[1],
                'size' => (int) $m[3],
                'mtime' => $time === false ? 0 : $time,
                'dir' => str_contains($m[2], 'D'),
            ];
        }

        return $result;
    }

    public function download(string $remotePath, string $localPath): void
    {
        $this->run('get "' . self::escape(trim($remotePath, '/')) . '" "' . self::escape($localPath) . '"');
        if (!is_file($localPath)) {
            throw new StorageException('SMB download failed: ' . $remotePath);
        }
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $remote = trim($remotePath, '/');
        $commands = [];
        $current = '';
        foreach (array_slice(explode('/', $remote), 0, -1) as $segment) {
            $current .= ($current === '' ? '' : '/') . $segment;
            $commands[] = 'mkdir "' . self::escape($current) . '"';
        }
        $commands[] = 'put "' . self::escape($localPath) . '" "' . self::escape($remote) . '"';
        $this->run(implode('; ', $commands), true);
    }

    public function delete(string $remotePath): void
    {
        $this->run('del "' . self::escape(trim($remotePath, '/')) . '"');
    }

    public function test(string $path): void
    {
        $this->listFiles($path);
    }

    private static function escape(string $value): string
    {
        if (str_contains($value, '"') || str_contains($value, ';')) {
            throw new StorageException('Unsupported character in SMB path: ' . $value);
        }

        return $value;
    }

    private function run(string $commands, bool $ignoreMkdirErrors = false): string
    {
        $authFile = (string) tempnam(sys_get_temp_dir(), 'aeps');
        chmod($authFile, 0600);
        file_put_contents($authFile, sprintf("username = %s\npassword = %s\n%s", $this->username, $this->password, $this->domain !== '' ? 'domain = ' . $this->domain . "\n" : ''));

        try {
            $result = $this->runner->run([
                $this->binary, '//' . $this->host . '/' . $this->share, '-A', $authFile, '-c', $commands,
            ], 3600);
        } finally {
            @unlink($authFile);
        }
        $output = $result->stdout . "\n" . $result->stderr;
        $failed = !$result->successful() || preg_match('/NT_STATUS_(?!OBJECT_NAME_COLLISION)[A-Z_]+/', $output) === 1;
        if ($failed && !($ignoreMkdirErrors && $result->successful())) {
            if ($result->exitCode === 127) {
                throw new StorageException('smbclient is not installed.');
            }

            throw new StorageException('smbclient failed: ' . $result->tail(3));
        }

        return $result->stdout;
    }
}
