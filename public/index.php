<?php

declare(strict_types=1);

/*
 * audiEdiPipe front controller.
 *
 * Works with Apache (.htaccess rewrite), nginx (try_files ... /index.php),
 * the PHP built-in server (router.php) and webspaces in a sub directory.
 */

use AudiEdiPipe\Http\HttpKernel;
use AudiEdiPipe\Http\NativeSession;
use AudiEdiPipe\Http\Request;
use AudiEdiPipe\Kernel\App;
use AudiEdiPipe\Kernel\Worker;
use AudiEdiPipe\Kernel\WorkerTrigger;

define('APP_ROOT', dirname(__DIR__));

require APP_ROOT . '/src/bootstrap.php';

$app = App::fromConfigFile();
if ($app->config->bool('app.debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

// Base path when installed in a sub directory (e.g. https://example.com/audio).
$scriptDir = str_replace('\\', '/', dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php')));
$basePath = rtrim($scriptDir === '.' ? '' : $scriptDir, '/');
$requestPath = (string) (parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?? '/');
// Root .htaccess fallback rewrites /app/x to /app/public/x internally.
if (str_ends_with($basePath, '/public') && !str_starts_with($requestPath, $basePath)) {
    $basePath = substr($basePath, 0, -strlen('/public'));
}
// Without URL rewriting the URLs look like /index.php/jobs – strip the script name.
$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '');
if ($scriptName !== '' && str_starts_with($requestPath, $scriptName)) {
    $basePath = $scriptName;
}

$request = Request::fromGlobals($basePath);
$secure = $request->isSecure();
$session = new NativeSession($secure);

$kernel = new HttpKernel($app, $session, $basePath);
$response = $kernel->handle($request);
$response->send();

// Run the worker after the response has been delivered when a request asked
// for it (fresh upload, approved review ...) or pseudo cron is due.
if ($app->isInstalled()) {
    $worker = new Worker($app);
    if (WorkerTrigger::isRequested() || $worker->pseudoCronDue()) {
        session_write_close();
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } elseif (function_exists('litespeed_finish_request')) {
            litespeed_finish_request();
        }

        try {
            $worker->run();
        } catch (Throwable $e) {
            @file_put_contents($app->storagePath('logs/worker.log'), '[' . gmdate('c') . '] ' . $e->getMessage() . "\n", FILE_APPEND);
        }
    }
}
