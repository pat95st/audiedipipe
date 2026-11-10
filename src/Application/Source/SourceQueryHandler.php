<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Source;

use AudiEdiPipe\Application\Source\Query\GetSource;
use AudiEdiPipe\Application\Source\Query\ListSources;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Storage\StorageFactory;
use AudiEdiPipe\Infrastructure\Support\Json;

final class SourceQueryHandler implements HandlerProvider
{
    public function __construct(private readonly Database $db, private readonly Crypto $crypto)
    {
    }

    public static function handledMessages(): array
    {
        return [
            ListSources::class => 'list',
            GetSource::class => 'get',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function list(ListSources $query): array
    {
        $rows = $this->db->fetchAll(
            'SELECT s.*, (SELECT COUNT(*) FROM seen_files f WHERE f.source_id = s.id) AS imported_count FROM sources s ORDER BY s.name',
        );

        return array_map(fn (array $row): array => $this->hydrate($row, false), $rows);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(GetSource $query): ?array
    {
        $row = $this->db->fetchOne('SELECT * FROM sources WHERE id = ?', [$query->id]);

        return $row === null ? null : $this->hydrate($row, $query->withSecrets);
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
            $config = $this->crypto->decryptFields($config, StorageFactory::SECRET_FIELDS);
        } else {
            foreach (StorageFactory::SECRET_FIELDS as $secret) {
                if (isset($config[$secret]) && $config[$secret] !== '') {
                    $config[$secret] = '';
                    $config[$secret . '_set'] = true;
                }
            }
        }
        $row['config'] = $config;
        $row['filter'] = Json::decode($row['filter']);
        $row['enabled'] = (bool) $row['enabled'];

        return $row;
    }
}
