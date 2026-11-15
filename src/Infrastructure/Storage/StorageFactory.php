<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

use AudiEdiPipe\Infrastructure\Http\HttpClient;
use AudiEdiPipe\Infrastructure\Process\ProcessRunner;
use AudiEdiPipe\Infrastructure\Storage\Cloud\BoxStorage;
use AudiEdiPipe\Infrastructure\Storage\Cloud\CloudProviders;
use AudiEdiPipe\Infrastructure\Storage\Cloud\DropboxStorage;
use AudiEdiPipe\Infrastructure\Storage\Cloud\GoogleDriveStorage;
use AudiEdiPipe\Infrastructure\Storage\Cloud\ICloudDriveStorage;
use AudiEdiPipe\Infrastructure\Storage\Cloud\OAuthTokens;
use AudiEdiPipe\Infrastructure\Storage\Cloud\OneDriveStorage;
use AudiEdiPipe\Infrastructure\Storage\Cloud\TokenSession;
use AudiEdiPipe\Infrastructure\Support\Json;
use Closure;

final class StorageFactory
{
    public const TYPES = ['local', 'ftp', 'sftp', 'scp', 'webdav', 'nextcloud', 'smb', 'dropbox', 'icloud', 'onedrive', 'gdrive', 'box'];

    public const SECRET_FIELDS = ['password', 'private_key', 'passphrase', 'refresh_token', 'access_token'];

    /**
     * OAuth connection state of cloud sources. Never taken from form input;
     * written by the OAuth flow and on token refresh.
     */
    public const TOKEN_FIELDS = ['refresh_token', 'access_token', 'token_expires', 'account'];

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
        // Cloud providers: app credentials live in Settings > Cloud storage, the account is connected via OAuth.
        'dropbox' => [],
        'icloud' => [],
        'onedrive' => [],
        'gdrive' => [],
        'box' => [],
    ];

    public function __construct(
        private readonly HttpClient $http,
        private readonly ProcessRunner $runner,
        private readonly string $smbclient = 'smbclient',
        private readonly ?CloudProviders $cloud = null,
    ) {
    }

    /**
     * @param array<mixed>                      $config        decrypted configuration
     * @param (Closure(OAuthTokens): void)|null $persistTokens called when a cloud access token was refreshed
     */
    public function create(string $type, array $config, ?Closure $persistTokens = null): StorageAdapter
    {
        if (in_array($type, CloudProviders::TYPES, true)) {
            return $this->cloud($type, $config, $persistTokens);
        }

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

    /**
     * @param array<mixed>                      $config
     * @param (Closure(OAuthTokens): void)|null $persistTokens
     */
    private function cloud(string $type, array $config, ?Closure $persistTokens): StorageAdapter
    {
        if ($this->cloud === null) {
            throw new StorageException('Cloud storage is not available.');
        }
        if ($type === 'icloud') {
            return new ICloudDriveStorage($this->cloud->icloudBasePath());
        }
        $session = new TokenSession(
            $this->cloud->client($type),
            new OAuthTokens(Json::str($config, 'access_token'), Json::str($config, 'refresh_token'), Json::int($config, 'token_expires')),
            $persistTokens,
        );

        return match ($type) {
            'dropbox' => new DropboxStorage($this->http, $session),
            'onedrive' => new OneDriveStorage($this->http, $session),
            'gdrive' => new GoogleDriveStorage($this->http, $session),
            'box' => new BoxStorage($this->http, $session),
            default => throw new StorageException('Unknown storage type: ' . $type),
        };
    }
}
