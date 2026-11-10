<?php

declare(strict_types=1);

/*
 * Router for the PHP built-in web server (development / quick tests):
 *
 *   php -S localhost:8080 -t public public/router.php
 */

$path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file) && !str_ends_with($file, '.php')) {
    return false;
}
$_SERVER['SCRIPT_NAME'] = '/index.php';

require __DIR__ . '/index.php';
