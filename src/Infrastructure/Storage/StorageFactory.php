<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

use AudiEdiPipe\Infrastructure\Http\HttpClient;
use AudiEdiPipe\Infrastructure\Process\ProcessRunner;
use AudiEdiPipe\Infrastructure\Support\Json;

final class StorageFactory
{
    public const TYPES = ['local', 'ftp', 'sftp', 'scp', 'webdav', 'nextcloud', 'smb'];

    public const SECRET_FIELDS = ['password', 'private_key', 'passphrase'];

    /**
     * Fields shown in the UI per type.
     */
    public const FIELDS = [
        'local' => [],
        'ftp' => ['host', 'port', 'username', 'password', 'ssl', 'passive'],
        'sftp' => ['host', 'port', 'username', 'password', 'private_key', 'passphrase', 'public_key'],
        'scp' => ['host', 'port', 'username', 'password', 'private_key', 'passphrase', 'public_key'],
        'webdav' => ['url', 'username', 'password', 'verify_tls'],
        'nextcloud' => ['url', 'username', 'password', 'verify_tls'],
        'smb' => ['host', 'share', 'domain', 'username', 'password'],
    ];

    public function __construct(
        private readonly HttpClient $http,
        private readonly ProcessRunner $runner,
        private readonly string $smbclient = 'smbclient',
    ) {
    }

    /**
     * @param array<mixed> $config decrypted configuration
     */
    public function create(string $type, array $config): StorageAdapter
    {
        $s = static fn (string $key, string $default = ''): string => Json::str($config, $key, $default);
        $port = static fn (int $default): int => Json::int($config, 'port', $default) ?: $default;
        $flag = static fn (string $key, bool $default): bool => isset($config[$key]) ? in_array($config[$key], [true, 1, '1', 'on', 'true'], true) : $default;

        return match ($type) {
            'local' => new LocalStorage(),
            'ftp' => new FtpStorage($s('host'), $port(21), $s('username'), $s('password'), $flag('ssl', false), $flag('passive', true)),
            'sftp' => new SftpStorage($s('host'), $port(22), $s('username'), $s('password'), $s('private_key'), $s('passphrase'), $s('public_key')),
            'scp' => new ScpStorage($s('host'), $port(22), $s('username'), $s('password'), $s('private_key'), $s('passphrase'), $s('public_key'), $this->runner),
            'webdav' => new WebDavStorage($this->http, $s('url'), $s('username'), $s('password'), $flag('verify_tls', true)),
            'nextcloud' => new NextcloudStorage($this->http, $s('url'), $s('username'), $s('password'), $flag('verify_tls', true)),
            'smb' => new SmbStorage($this->runner, $s('host'), $s('share'), $s('username'), $s('password'), $s('domain'), $this->smbclient),
            default => throw new StorageException('Unknown storage type: ' . $type),
        };
    }
}
