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

    public function test_requirements_check_uses_the_entered_binary_paths(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Shell script fixtures need a POSIX system.');
        }
        $data = $this->checkJson($this->freshKernel(), $this->fakeBinary('ffmpeg'), $this->fakeBinary('ffprobe'));
        $byKey = array_column($data['checks'], null, 'key');

        self::assertTrue($byKey['ffmpeg']['ok']);
        self::assertSame('ffmpeg version 99.0-fake', $byKey['ffmpeg']['detail']);
        self::assertTrue($byKey['ffprobe']['ok']);
        self::assertStringContainsString('data-check="ffprobe"', $data['html']);
        self::assertStringContainsString('ffmpeg version 99.0-fake', $data['html']);
    }

    public function test_requirements_check_explains_a_non_executable_binary(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            self::markTestSkipped('Shell script fixtures need a POSIX system.');
        }
        $data = $this->checkJson($this->freshKernel(), $this->fakeBinary('ffmpeg', false), $this->fakeBinary('ffprobe'));
        $byKey = array_column($data['checks'], null, 'key');

        self::assertFalse($data['ok']);
        self::assertFalse($byKey['ffmpeg']['ok']);
        self::assertSame('install.hint.binary_not_executable', $byKey['ffmpeg']['hint']);
        self::assertStringContainsString('chmod +x', $data['html']);
    }

    public function test_entered_binary_paths_are_kept_when_the_page_is_reloaded(): void
    {
        $kernel = $this->freshKernel();
        $this->checkJson($kernel, '/opt/custom/bin/ffmpeg', '/opt/custom/bin/ffprobe');

        $page = $kernel->handle(new Request('GET', '/install'))->body;

        self::assertStringContainsString('value="/opt/custom/bin/ffmpeg"', $page);
        self::assertStringContainsString('value="/opt/custom/bin/ffprobe"', $page);
        self::assertStringContainsString('id="aep-install-checks"', $page);
        self::assertStringContainsString('js/install.js', $page);
    }

    public function test_theme_css_is_served_before_installation(): void
    {
        $response = $this->freshKernel()->handle(new Request('GET', '/theme.css'));

        self::assertSame(200, $response->status);
        self::assertStringStartsWith('text/css', $response->headers['Content-Type'] ?? '');
        self::assertStringContainsString('--bs-primary', $response->body);
    }

    private function freshKernel(): HttpKernel
    {
        // Empty config => App::isInstalled() is false.
        $app = new App(new Config([]), APP_ROOT);

        return new HttpKernel($app, new ArraySession());
    }

    private function fakeBinary(string $name, bool $executable = true): string
    {
        $path = $this->tempDir('aepbin') . '/' . $name;
        file_put_contents($path, "#!/bin/sh\necho \"{$name} version 99.0-fake\"\n");
        chmod($path, $executable ? 0755 : 0644);

        return $path;
    }

    /**
     * @return array{ok: bool, checks: list<array<string, mixed>>, html: string}
     */
    private function checkJson(HttpKernel $kernel, string $ffmpeg, string $ffprobe): array
    {
        $response = $kernel->handle(new Request('GET', '/install/check', query: ['ffmpeg' => $ffmpeg, 'ffprobe' => $ffprobe]));
        self::assertSame(200, $response->status);
        $data = json_decode($response->body, true);
        self::assertIsArray($data);

        return $data;
    }
}
