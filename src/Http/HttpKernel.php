<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http;

use AudiEdiPipe\Application\Auth\AuthService;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Domain\User\Role;
use AudiEdiPipe\Http\Controller\AssetController;
use AudiEdiPipe\Http\Controller\Controller;
use AudiEdiPipe\Http\Controller\InstallController;
use AudiEdiPipe\Infrastructure\I18n\Translator;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;
use AudiEdiPipe\Kernel\App;
use Throwable;

/**
 * HTTP front controller: routing, installer guard, locale, authentication,
 * authorization, CSRF protection and error handling.
 */
final class HttpKernel
{
    private Router $router;

    public function __construct(
        private readonly App $app,
        private readonly Session $session,
        private readonly string $basePath = '',
    ) {
        $this->router = new Router();
        Routes::register($this->router);
    }

    public function router(): Router
    {
        return $this->router;
    }

    public function handle(Request $request): Response
    {
        try {
            $response = $this->dispatch($request);
        } catch (Throwable $e) {
            $response = $this->error($e, $request);
        }

        return $this->securityHeaders($response);
    }

    private function dispatch(Request $request): Response
    {
        $translator = $this->app->get(Translator::class);
        $view = new View($this->app->root . '/templates', $translator, $this->basePath);

        if (!$this->app->isInstalled()) {
            if (str_starts_with($request->path, '/locale/')) {
                $this->session->set('locale', substr($request->path, 8));

                return Response::redirect($view->url('/install'));
            }
            if ($request->path === '/theme.css') {
                return Response::text(AssetController::css('#e8590c', '#1c7ed6'), 200, 'text/css; charset=utf-8');
            }
            if (str_starts_with($request->path, '/assets/') || $request->path === '/manifest.webmanifest') {
                return Response::notFound();
            }
            $sessionLocale = (string) $this->session->get('locale', '');
            $translator->setLocale($translator->supports($sessionLocale) ? $sessionLocale : $translator->negotiate($request->header('accept-language'), 'en'));
            $view->share('csrf', $this->csrfToken());
            $view->share('user', null);
            $controller = new InstallController($this->app, $view, $this->session, $request, null);
            if ($request->method === 'POST') {
                if (!$this->validCsrf($request)) {
                    return Response::text('Invalid CSRF token', 419);
                }

                return $controller->install();
            }
            if ($request->path === '/install/check') {
                return $controller->check();
            }

            return $controller->show();
        }

        $match = $this->router->match($request->method, $request->path);
        if ($match === null) {
            return $this->notFound($view, $request);
        }
        [$route, $params] = $match;
        if ($route->action === '__method_not_allowed') {
            return Response::text('Method not allowed', 405);
        }
        foreach ($params as $name => $value) {
            $request = $request->withAttribute($name, $value);
        }

        $settings = $this->app->get(SettingsRepository::class);
        $auth = $this->app->get(AuthService::class);
        $user = $auth->currentUser($this->session);

        // Locale: user preference > session > browser > default
        $locale = (string) ($user['locale'] ?? '');
        if ($locale === '' || !$translator->supports($locale)) {
            $locale = (string) $this->session->get('locale', '');
        }
        if ($locale === '' || !$translator->supports($locale)) {
            $locale = $translator->negotiate($request->header('accept-language'), $settings->string('default_locale') ?: 'en');
        }
        $translator->setLocale($locale);

        $view->share('user', $user);
        $view->share('csrf', $this->csrfToken());
        $view->share('settings', $settings->all());
        $view->share('path', $request->path);
        $view->share('flashes', []);
        $view->share('theme', (string) ($user['theme'] ?? $settings->string('default_theme') ?: 'auto'));

        if ($route->auth && $user === null) {
            if ($request->isJson()) {
                return Response::json(['error' => 'unauthenticated'], 401);
            }
            $this->session->set('intended', $request->path);

            return Response::redirect($view->url('/login'));
        }
        if ($route->roles !== [] && $user !== null) {
            $role = Role::fromString((string) $user['role']);
            $allowed = false;
            foreach ($route->roles as $permission) {
                $allowed = $allowed || $role->can($permission);
            }
            if (!$allowed) {
                return Response::html($view->render('errors/403', ['title' => $translator->trans('errors.forbidden')]), 403);
            }
        }
        if ($request->method === 'POST' && $route->csrf && !$this->validCsrf($request)) {
            if ($request->isJson()) {
                return Response::json(['error' => 'csrf'], 419);
            }
            $this->session->flash('danger', $translator->trans('errors.csrf'));

            return Response::redirect($view->url($request->path === '/login' ? '/login' : $request->path));
        }
        $view->share('flashes', $this->session->takeFlashes());

        $class = $route->controller;
        /** @var Controller $controller */
        $controller = new $class($this->app, $view, $this->session, $request, $user);
        $action = $route->action;
        try {
            /** @var Response $response */
            $response = $controller->{$action}();
        } catch (ValidationException $e) {
            $message = $translator->trans($e->getMessage(), $e->params);
            if ($request->isJson()) {
                return Response::json(['error' => $message], 422);
            }
            $this->session->flash('danger', $message);
            $referer = $request->header('referer');
            $target = $referer !== '' && str_starts_with($referer, $request->origin()) ? $referer : $view->url($request->path);

            return Response::redirect($target);
        }

        return $response;
    }

    private function csrfToken(): string
    {
        $token = $this->session->get('csrf_token');
        if (!is_string($token) || strlen($token) < 32) {
            $token = bin2hex(random_bytes(32));
            $this->session->set('csrf_token', $token);
        }

        return $token;
    }

    private function validCsrf(Request $request): bool
    {
        $expected = $this->session->get('csrf_token');
        $given = (string) ($request->post['_csrf'] ?? $request->header('x-csrf-token'));
        if ($given === '') {
            $json = $request->json();
            $given = is_string($json['_csrf'] ?? null) ? $json['_csrf'] : '';
        }

        return is_string($expected) && $expected !== '' && hash_equals($expected, $given);
    }

    private function notFound(View $view, Request $request): Response
    {
        if ($request->isJson()) {
            return Response::json(['error' => 'not_found'], 404);
        }
        $view->share('user', $this->app->get(AuthService::class)->currentUser($this->session));
        $view->share('settings', $this->app->get(SettingsRepository::class)->all());
        $view->share('path', $request->path);
        $view->share('flashes', []);
        $view->share('theme', 'auto');

        return Response::html($view->render('errors/404', ['title' => '404']), 404);
    }

    private function error(Throwable $e, Request $request): Response
    {
        $logDir = $this->app->storagePath('logs');
        if (is_dir($logDir) && is_writable($logDir)) {
            @file_put_contents(
                $logDir . '/app.log',
                sprintf("[%s] %s %s: %s in %s:%d\n%s\n", gmdate('c'), $request->method, $request->path, $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()),
                FILE_APPEND,
            );
        }
        $debug = $this->app->config->bool('app.debug');
        if ($request->isJson()) {
            return Response::json(['error' => $debug ? $e->getMessage() : 'Internal error'], 500);
        }
        $body = '<!doctype html><html><head><meta charset="utf-8"><title>Error</title></head><body style="font-family:sans-serif;padding:2rem">'
            . '<h1>Something went wrong</h1><p>The error was logged to storage/logs/app.log.</p>';
        if ($debug) {
            $body .= '<pre>' . htmlspecialchars($e->getMessage() . "\n\n" . $e->getTraceAsString(), ENT_QUOTES) . '</pre>';
        }

        return Response::html($body . '</body></html>', 500);
    }

    private function securityHeaders(Response $response): Response
    {
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), publickey-credentials-get=(self), publickey-credentials-create=(self)',
        ];
        $contentType = $response->headers['Content-Type'] ?? '';
        if (str_starts_with($contentType, 'text/html')) {
            $headers['Content-Security-Policy'] = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob: https:; media-src 'self' blob:; connect-src 'self'; font-src 'self' data:; frame-ancestors 'self'; base-uri 'self'; form-action 'self'";
        }
        foreach ($headers as $name => $value) {
            if (!isset($response->headers[$name])) {
                $response->headers[$name] = $value;
            }
        }

        return $response;
    }
}
