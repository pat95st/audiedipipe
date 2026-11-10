<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Support\Json;

/**
 * Builds storage adapters from the stored (encrypted) connection definitions.
 */
final class StorageConnections
{
    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
        private readonly StorageFactory $factory,
    ) {
    }

    public function adapter(int $sourceId): ?StorageAdapter
    {
        $row = $this->db->fetchOne('SELECT type, config FROM sources WHERE id = ?', [$sourceId]);
        if ($row === null) {
            return null;
        }

        return $this->fromConfig((string) $row['type'], Json::decode($row['config']));
    }

    /**
     * @param array<mixed> $config
     */
    public function fromConfig(string $type, array $config): StorageAdapter
    {
        /** @var array<string, mixed> $config */
        return $this->factory->create($type, $this->crypto->decryptFields($config, StorageFactory::SECRET_FIELDS));
    }
}
