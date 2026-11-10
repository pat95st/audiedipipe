<?php

/*
 * Example configuration. The installer writes config/config.php automatically.
 * Copy this file to config/config.php only if you want to configure manually.
 */

return [
    'app' => [
        // Random 32 byte key, base64 encoded. Used to encrypt stored credentials.
        // Generate with: php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"
        'key' => 'CHANGE_ME',
        // Public base URL without trailing slash, e.g. https://audio.example.com
        'url' => 'http://localhost:8080',
        'debug' => false,
        'default_locale' => 'en',
    ],
    'database' => [
        // mysql (MariaDB) | pgsql (PostgreSQL) | sqlite (tests/dev only)
        'driver' => 'mysql',
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'audiedipipe',
        'user' => 'audiedipipe',
        'password' => '',
        // only for sqlite
        'path' => APP_ROOT . '/storage/database.sqlite',
    ],
    'paths' => [
        'storage' => APP_ROOT . '/storage',
        'ffmpeg' => 'ffmpeg',
        'ffprobe' => 'ffprobe',
        'smbclient' => 'smbclient',
    ],
    'worker' => [
        // Max seconds a single worker invocation may run (webspace friendly).
        'time_budget' => 50,
        // Token for the web cron endpoint /cron?token=...
        'cron_token' => 'CHANGE_ME',
        // Run the worker after normal page requests ("pseudo cron") when no real cron is available.
        'pseudo_cron' => false,
    ],
];
