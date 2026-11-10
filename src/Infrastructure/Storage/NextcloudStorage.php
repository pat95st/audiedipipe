<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

use AudiEdiPipe\Infrastructure\Http\HttpClient;

/**
 * Nextcloud / ownCloud: WebDAV under remote.php/dav/files/<user>/.
 * Use an app password (Settings > Security) instead of the login password.
 */
final class NextcloudStorage extends WebDavStorage
{
    public function __construct(HttpClient $http, string $serverUrl, string $username, string $password, bool $verifyTls = true)
    {
        parent::__construct($http, self::davUrl($serverUrl, $username), $username, $password, $verifyTls);
    }

    public static function davUrl(string $serverUrl, string $username): string
    {
        $serverUrl = rtrim($serverUrl, '/');
        if (str_contains($serverUrl, '/remote.php/')) {
            return $serverUrl;
        }

        return $serverUrl . '/remote.php/dav/files/' . rawurlencode($username);
    }
}
