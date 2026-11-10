<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

use AudiEdiPipe\Infrastructure\Process\ProcessRunner;

/**
 * SCP transfer. SCP itself cannot list directories, so listing runs
 * "find" over SSH. Uses ext-ssh2 when available, otherwise the system
 * ssh/scp binaries with key based authentication.
 */
final class ScpStorage implements StorageAdapter
{
    private ?SftpStorage $ssh = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
        private readonly string $privateKey,
        private readonly string $passphrase,
        private readonly string $publicKey,
        private readonly ProcessRunner $runner,
    ) {
    }

    public function listFiles(string $path, bool $recursive = false): array
    {
        $command = sprintf(
            'find %s %s -type f -printf %s',
            escapeshellarg($path === '' ? '.' : $path),
            $recursive ? '' : '-maxdepth 1',
            escapeshellarg('%s\t%T@\t%p\n'),
        );

        return self::parseFindOutput($this->exec($command));
    }

    /**
     * @return list<RemoteFile>
     */
    public static function parseFindOutput(string $output): array
    {
        $files = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $parts = explode("\t", $line, 3);
            if (count($parts) === 3) {
                $files[] = new RemoteFile($parts[2], (int) $parts[0], (int) $parts[1]);
            }
        }

        return $files;
    }

    public function download(string $remotePath, string $localPath): void
    {
        if (function_exists('ssh2_scp_recv')) {
            if (!@ssh2_scp_recv($this->ssh2(), $remotePath, $localPath)) {
                throw new StorageException('SCP download failed: ' . $remotePath);
            }

            return;
        }
        $this->cli('scp', [$this->username . '@' . $this->host . ':' . $remotePath, $localPath]);
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $this->exec('mkdir -p ' . escapeshellarg(dirname($remotePath)));
        if (function_exists('ssh2_scp_send')) {
            if (!@ssh2_scp_send($this->ssh2(), $localPath, $remotePath, 0644)) {
                throw new StorageException('SCP upload failed: ' . $remotePath);
            }

            return;
        }
        $this->cli('scp', [$localPath, $this->username . '@' . $this->host . ':' . $remotePath]);
    }

    public function delete(string $remotePath): void
    {
        $this->exec('rm -f ' . escapeshellarg($remotePath));
    }

    public function test(string $path): void
    {
        $this->exec('test -d ' . escapeshellarg($path === '' ? '.' : $path) . ' && echo ok');
    }

    private function exec(string $command): string
    {
        if (function_exists('ssh2_exec')) {
            $stream = @ssh2_exec($this->ssh2(), $command);
            if ($stream === false) {
                throw new StorageException('SSH command failed.');
            }
            stream_set_blocking($stream, true);
            $output = (string) stream_get_contents($stream);
            fclose($stream);

            return $output;
        }

        return $this->cli('ssh', [$this->username . '@' . $this->host, $command]);
    }

    /**
     * @return resource
     */
    private function ssh2()
    {
        $this->ssh ??= new SftpStorage($this->host, $this->port, $this->username, $this->password, $this->privateKey, $this->passphrase, $this->publicKey);
        $session = $this->ssh->ssh2Session();
        if ($session === null) {
            throw new StorageException('SSH session unavailable.');
        }

        return $session;
    }

    /**
     * @param list<string> $args
     */
    private function cli(string $binary, array $args): string
    {
        if ($this->privateKey === '') {
            throw new StorageException('SCP without ext-ssh2 requires a private key.');
        }
        $keyFile = (string) tempnam(sys_get_temp_dir(), 'aepk');
        chmod($keyFile, 0600);
        file_put_contents($keyFile, rtrim($this->privateKey) . "\n");
        $portFlag = $binary === 'scp' ? '-P' : '-p';
        try {
            $result = $this->runner->run([
                $binary, '-i', $keyFile, $portFlag, (string) $this->port,
                '-o', 'BatchMode=yes', '-o', 'StrictHostKeyChecking=accept-new', ...$args,
            ], 3600);
        } finally {
            @unlink($keyFile);
        }
        if (!$result->successful()) {
            throw new StorageException($binary . ' failed: ' . $result->tail(3));
        }

        return $result->stdout;
    }
}
