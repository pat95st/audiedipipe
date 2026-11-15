<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Source;

use AudiEdiPipe\Application\Source\Command\DeleteSource;
use AudiEdiPipe\Application\Source\Command\SaveSource;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Storage\Cloud\CloudProviders;
use AudiEdiPipe\Infrastructure\Storage\StorageFactory;
use AudiEdiPipe\Infrastructure\Support\Json;

final class SourceCommandHandler implements HandlerProvider
{
    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
        private readonly ?CloudProviders $cloud = null,
    ) {
    }

    public static function handledMessages(): array
    {
        return [
            SaveSource::class => 'save',
            DeleteSource::class => 'delete',
        ];
    }

    public function save(SaveSource $command): int
    {
        if (trim($command->name) === '') {
            throw new ValidationException('sources.error.name_required');
        }
        if (!in_array($command->type, StorageFactory::TYPES, true)) {
            throw new ValidationException('sources.error.invalid_type');
        }
        $config = [];
        foreach ($command->config as $key => $value) {
            if (is_string($key) && is_scalar($value) && !in_array($key, StorageFactory::TOKEN_FIELDS, true)) {
                $config[$key] = trim((string) $value);
            }
        }
        $existing = $command->id !== null ? $this->db->fetchOne('SELECT type, config FROM sources WHERE id = ?', [$command->id]) : null;
        if ($command->id !== null && $existing === null) {
            throw new ValidationException('sources.error.not_found');
        }
        $typeUnchanged = $existing !== null && (string) $existing['type'] === $command->type;
        // Cloud providers must be enabled under Settings > Cloud storage (existing locations keep working).
        if (in_array($command->type, CloudProviders::TYPES, true) && !$typeUnchanged && $this->cloud?->enabled($command->type) !== true) {
            throw new ValidationException('sources.error.provider_disabled');
        }
        // Empty secret fields on update mean "keep the stored secret".
        if ($existing !== null) {
            $old = Json::decode($existing['config']);
            foreach (StorageFactory::SECRET_FIELDS as $secret) {
                if (!in_array($secret, StorageFactory::TOKEN_FIELDS, true) && ($config[$secret] ?? '') === '' && isset($old[$secret])) {
                    $config[$secret] = $old[$secret];
                }
            }
            // The connected cloud account stays connected unless the type changes.
            if ($typeUnchanged) {
                foreach (StorageFactory::TOKEN_FIELDS as $field) {
                    if (isset($old[$field])) {
                        $config[$field] = $old[$field];
                    }
                }
            }
        }
        $config = $this->crypto->encryptFields($config, StorageFactory::SECRET_FIELDS);
        $filter = self::normalizeFilter($command->filter);
        $data = [
            'name' => mb_substr(trim($command->name), 0, 190),
            'type' => $command->type,
            'config' => Json::encode($config),
            'path' => $command->path === '' ? '/' : mb_substr($command->path, 0, 1000),
            'filter' => Json::encode($filter),
            'poll_interval' => max(60, $command->pollInterval),
            'after_import' => in_array($command->afterImport, ['keep', 'delete'], true) ? $command->afterImport : 'keep',
            'enabled' => $command->enabled,
            'updated_at' => Database::now(),
        ];
        if ($command->id !== null) {
            $this->db->update('sources', $data, ['id' => $command->id]);

            return $command->id;
        }
        $data['created_at'] = Database::now();

        return $this->db->insert('sources', $data);
    }

    public function delete(DeleteSource $command): void
    {
        $this->db->execute('UPDATE pipelines SET source_id = NULL WHERE source_id = ?', [$command->id]);
        $this->db->delete('seen_files', ['source_id' => $command->id]);
        $this->db->delete('sources', ['id' => $command->id]);
    }

    /**
     * @param array<mixed> $filter
     *
     * @return array{include: string, exclude: string, regex: string, extensions: string, min_size_kb: int, min_age_seconds: int, recursive: bool}
     */
    public static function normalizeFilter(array $filter): array
    {
        $regex = trim((string) ($filter['regex'] ?? ''));
        if ($regex !== '' && @preg_match('#' . str_replace('#', '\#', $regex) . '#', '') === false) {
            throw new ValidationException('sources.error.invalid_regex');
        }

        return [
            'include' => trim((string) ($filter['include'] ?? '')),
            'exclude' => trim((string) ($filter['exclude'] ?? '')),
            'regex' => $regex,
            'extensions' => strtolower(trim((string) ($filter['extensions'] ?? 'wav,mp3,flac,m4a,aac,ogg,opus,aiff'))),
            'min_size_kb' => max(0, (int) ($filter['min_size_kb'] ?? 0)),
            'max_size_mb' => max(0, (int) ($filter['max_size_mb'] ?? 0)),
            'min_age_seconds' => max(0, (int) ($filter['min_age_seconds'] ?? 60)),
            'recursive' => ($filter['recursive'] ?? false) === true || ($filter['recursive'] ?? '') === '1',
        ];
    }
}
