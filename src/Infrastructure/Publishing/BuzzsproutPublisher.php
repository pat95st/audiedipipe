<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Publishing;

use AudiEdiPipe\Infrastructure\Http\HttpClient;
use CURLFile;

/**
 * Buzzsprout API: POST /api/{podcast_id}/episodes.json with the audio file.
 */
final class BuzzsproutPublisher implements Publisher
{
    public function __construct(
        private readonly HttpClient $http,
        private readonly string $podcastId,
        private readonly string $apiToken,
        private readonly bool $publishImmediately = true,
    ) {
    }

    public function publish(Episode $episode): PublishResult
    {
        if ($this->podcastId === '' || $this->apiToken === '') {
            throw new PublishException('Buzzsprout podcast id and API token are required.');
        }
        $fields = [
            'title' => $episode->title,
            'description' => $episode->description,
            'explicit' => $episode->explicit ? 'true' : 'false',
            'private' => 'false',
            'audio_file' => new CURLFile($episode->filePath, $episode->mimeType, basename($episode->filePath)),
        ];
        if (!$this->publishImmediately) {
            // Schedule far in the future = draft that can be published manually.
            $fields['published_at'] = gmdate('c', time() + 10 * 365 * 86400);
        }
        if ($episode->episodeNumber !== null) {
            $fields['episode_number'] = (string) $episode->episodeNumber;
        }
        if ($episode->seasonNumber !== null) {
            $fields['season_number'] = (string) $episode->seasonNumber;
        }
        $response = $this->http->request(
            'POST',
            'https://www.buzzsprout.com/api/' . rawurlencode($this->podcastId) . '/episodes.json',
            ['Authorization' => 'Token token=' . $this->apiToken],
            $fields,
        );
        if (!$response->ok()) {
            throw new PublishException(sprintf('Buzzsprout responded with %d: %s', $response->status, substr($response->body, 0, 300)));
        }
        $data = $response->json();

        return new PublishResult((string) ($data['id'] ?? ''), (string) ($data['audio_url'] ?? ''), 'Buzzsprout episode created');
    }
}
