<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Publishing;

use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Storage\StorageAdapter;

/**
 * Publishes to a podcast RSS feed hosted by audiEdiPipe itself (/feed/{slug}).
 * Spotify, Apple Podcasts, Amazon Music, Deezer, YouTube Music etc. ingest
 * podcasts via RSS, so submitting this feed URL once to each platform makes
 * every new episode appear there automatically.
 *
 * The audio is copied to storage/media/{slug}/ (served by /media/...) or,
 * if configured, uploaded to an external storage connection with a public URL.
 */
final class RssFeedPublisher implements Publisher
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        private readonly Database $db,
        private readonly int $targetId,
        private readonly array $config,
        private readonly string $mediaDirectory,
        private readonly string $appUrl,
        private readonly ?StorageAdapter $externalStorage = null,
    ) {
    }

    public function publish(Episode $episode): PublishResult
    {
        $slug = (string) ($this->config['slug'] ?? 'feed');
        $fileName = gmdate('Ymd-His') . '-' . self::safeName(basename($episode->filePath));
        if ($this->externalStorage !== null) {
            $remoteDir = rtrim((string) ($this->config['storage_path'] ?? '/'), '/');
            $this->externalStorage->upload($episode->filePath, $remoteDir . '/' . $fileName);
            $base = rtrim((string) ($this->config['public_base_url'] ?? ''), '/');
            if ($base === '') {
                throw new PublishException('A public base URL is required when using external storage.');
            }
            $url = $base . '/' . rawurlencode($fileName);
        } else {
            $dir = $this->mediaDirectory . '/' . $slug;
            if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new PublishException('Cannot create media directory.');
            }
            if (!@copy($episode->filePath, $dir . '/' . $fileName)) {
                throw new PublishException('Cannot copy episode into media directory.');
            }
            $url = rtrim($this->appUrl, '/') . '/media/' . rawurlencode($slug) . '/' . rawurlencode($fileName);
        }
        $guid = bin2hex(random_bytes(16));
        $id = $this->db->insert('feed_items', [
            'target_id' => $this->targetId,
            'job_id' => $episode->jobId,
            'guid' => $guid,
            'title' => mb_substr($episode->title, 0, 255),
            'description' => $episode->description,
            'file_url' => $url,
            'file_size' => (int) filesize($episode->filePath),
            'mime_type' => $episode->mimeType,
            'duration' => $episode->duration,
            'image_url' => $episode->imageUrl !== '' ? $episode->imageUrl : null,
            'published_at' => Database::now(),
        ]);

        return new PublishResult((string) $id, rtrim($this->appUrl, '/') . '/feed/' . $slug, 'Added to RSS feed');
    }

    public static function safeName(string $name): string
    {
        $clean = (string) preg_replace('/[^a-zA-Z0-9._-]+/', '-', $name);

        return trim($clean, '-') ?: 'episode';
    }
}
