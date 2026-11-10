<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Acceptance;

use AudiEdiPipe\Http\ArraySession;
use AudiEdiPipe\Http\HttpKernel;
use AudiEdiPipe\Http\Request;
use AudiEdiPipe\Kernel\App;
use AudiEdiPipe\Kernel\Config;
use AudiEdiPipe\Tests\Support\TestCase;

/**
 * Before installation the app must guide the user to the installer and must
 * not leak application routes. This boots the real HTTP kernel end to end.
 */
final class InstallFlowTest extends TestCase
{
    private function freshKernel(): HttpKernel
    {
        // Empty config => App::isInstalled() is false.
        $app = new App(new Config([]), APP_ROOT);

        return new HttpKernel($app, new ArraySession());
    }

    public function test_install_page_renders_before_installation(): void
    {
        $response = $this->freshKernel()->handle(new Request('GET', '/install'));

        self::assertSame(200, $response->status);
        self::assertStringContainsStringIgnoringCase('<form', $response->body);
    }

    public function test_requirements_check_endpoint_responds(): void
    {
        $response = $this->freshKernel()->handle(new Request('GET', '/install/check'));
        self::assertSame(200, $response->status);
    }

    public function test_install_post_without_csrf_is_rejected(): void
    {
        $response = $this->freshKernel()->handle(new Request('POST', '/install', post: ['username' => 'x']));
        self::assertSame(419, $response->status);
    }

    public function test_security_headers_are_present(): void
    {
        $response = $this->freshKernel()->handle(new Request('GET', '/install'));
        // HttpKernel::securityHeaders adds standard hardening headers.
        self::assertArrayHasKey('X-Content-Type-Options', $response->headers);
        self::assertSame('nosniff', $response->headers['X-Content-Type-Options']);
    }
}
