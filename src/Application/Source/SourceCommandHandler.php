<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Source;

use AudiEdiPipe\Application\Source\Command\DeleteSource;
use AudiEdiPipe\Application\Source\Command\SaveSource;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Storage\StorageFactory;
use AudiEdiPipe\Infrastructure\Support\Json;

final class SourceCommandHandler implements HandlerProvider
{
    public function __construct(private readonly Database $db, private readonly Crypto $crypto)
    {
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
            if (is_string($key) && is_scalar($value)) {
                $config[$key] = trim((string) $value);
            }
        }
        // Empty secret fields on update mean "keep the stored secret".
        if ($command->id !== null) {
            $existing = $this->db->fetchOne('SELECT config FROM sources WHERE id = ?', [$command->id]);
            if ($existing === null) {
                throw new ValidationException('sources.error.not_found');
            }
            $old = Json::decode($existing['config']);
            foreach (StorageFactory::SECRET_FIELDS as $secret) {
                if (($config[$secret] ?? '') === '' && isset($old[$secret])) {
                    $config[$secret] = $old[$secret];
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
