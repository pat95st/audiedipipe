<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage\Cloud;

/**
 * OAuth 2.0 token set of a connected cloud account.
 */
final class OAuthTokens
{
    public function __construct(
        public readonly string $accessToken,
        public readonly string $refreshToken,
        public readonly int $expiresAt,
    ) {
    }

    /**
     * @param array<mixed> $data token endpoint response
     */
    public static function fromResponse(array $data, string $previousRefreshToken = '', ?int $now = null): self
    {
        $now ??= time();
        $access = is_string($data['access_token'] ?? null) ? $data['access_token'] : '';
        // Some providers (Google, Dropbox) only send a refresh token on the first exchange.
        $refresh = is_string($data['refresh_token'] ?? null) && $data['refresh_token'] !== '' ? $data['refresh_token'] : $previousRefreshToken;
        $expiresIn = is_numeric($data['expires_in'] ?? null) ? (int) $data['expires_in'] : 3600;

        return new self($access, $refresh, $now + max(60, $expiresIn));
    }

    /**
     * Still usable for at least $margin seconds.
     */
    public function valid(int $margin = 120, ?int $now = null): bool
    {
        return $this->accessToken !== '' && $this->expiresAt - $margin > ($now ?? time());
    }
}
