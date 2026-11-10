<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Publishing;

use AudiEdiPipe\Infrastructure\Http\HttpClient;

/**
 * Podbean API: OAuth client credentials -> upload authorization -> PUT to
 * presigned URL -> create episode.
 */
final class PodbeanPublisher implements Publisher
{
    private const API = 'https://api.podbean.com/v1';

    public function __construct(
        private readonly HttpClient $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $status = 'publish',
    ) {
    }

    public function publish(Episode $episode): PublishResult
    {
        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new PublishException('Podbean client id and secret are required.');
        }
        $token = $this->http->request('POST', self::API . '/oauth/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query(['grant_type' => 'client_credentials']), ['auth' => [$this->clientId, $this->clientSecret]]);
        $accessToken = (string) ($token->json()['access_token'] ?? '');
        if (!$token->ok() || $accessToken === '') {
            throw new PublishException('Podbean authentication failed: ' . substr($token->body, 0, 300));
        }

        $authorize = $this->http->request('GET', self::API . '/files/uploadAuthorize?' . http_build_query([
            'access_token' => $accessToken,
            'filename' => basename($episode->filePath),
            'filesize' => (int) filesize($episode->filePath),
            'content_type' => $episode->mimeType,
        ]));
        $auth = $authorize->json();
        $presigned = (string) ($auth['presigned_url'] ?? '');
        $fileKey = (string) ($auth['file_key'] ?? '');
        if (!$authorize->ok() || $presigned === '' || $fileKey === '') {
            throw new PublishException('Podbean upload authorization failed: ' . substr($authorize->body, 0, 300));
        }

        $upload = $this->http->request('PUT', $presigned, ['Content-Type' => $episode->mimeType], null, ['upload_file' => $episode->filePath]);
        if (!$upload->ok()) {
            throw new PublishException(sprintf('Podbean file upload failed (%d).', $upload->status));
        }

        $create = $this->http->request('POST', self::API . '/episodes', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'access_token' => $accessToken,
            'title' => $episode->title,
            'content' => $episode->description !== '' ? $episode->description : $episode->title,
            'status' => in_array($this->status, ['publish', 'draft'], true) ? $this->status : 'publish',
            'type' => 'public',
            'media_key' => $fileKey,
            'apple_episode_type' => 'full',
            'content_explicit' => $episode->explicit ? 'explicit' : 'clean',
        ]));
        if (!$create->ok()) {
            throw new PublishException('Podbean episode creation failed: ' . substr($create->body, 0, 300));
        }
        $data = $create->json();
        $ep = is_array($data['episode'] ?? null) ? $data['episode'] : [];

        return new PublishResult((string) ($ep['id'] ?? ''), (string) ($ep['permalink_url'] ?? ''), 'Podbean episode created');
    }
}
