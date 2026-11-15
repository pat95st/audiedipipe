<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage\Cloud;

use AudiEdiPipe\Infrastructure\Http\HttpClient;
use AudiEdiPipe\Infrastructure\Storage\StorageException;

/**
 * Minimal OAuth 2.0 authorization code client (RFC 6749) for the cloud storage providers.
 */
final class OAuthClient
{
    /**
     * @param array<string, string> $authorizeParams extra query parameters of the authorization request
     * @param array<string, string> $tokenParams     extra parameters for every token request
     */
    public function __construct(
        private readonly HttpClient $http,
        public readonly string $provider,
        private readonly string $authorizeUrl,
        private readonly string $tokenUrl,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $scope = '',
        private readonly array $authorizeParams = [],
        private readonly array $tokenParams = [],
    ) {
    }

    public function authorizationUrl(string $redirectUri, string $state): string
    {
        $params = [
            'response_type' => 'code',
            'client_id' => $this->clientId,
            'redirect_uri' => $redirectUri,
            'state' => $state,
        ];
        if ($this->scope !== '') {
            $params['scope'] = $this->scope;
        }

        return $this->authorizeUrl . '?' . http_build_query($params + $this->authorizeParams, '', '&', PHP_QUERY_RFC3986);
    }

    public function exchangeCode(string $code, string $redirectUri): OAuthTokens
    {
        $tokens = $this->tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri], '');
        if ($tokens->refreshToken === '') {
            throw new StorageException(sprintf('%s did not return a refresh token. Remove the app access in your account and connect again.', $this->provider));
        }

        return $tokens;
    }

    public function refresh(string $refreshToken): OAuthTokens
    {
        if ($refreshToken === '') {
            throw new StorageException(sprintf('Not connected to %s yet. Open the storage location and click "Connect account".', $this->provider));
        }

        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken], $refreshToken);
    }

    /**
     * @param array<string, string> $params
     */
    private function tokenRequest(array $params, string $previousRefreshToken): OAuthTokens
    {
        $params += ['client_id' => $this->clientId, 'client_secret' => $this->clientSecret] + $this->tokenParams;
        $response = $this->http->request('POST', $this->tokenUrl, [
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], http_build_query($params, '', '&'), ['timeout' => 60]);
        $data = $response->json();
        if (!$response->ok() || !is_string($data['access_token'] ?? null)) {
            $error = $data['error_description'] ?? $data['error'] ?? ('HTTP ' . $response->status);

            throw new StorageException(sprintf('%s authorization failed: %s', $this->provider, is_scalar($error) ? (string) $error : 'unknown error'));
        }

        return OAuthTokens::fromResponse($data, $previousRefreshToken);
    }
}
