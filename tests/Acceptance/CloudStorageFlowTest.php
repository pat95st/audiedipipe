<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Acceptance;

use AudiEdiPipe\Application\Source\Command\SaveSource;
use AudiEdiPipe\Application\User\Command\CreateUser;
use AudiEdiPipe\Cqrs\CommandBus;
use AudiEdiPipe\Http\ArraySession;
use AudiEdiPipe\Http\HttpKernel;
use AudiEdiPipe\Http\Request;
use AudiEdiPipe\Kernel\App;
use AudiEdiPipe\Tests\Support\TestCase;

/**
 * Cloud storage: configuration in the settings, source form and OAuth redirect.
 */
final class CloudStorageFlowTest extends TestCase
{
    private const CSRF = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    public function test_cloud_providers_are_configured_in_the_settings_and_offered_for_sources(): void
    {
        [$app, $kernel, $session] = $this->loggedIn();

        $settings = $kernel->handle(new Request('GET', '/settings'));
        self::assertSame(200, $settings->status);
        self::assertStringContainsString('data-bs-target="#cloud"', $settings->body);
        self::assertStringContainsString('http://localhost/oauth/callback', $settings->body);
        foreach (['dropbox', 'icloud', 'onedrive', 'gdrive', 'box'] as $type) {
            self::assertStringContainsString('name="cloud_storage[' . $type . '][enabled]"', $settings->body);
        }

        // Not set up yet: still offered as storage type, but marked and explained.
        $form = $kernel->handle(new Request('GET', '/sources/new'));
        foreach (['dropbox', 'icloud', 'onedrive', 'gdrive', 'box'] as $type) {
            self::assertStringContainsString('<option value="' . $type . '"', $form->body);
        }
        self::assertStringContainsString('not set up yet', $form->body);
        self::assertStringContainsString('/settings#cloud', $form->body);

        $saved = $kernel->handle(new Request('POST', '/settings', post: [
            '_csrf' => self::CSRF,
            'cloud_storage' => [
                'dropbox' => ['enabled' => '1', 'client_id' => 'app-key', 'client_secret' => 'app-secret'],
                'icloud' => ['enabled' => '1', 'base_path' => ''],
                'onedrive' => ['enabled' => '0'], 'gdrive' => ['enabled' => '0'], 'box' => ['enabled' => '0'],
            ],
        ]));
        self::assertSame(302, $saved->status);
        self::assertStringEndsWith('/settings#cloud', $saved->headers['Location'] ?? '');

        $settings = $kernel->handle(new Request('GET', '/settings'));
        self::assertStringNotContainsString('app-secret', $settings->body);

        $form = $kernel->handle(new Request('GET', '/sources/new'));
        self::assertStringContainsString('<option value="dropbox">Dropbox</option>', $form->body);
        self::assertStringContainsString('<option value="icloud">iCloud Drive</option>', $form->body);
        self::assertStringContainsString('<option value="gdrive">Google Drive – not set up yet</option>', $form->body);

        // Start the OAuth flow for a saved Dropbox location.
        $id = (int) $app->get(CommandBus::class)->dispatch(new SaveSource(null, 'Dropbox inbox', 'dropbox', [], '/Inbox', [], 300, 'keep', true));
        $page = $kernel->handle(new Request('GET', '/sources/' . $id));
        self::assertStringContainsString('/sources/' . $id . '/connect', $page->body);

        $connect = $kernel->handle(new Request('POST', '/sources/' . $id . '/connect', post: ['_csrf' => self::CSRF]));
        self::assertSame(302, $connect->status);
        $location = $connect->headers['Location'] ?? '';
        self::assertStringStartsWith('https://www.dropbox.com/oauth2/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        self::assertSame('app-key', $query['client_id']);
        self::assertSame('offline', $query['token_access_type']);
        self::assertSame('http://localhost/oauth/callback', $query['redirect_uri']);
        $pending = $session->get('oauth_connect');
        self::assertIsArray($pending);
        self::assertSame($query['state'], $pending['state']);

        // A callback with a forged state is rejected without contacting Dropbox.
        $callback = $kernel->handle(new Request('GET', '/oauth/callback', ['state' => 'forged', 'code' => 'x']));
        self::assertSame(302, $callback->status);
        self::assertStringEndsWith('/sources', $callback->headers['Location'] ?? '');
        self::assertNull($session->get('oauth_connect'));
    }

    public function test_provider_error_on_callback_is_shown(): void
    {
        [$app, $kernel, $session] = $this->loggedIn();
        $session->set('oauth_connect', ['state' => 'abc', 'source' => 7, 'time' => time()]);

        $response = $kernel->handle(new Request('GET', '/oauth/callback', ['state' => 'abc', 'error' => 'access_denied']));

        self::assertStringEndsWith('/sources/7', $response->headers['Location'] ?? '');
        $flashes = $session->takeFlashes();
        self::assertStringContainsString('access_denied', $flashes[0]['message'] ?? '');
    }

    /**
     * @return array{App, HttpKernel, ArraySession}
     */
    private function loggedIn(): array
    {
        $app = $this->installedApp();
        $userId = (int) $app->get(CommandBus::class)->dispatch(new CreateUser(
            username: 'admin',
            email: 'admin@example.org',
            password: 'super-secret-password',
            role: 'admin',
        ));
        $session = new ArraySession();
        $session->set('csrf_token', self::CSRF);
        $session->set('auth_user_id', $userId);

        return [$app, new HttpKernel($app, $session), $session];
    }
}
