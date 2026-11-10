<?php

declare(strict_types=1);

/*
 * Bootstrap without Composer: audiEdiPipe has no runtime dependencies, so it
 * can run on a plain webspace. If a Composer autoloader exists (dev tools,
 * optional phpseclib) it is loaded as well.
 */

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

$composerAutoload = APP_ROOT . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

spl_autoload_register(static function (string $class): void {
    $prefix = 'AudiEdiPipe\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($file)) {
        require_once $file;
    }
});

date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
