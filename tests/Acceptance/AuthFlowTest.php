<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Acceptance;

use AudiEdiPipe\Application\User\Command\CreateUser;
use AudiEdiPipe\Cqrs\CommandBus;
use AudiEdiPipe\Http\ArraySession;
use AudiEdiPipe\Http\HttpKernel;
use AudiEdiPipe\Http\Request;
use AudiEdiPipe\Tests\Support\TestCase;

/**
 * End-to-end authentication against an installed application instance.
 */
final class AuthFlowTest extends TestCase
{
    public function test_protected_route_redirects_anonymous_user_to_login(): void
    {
        $app = $this->installedApp();
        $kernel = new HttpKernel($app, new ArraySession());

        $response = $kernel->handle(new Request('GET', '/'));

        self::assertSame(302, $response->status);
        self::assertStringContainsString('/login', $response->headers['Location'] ?? '');
    }

    public function test_login_form_renders(): void
    {
        $app = $this->installedApp();
        $kernel = new HttpKernel($app, new ArraySession());

        $response = $kernel->handle(new Request('GET', '/login'));

        self::assertSame(200, $response->status);
        self::assertStringContainsString('name="username"', $response->body);
        self::assertStringContainsString('_csrf', $response->body);
    }

    public function test_valid_credentials_are_accepted(): void
    {
        $app = $this->installedApp();
        $app->get(CommandBus::class)->dispatch(new CreateUser(
            username: 'admin',
            email: 'admin@example.org',
            password: 'super-secret-password',
            role: 'admin',
        ));

        $session = new ArraySession();
        $session->set('csrf_token', str_repeat('a', 64));
        $kernel = new HttpKernel($app, $session);

        $response = $kernel->handle(new Request('POST', '/login', post: [
            '_csrf' => str_repeat('a', 64),
            'username' => 'admin',
            'password' => 'super-secret-password',
        ]));

        // Successful login redirects away from the login form (dashboard or 2FA step).
        self::assertSame(302, $response->status);
        self::assertStringNotContainsString('/login', $response->headers['Location'] ?? '/');
    }

    public function test_wrong_password_does_not_redirect_to_dashboard(): void
    {
        $app = $this->installedApp();
        $app->get(CommandBus::class)->dispatch(new CreateUser(
            username: 'admin',
            email: 'admin@example.org',
            password: 'super-secret-password',
            role: 'admin',
        ));

        $session = new ArraySession();
        $session->set('csrf_token', str_repeat('a', 64));
        $kernel = new HttpKernel($app, $session);

        $response = $kernel->handle(new Request('POST', '/login', post: [
            '_csrf' => str_repeat('a', 64),
            'username' => 'admin',
            'password' => 'WRONG',
        ]));

        $location = $response->headers['Location'] ?? '';
        self::assertTrue(
            $response->status !== 302 || str_contains($location, '/login'),
            'wrong password must not lead to the dashboard',
        );
    }
}
