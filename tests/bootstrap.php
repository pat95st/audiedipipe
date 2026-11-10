<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap. audiEdiPipe has no runtime Composer dependencies, so the
 * application is loaded through its own autoloader; the Composer dev autoloader
 * (PHPUnit itself) is loaded first when present.
 */

error_reporting(E_ALL);

$composerAutoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composerAutoload)) {
    require_once $composerAutoload;
}

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/src/bootstrap.php';
