<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Publish;

use AudiEdiPipe\Application\Publish\Command\DeletePublishTarget;
use AudiEdiPipe\Application\Publish\Command\SavePublishTarget;
use AudiEdiPipe\Application\Publish\Query\GetFeed;
use AudiEdiPipe\Application\Publish\Query\GetPublishTarget;
use AudiEdiPipe\Application\Publish\Query\ListPublishTargets;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Publishing\PublisherFactory;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Support\Json;

final class PublishTargetHandler implements HandlerProvider
{
    public function __construct(private readonly Database $db, private readonly Crypto $crypto)
    {
    }

    public static function handledMessages(): array
    {
        return [
            SavePublishTarget::class => 'save',
            DeletePublishTarget::class => 'delete',
            ListPublishTargets::class => 'list',
            GetPublishTarget::class => 'get',
            GetFeed::class => 'feed',
        ];
    }

    public function save(SavePublishTarget $command): int
    {
        if (trim($command->name) === '') {
            throw new ValidationException('publish.error.name_required');
        }
        if (!in_array($command->type, PublisherFactory::TYPES, true)) {
            throw new ValidationException('publish.error.invalid_type');
        }
        $config = [];
        foreach ($command->config as $key => $value) {
            if (is_string($key) && is_scalar($value)) {
                $config[$key] = trim((string) $value);
            }
        }
        if ($command->id !== null) {
            $existing = $this->db->fetchOne('SELECT config FROM publish_targets WHERE id = ?', [$command->id]);
            if ($existing === null) {
                throw new ValidationException('publish.error.not_found');
            }
            $old = Json::decode($existing['config']);
            foreach (PublisherFactory::SECRET_FIELDS as $secret) {
                if (($config[$secret] ?? '') === '' && isset($old[$secret])) {
                    $config[$secret] = $old[$secret];
                }
            }
        }
        $slug = null;
        if ($command->type === 'rss') {
            $slug = strtolower(trim((string) preg_replace('/[^a-zA-Z0-9]+/', '-', (string) ($config['slug'] ?? $command->name)), '-'));
            if ($slug === '') {
                $slug = 'feed';
            }
            $clash = $this->db->fetchValue('SELECT id FROM publish_targets WHERE slug = ? AND id <> ?', [$slug, $command->id ?? 0]);
            if ($clash !== null) {
                throw new ValidationException('publish.error.slug_taken');
            }
            $config['slug'] = $slug;
        }
        $data = [
            'name' => mb_substr(trim($command->name), 0, 190),
            'type' => $command->type,
            'slug' => $slug,
            'config' => Json::encode($this->crypto->encryptFields($config, PublisherFactory::SECRET_FIELDS)),
            'enabled' => $command->enabled,
            'updated_at' => Database::now(),
        ];
        if ($command->id !== null) {
            $this->db->update('publish_targets', $data, ['id' => $command->id]);

            return $command->id;
        }
        $data['created_at'] = Database::now();

        return $this->db->insert('publish_targets', $data);
    }

    public function delete(DeletePublishTarget $command): void
    {
        $this->db->delete('feed_items', ['target_id' => $command->id]);
        $this->db->delete('publish_targets', ['id' => $command->id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(ListPublishTargets $query): array
    {
        return array_map(
            fn (array $row): array => $this->hydrate($row, false),
            $this->db->fetchAll('SELECT * FROM publish_targets ORDER BY name'),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(GetPublishTarget $query): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM publish_targets WHERE id = ?', [$query->id]);

        return $row === null ? null : $this->hydrate($row, $query->withSecrets);
    }

    /**
     * @return array{target: array<string, mixed>, items: list<array<string, mixed>>}|null
     */
    public function feed(GetFeed $query): ?array
    {
        $row = $this->db->fetchOne("SELECT * FROM publish_targets WHERE slug = ? AND type = 'rss' AND enabled = 1", [$query->slug]);
        if ($row === null) {
            return null;
        }
        $items = $this->db->fetchAll(
            'SELECT * FROM feed_items WHERE target_id = ? ORDER BY published_at DESC LIMIT 500',
            [(int) $row['id']],
        );

        return ['target' => $this->hydrate($row, false), 'items' => $items];
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function hydrate(array $row, bool $withSecrets): array
    {
        $config = Json::decode($row['config']);
        if ($withSecrets) {
            $config = $this->crypto->decryptFields($config, PublisherFactory::SECRET_FIELDS);
        } else {
            foreach (PublisherFactory::SECRET_FIELDS as $secret) {
                if (isset($config[$secret]) && $config[$secret] !== '') {
                    $config[$secret] = '';
                    $config[$secret . '_set'] = true;
                }
            }
        }
        $row['config'] = $config;
        $row['enabled'] = (bool) $row['enabled'];

        return $row;
    }
}
