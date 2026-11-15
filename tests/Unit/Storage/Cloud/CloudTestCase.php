<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Storage\Cloud\OAuthClient;
use AudiEdiPipe\Infrastructure\Storage\Cloud\OAuthTokens;
use AudiEdiPipe\Infrastructure\Storage\Cloud\TokenSession;
use AudiEdiPipe\Tests\Support\FakeHttpClient;
use AudiEdiPipe\Tests\Support\TestCase;

abstract class CloudTestCase extends TestCase
{
    protected FakeHttpClient $http;

    /** @var list<OAuthTokens> */
    protected array $persisted = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new FakeHttpClient();
        $this->persisted = [];
    }

    protected function session(string $accessToken = 'access-1', int $expiresIn = 3600): TokenSession
    {
        return new TokenSession(
            new OAuthClient($this->http, 'Test', 'https://auth.example/authorize', 'https://auth.example/token', 'client', 'secret'),
            new OAuthTokens($accessToken, 'refresh-1', time() + $expiresIn),
            function (OAuthTokens $tokens): void {
                $this->persisted[] = $tokens;
            },
        );
    }
}
