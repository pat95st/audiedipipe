<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\Cloud\DropboxStorage;
use AudiEdiPipe\Infrastructure\Storage\Cloud\OAuthClient;
use AudiEdiPipe\Infrastructure\Storage\StorageException;

final class OAuthClientTest extends CloudTestCase
{
    public function test_authorization_url_contains_client_redirect_state_and_extra_params(): void
    {
        $client = new OAuthClient($this->http, 'Google Drive', 'https://accounts.example/auth', 'https://accounts.example/token', 'my-id', 'my-secret', 'scope-a scope-b', ['access_type' => 'offline']);
        $url = $client->authorizationUrl('https://aep.example/oauth/callback', 'xyz');
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

        self::assertStringStartsWith('https://accounts.example/auth?', $url);
        self::assertSame('code', $query['response_type']);
        self::assertSame('my-id', $query['client_id']);
        self::assertSame('https://aep.example/oauth/callback', $query['redirect_uri']);
        self::assertSame('xyz', $query['state']);
        self::assertSame('scope-a scope-b', $query['scope']);
        self::assertSame('offline', $query['access_type']);
        self::assertArrayNotHasKey('client_secret', $query);
    }

    public function test_exchange_code_posts_form_and_returns_tokens(): void
    {
        $this->http->queue(200, ['access_token' => 'at', 'refresh_token' => 'rt', 'expires_in' => 14400]);
        $client = new OAuthClient($this->http, 'Dropbox', 'https://a/authorize', 'https://a/token', 'id', 'secret', '', [], ['scope' => 'x']);

        $tokens = $client->exchangeCode('the-code', 'https://aep/cb');

        self::assertSame('at', $tokens->accessToken);
        self::assertSame('rt', $tokens->refreshToken);
        self::assertEqualsWithDelta(time() + 14400, $tokens->expiresAt, 5);
        parse_str((string) $this->http->requests[0]['body'], $form);
        self::assertSame(['grant_type' => 'authorization_code', 'code' => 'the-code', 'redirect_uri' => 'https://aep/cb', 'client_id' => 'id', 'client_secret' => 'secret', 'scope' => 'x'], $form);
    }

    public function test_exchange_without_refresh_token_fails(): void
    {
        $this->http->queue(200, ['access_token' => 'at', 'expires_in' => 3600]);
        $client = new OAuthClient($this->http, 'Google Drive', 'https://a', 'https://t', 'id', 'secret');

        $this->expectException(StorageException::class);
        $client->exchangeCode('c', 'https://aep/cb');
    }

    public function test_refresh_keeps_refresh_token_when_provider_does_not_rotate_it(): void
    {
        $this->http->queue(200, ['access_token' => 'new', 'expires_in' => 3600]);
        $client = new OAuthClient($this->http, 'Dropbox', 'https://a', 'https://t', 'id', 'secret');

        $tokens = $client->refresh('old-refresh');

        self::assertSame('new', $tokens->accessToken);
        self::assertSame('old-refresh', $tokens->refreshToken);
    }

    public function test_refresh_error_is_reported_readably(): void
    {
        $this->http->queue(400, ['error' => 'invalid_grant', 'error_description' => 'Refresh token expired']);
        $client = new OAuthClient($this->http, 'Box', 'https://a', 'https://t', 'id', 'secret');

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('Box authorization failed: Refresh token expired');
        $client->refresh('r');
    }

    public function test_expired_access_token_is_refreshed_and_persisted_before_the_api_call(): void
    {
        $this->http
            ->queue(200, ['access_token' => 'fresh', 'refresh_token' => 'rotated', 'expires_in' => 3600])
            ->queue(200, ['entries' => [], 'has_more' => false]);
        $storage = new DropboxStorage($this->http, $this->session('stale', -10));

        $storage->listFiles('/');

        self::assertSame('https://auth.example/token', $this->http->requests[0]['url']);
        self::assertSame('Bearer fresh', $this->http->requests[1]['headers']['Authorization']);
        self::assertCount(1, $this->persisted);
        self::assertSame('rotated', $this->persisted[0]->refreshToken);
    }

    public function test_api_401_triggers_one_refresh_and_retry(): void
    {
        $this->http
            ->queue(401, ['error_summary' => 'expired_access_token/'])
            ->queue(200, ['access_token' => 'fresh', 'expires_in' => 3600])
            ->queue(200, ['entries' => [], 'has_more' => false]);
        $storage = new DropboxStorage($this->http, $this->session());

        self::assertSame([], $storage->listFiles('/in'));
        self::assertSame('Bearer access-1', $this->http->requests[0]['headers']['Authorization']);
        self::assertSame('Bearer fresh', $this->http->requests[2]['headers']['Authorization']);
        self::assertSame('refresh-1', $this->persisted[0]->refreshToken);
    }
}
