<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Http\HttpClient;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Storage\StorageException;

/**
 * Cloud storage providers and their app credentials (configured under Settings > Cloud storage).
 */
final class CloudProviders
{
    /** Providers that are connected per storage location via OAuth 2.0. */
    public const OAUTH_TYPES = ['dropbox', 'onedrive', 'gdrive', 'box'];

    public const TYPES = ['dropbox', 'icloud', 'onedrive', 'gdrive', 'box'];

    private const ONEDRIVE_SCOPE = 'offline_access Files.ReadWrite.All User.Read';

    public const SETTINGS_KEY = 'cloud_storage';

    public const DEFAULTS = [
        'dropbox' => ['enabled' => false, 'client_id' => '', 'client_secret' => ''],
        'icloud' => ['enabled' => false, 'base_path' => ''],
        'onedrive' => ['enabled' => false, 'client_id' => '', 'client_secret' => '', 'tenant' => 'common'],
        'gdrive' => ['enabled' => false, 'client_id' => '', 'client_secret' => ''],
        'box' => ['enabled' => false, 'client_id' => '', 'client_secret' => ''],
    ];

    /** Developer consoles where the OAuth apps are registered. */
    public const CONSOLES = [
        'dropbox' => 'https://www.dropbox.com/developers/apps',
        'onedrive' => 'https://entra.microsoft.com/#view/Microsoft_AAD_RegisteredApps/ApplicationsListBlade',
        'gdrive' => 'https://console.cloud.google.com/apis/credentials',
        'box' => 'https://app.box.com/developers/console',
    ];

    /** Default location of the iCloud Drive sync folder on macOS. */
    public const ICLOUD_MAC_PATH = '~/Library/Mobile Documents/com~apple~CloudDocs';

    public function __construct(
        private readonly HttpClient $http,
        private readonly SettingsRepository $settings,
        private readonly Crypto $crypto,
        private readonly string $appUrl,
    ) {
    }

    public static function isOAuth(string $type): bool
    {
        return in_array($type, self::OAUTH_TYPES, true);
    }

    public function redirectUri(): string
    {
        return rtrim($this->appUrl, '/') . '/oauth/callback';
    }

    /**
     * Decrypted settings of one provider.
     *
     * @return array<string, mixed>
     */
    public function config(string $type): array
    {
        $all = $this->settings->get(self::SETTINGS_KEY);
        $config = is_array($all) && is_array($all[$type] ?? null) ? $all[$type] : [];
        $config += self::DEFAULTS[$type] ?? [];
        if (is_string($config['client_secret'] ?? null) && $config['client_secret'] !== '') {
            $config['client_secret'] = $this->crypto->decrypt($config['client_secret']);
        }

        return $config;
    }

    public function enabled(string $type): bool
    {
        if (!in_array($type, self::TYPES, true)) {
            return false;
        }
        $config = $this->config($type);
        if (($config['enabled'] ?? false) !== true) {
            return false;
        }

        return !self::isOAuth($type) || (($config['client_id'] ?? '') !== '' && ($config['client_secret'] ?? '') !== '');
    }

    /**
     * @return list<string>
     */
    public function enabledTypes(): array
    {
        return array_values(array_filter(self::TYPES, fn (string $type): bool => $this->enabled($type)));
    }

    public function icloudBasePath(): string
    {
        $path = trim((string) ($this->config('icloud')['base_path'] ?? ''));

        return $path !== '' ? $path : self::ICLOUD_MAC_PATH;
    }

    public function client(string $type): OAuthClient
    {
        if (!self::isOAuth($type)) {
            throw new StorageException('Not an OAuth storage type: ' . $type);
        }
        $config = $this->config($type);
        $id = (string) ($config['client_id'] ?? '');
        $secret = (string) ($config['client_secret'] ?? '');
        if ($id === '' || $secret === '') {
            throw new StorageException(sprintf('%s is not configured. Enter the app credentials under Settings > Cloud storage.', self::label($type)));
        }

        return match ($type) {
            'dropbox' => new OAuthClient(
                $this->http, 'Dropbox',
                'https://www.dropbox.com/oauth2/authorize', 'https://api.dropboxapi.com/oauth2/token',
                $id, $secret, '', ['token_access_type' => 'offline'],
            ),
            'onedrive' => new OAuthClient(
                $this->http, 'OneDrive',
                'https://login.microsoftonline.com/' . self::tenant($config) . '/oauth2/v2.0/authorize',
                'https://login.microsoftonline.com/' . self::tenant($config) . '/oauth2/v2.0/token',
                $id, $secret, self::ONEDRIVE_SCOPE, [], ['scope' => self::ONEDRIVE_SCOPE],
            ),
            'gdrive' => new OAuthClient(
                $this->http, 'Google Drive',
                'https://accounts.google.com/o/oauth2/v2/auth', 'https://oauth2.googleapis.com/token',
                $id, $secret, 'https://www.googleapis.com/auth/drive', ['access_type' => 'offline', 'prompt' => 'consent'],
            ),
            'box' => new OAuthClient(
                $this->http, 'Box',
                'https://account.box.com/api/oauth2/authorize', 'https://api.box.com/oauth2/token',
                $id, $secret,
            ),
            default => throw new StorageException('Unknown cloud storage type: ' . $type),
        };
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function tenant(array $config): string
    {
        $tenant = is_string($config['tenant'] ?? null) ? $config['tenant'] : '';

        return preg_match('/^[A-Za-z0-9.-]+$/', $tenant) === 1 ? $tenant : 'common';
    }

    public static function label(string $type): string
    {
        return ['dropbox' => 'Dropbox', 'icloud' => 'iCloud Drive', 'onedrive' => 'OneDrive', 'gdrive' => 'Google Drive', 'box' => 'Box'][$type] ?? $type;
    }

    /**
     * Validate and normalise the settings form input. Empty secrets keep the stored value.
     *
     * @param array<mixed> $input
     * @param array<mixed> $current stored (encrypted) settings
     *
     * @return array<string, array<string, mixed>>
     */
    public static function sanitize(array $input, array $current, Crypto $crypto): array
    {
        $result = [];
        foreach (self::DEFAULTS as $type => $defaults) {
            $in = is_array($input[$type] ?? null) ? $input[$type] : [];
            $old = is_array($current[$type] ?? null) ? $current[$type] : [];
            $row = [];
            foreach ($defaults as $key => $default) {
                $value = $in[$key] ?? null;
                $row[$key] = match (true) {
                    $key === 'enabled' => in_array($value, [true, 1, '1', 'on', 'true'], true),
                    $key === 'client_secret' => is_string($value) && trim($value) !== ''
                        ? $crypto->encrypt(trim($value))
                        : (string) ($old['client_secret'] ?? ''),
                    $key === 'tenant' => is_string($value) && preg_match('/^[A-Za-z0-9.-]+$/', trim($value)) === 1 ? trim($value) : 'common',
                    default => is_scalar($value) ? mb_substr(trim((string) $value), 0, 500) : (string) ($old[$key] ?? $default),
                };
            }
            $result[$type] = $row;
        }

        return $result;
    }
}
