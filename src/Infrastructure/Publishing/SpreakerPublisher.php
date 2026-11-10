<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Publishing;

use AudiEdiPipe\Infrastructure\Http\HttpClient;
use CURLFile;

/**
 * Spreaker API: POST /v2/shows/{show_id}/episodes (multipart, OAuth bearer token).
 */
final class SpreakerPublisher implements Publisher
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $showId,
        private readonly string $accessToken,
    ) {
    }

    public function publish(Episode $episode): PublishResult
    {
        if ($this->showId === '' || $this->accessToken === '') {
            throw new PublishException('Spreaker show id and access token are required.');
        }
        $response = $this->http->request(
            'POST',
            'https://api.spreaker.com/v2/shows/' . rawurlencode($this->showId) . '/episodes',
            ['Authorization' => 'Bearer ' . $this->accessToken],
            [
                'title' => mb_substr($episode->title, 0, 140),
                'description' => $episode->description,
                'explicit' => $episode->explicit ? 'true' : 'false',
                'media_file' => new CURLFile($episode->filePath, $episode->mimeType, basename($episode->filePath)),
            ],
        );
        if (!$response->ok()) {
            throw new PublishException(sprintf('Spreaker responded with %d: %s', $response->status, substr($response->body, 0, 300)));
        }
        $data = $response->json();
        $ep = is_array($data['response']['episode'] ?? null) ? $data['response']['episode'] : [];

        return new PublishResult((string) ($ep['episode_id'] ?? ''), (string) ($ep['site_url'] ?? ''), 'Spreaker episode created');
    }
}
