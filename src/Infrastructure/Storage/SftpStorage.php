<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

/**
 * SFTP via ext-ssh2, falling back to phpseclib3 (pure PHP, works on webspaces)
 * when the extension is missing.
 */
final class SftpStorage implements StorageAdapter
{
    /** @var resource|null */
    private $session;

    /** @var resource|null */
    private $sftp;

    private ?object $phpseclib = null;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password = '',
        private readonly string $privateKey = '',
        private readonly string $passphrase = '',
        private readonly string $publicKey = '',
    ) {
    }

    public static function backend(): ?string
    {
        if (function_exists('ssh2_connect')) {
            return 'ssh2';
        }
        if (class_exists('phpseclib3\\Net\\SFTP')) {
            return 'phpseclib';
        }

        return null;
    }

    public function listFiles(string $path, bool $recursive = false): array
    {
        $this->connect();
        $path = $path === '' ? '/' : rtrim($path, '/');
        if ($this->phpseclib !== null) {
            /** @var array<string, array<string, mixed>>|false $entries */
            $entries = $this->phpseclib->rawlist($path); // @phpstan-ignore method.notFound
            if ($entries === false) {
                throw new StorageException('Cannot list SFTP directory ' . $path);
            }
            $files = [];
            foreach ($entries as $name => $attributes) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $full = $path . '/' . $name;
                if ((int) ($attributes['type'] ?? 0) === 2) { // NET_SFTP_TYPE_DIRECTORY
                    if ($recursive) {
                        array_push($files, ...$this->listFiles($full, true));
                    }
                    continue;
                }
                $files[] = new RemoteFile($full, (int) ($attributes['size'] ?? 0), (int) ($attributes['mtime'] ?? 0));
            }

            return $files;
        }
        $base = $this->wrapper($path);
        $handle = @opendir($base);
        if ($handle === false) {
            throw new StorageException('Cannot list SFTP directory ' . $path);
        }
        $files = [];
        while (($name = readdir($handle)) !== false) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $full = $path . '/' . $name;
            $stat = @ssh2_sftp_stat($this->sftp, $full); // @phpstan-ignore argument.type
            if ($stat === false) {
                continue;
            }
            if (((int) $stat['mode'] & 0170000) === 0040000) {
                if ($recursive) {
                    array_push($files, ...$this->listFiles($full, true));
                }
                continue;
            }
            $files[] = new RemoteFile($full, (int) $stat['size'], (int) $stat['mtime']);
        }
        closedir($handle);

        return $files;
    }

    public function download(string $remotePath, string $localPath): void
    {
        $this->connect();
        if ($this->phpseclib !== null) {
            if ($this->phpseclib->get($remotePath, $localPath) === false) { // @phpstan-ignore method.notFound
                throw new StorageException('SFTP download failed: ' . $remotePath);
            }

            return;
        }
        $this->copyStream($this->wrapper($remotePath), $localPath);
    }

    public function upload(string $localPath, string $remotePath): void
    {
        $this->connect();
        if ($this->phpseclib !== null) {
            $this->phpseclib->mkdir(dirname($remotePath), -1, true); // @phpstan-ignore method.notFound
            if ($this->phpseclib->put($remotePath, $localPath, 1) === false) { // @phpstan-ignore method.notFound
                throw new StorageException('SFTP upload failed: ' . $remotePath);
            }

            return;
        }
        @ssh2_sftp_mkdir($this->sftp, dirname($remotePath), 0775, true); // @phpstan-ignore argument.type
        $this->copyStream($localPath, $this->wrapper($remotePath));
    }

    public function delete(string $remotePath): void
    {
        $this->connect();
        $ok = $this->phpseclib !== null
            ? $this->phpseclib->delete($remotePath) // @phpstan-ignore method.notFound
            : @ssh2_sftp_unlink($this->sftp, $remotePath); // @phpstan-ignore argument.type
        if ($ok === false) {
            throw new StorageException('SFTP delete failed: ' . $remotePath);
        }
    }

    public function test(string $path): void
    {
        $this->listFiles($path);
    }

    /**
     * @return resource|null raw ssh2 session (used by the SCP adapter)
     */
    public function ssh2Session()
    {
        $this->connect();

        return $this->session;
    }

    private function connect(): void
    {
        if ($this->sftp !== null || $this->phpseclib !== null) {
            return;
        }
        $backend = self::backend();
        if ($backend === null) {
            throw new StorageException('Neither ext-ssh2 nor phpseclib/phpseclib is available for SFTP.');
        }
        if ($backend === 'phpseclib') {
            $class = 'phpseclib3\\Net\\SFTP';
            $sftp = new $class($this->host, $this->port, 20);
            $credential = $this->password;
            if ($this->privateKey !== '') {
                $loader = 'phpseclib3\\Crypt\\PublicKeyLoader';
                $credential = $loader::load($this->privateKey, $this->passphrase !== '' ? $this->passphrase : false);
            }
            if (!$sftp->login($this->username, $credential)) {
                throw new StorageException('SFTP login failed for ' . $this->username);
            }
            $this->phpseclib = $sftp;

            return;
        }
        $session = @ssh2_connect($this->host, $this->port);
        if ($session === false) {
            throw new StorageException('Cannot connect to SSH server ' . $this->host);
        }
        $authenticated = false;
        if ($this->privateKey !== '' && $this->publicKey !== '') {
            $dir = sys_get_temp_dir();
            $priv = (string) tempnam($dir, 'aepk');
            $pub = (string) tempnam($dir, 'aepp');
            chmod($priv, 0600);
            file_put_contents($priv, $this->privateKey);
            file_put_contents($pub, $this->publicKey);
            $authenticated = @ssh2_auth_pubkey_file($session, $this->username, $pub, $priv, $this->passphrase);
            @unlink($priv);
            @unlink($pub);
        }
        if (!$authenticated && $this->password !== '') {
            $authenticated = @ssh2_auth_password($session, $this->username, $this->password);
        }
        if (!$authenticated) {
            throw new StorageException('SSH authentication failed for ' . $this->username);
        }
        $sftp = @ssh2_sftp($session);
        if ($sftp === false) {
            throw new StorageException('Cannot initialize SFTP subsystem.');
        }
        $this->session = $session;
        $this->sftp = $sftp;
    }

    private function wrapper(string $path): string
    {
        return 'ssh2.sftp://' . (int) $this->sftp . '/' . ltrim($path, '/');
    }

    private function copyStream(string $from, string $to): void
    {
        $in = @fopen($from, 'rb');
        $out = @fopen($to, 'wb');
        if ($in === false || $out === false) {
            throw new StorageException('SFTP transfer failed: ' . $from . ' -> ' . $to);
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);
    }
}
