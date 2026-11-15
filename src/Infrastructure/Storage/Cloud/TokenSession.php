<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage\Cloud;

use Closure;

/**
 * Hands out a valid access token, refreshing it when needed. Refreshed tokens
 * are passed to the persist callback because some providers (Box, Microsoft)
 * rotate the refresh token and invalidate the old one.
 */
final class TokenSession
{
    private OAuthTokens $tokens;

    /**
     * @param (Closure(OAuthTokens): void)|null $persist
     */
    public function __construct(
        private readonly OAuthClient $client,
        OAuthTokens $tokens,
        private readonly ?Closure $persist = null,
    ) {
        $this->tokens = $tokens;
    }

    public function accessToken(): string
    {
        if (!$this->tokens->valid()) {
            $this->refresh();
        }

        return $this->tokens->accessToken;
    }

    /**
     * Force a refresh, e.g. after the API answered 401.
     */
    public function refresh(): void
    {
        $this->tokens = $this->client->refresh($this->tokens->refreshToken);
        if ($this->persist !== null) {
            ($this->persist)($this->tokens);
        }
    }

    public function provider(): string
    {
        return $this->client->provider;
    }
}
