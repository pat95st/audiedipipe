<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Publishing;

use AudiEdiPipe\Infrastructure\Http\HttpClient;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Storage\StorageAdapter;
use AudiEdiPipe\Infrastructure\Support\Json;
use Closure;

final class PublisherFactory
{
    public const TYPES = ['rss', 'buzzsprout', 'podbean', 'spreaker'];

    public const SECRET_FIELDS = ['api_token', 'client_secret', 'access_token'];

    public const FIELDS = [
        'rss' => ['slug', 'title', 'description', 'author', 'owner_email', 'language', 'category', 'explicit', 'image_url', 'website', 'storage_id', 'storage_path', 'public_base_url'],
        'buzzsprout' => ['podcast_id', 'api_token', 'publish_immediately'],
        'podbean' => ['client_id', 'client_secret', 'status'],
        'spreaker' => ['show_id', 'access_token'],
    ];

    /**
     * @param Closure(int): ?StorageAdapter $storageResolver resolves a storage connection id
     */
    public function __construct(
        private readonly HttpClient $http,
        private readonly Database $db,
        private readonly string $mediaDirectory,
        private readonly string $appUrl,
        private readonly Closure $storageResolver,
    ) {
    }

    /**
     * @param array<string, mixed> $target target row with decrypted config
     */
    public function create(array $target): Publisher
    {
        $config = is_array($target['config'] ?? null) ? $target['config'] : [];
        $s = static fn (string $key): string => Json::str($config, $key);

        return match ((string) $target['type']) {
            'rss' => new RssFeedPublisher(
                $this->db,
                (int) $target['id'],
                $config,
                $this->mediaDirectory,
                $this->appUrl,
                (int) $s('storage_id') > 0 ? ($this->storageResolver)((int) $s('storage_id')) : null,
            ),
            'buzzsprout' => new BuzzsproutPublisher($this->http, $s('podcast_id'), $s('api_token'), $s('publish_immediately') !== '0'),
            'podbean' => new PodbeanPublisher($this->http, $s('client_id'), $s('client_secret'), $s('status') ?: 'publish'),
            'spreaker' => new SpreakerPublisher($this->http, $s('show_id'), $s('access_token')),
            default => throw new PublishException('Unknown publish target type.'),
        };
    }
}
