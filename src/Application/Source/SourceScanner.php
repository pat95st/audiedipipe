<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Source;

use AudiEdiPipe\Application\Job\Command\CreateJob;
use AudiEdiPipe\Application\Notification\Notifier;
use AudiEdiPipe\Cqrs\CommandBus;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Storage\FileFilter;
use AudiEdiPipe\Infrastructure\Storage\StorageConnections;
use AudiEdiPipe\Infrastructure\Support\Json;
use Throwable;

/**
 * Polls watched storage locations for new audio files and starts the
 * pipelines attached to them.
 */
final class SourceScanner
{
    public function __construct(
        private readonly Database $db,
        private readonly StorageConnections $connections,
        private readonly CommandBus $commands,
        private readonly string $uploadDirectory,
        private readonly ?Notifier $notifier = null,
    ) {
    }

    /**
     * @return array<int, array{imported: int, error: ?string}> per source id
     */
    public function scanDue(bool $force = false, ?int $onlySourceId = null): array
    {
        $results = [];
        $sources = $this->db->fetchAll('SELECT * FROM sources WHERE enabled = 1 ORDER BY id');
        foreach ($sources as $source) {
            $id = (int) $source['id'];
            if ($onlySourceId !== null && $id !== $onlySourceId) {
                continue;
            }
            $last = $source['last_scan_at'] !== null ? strtotime((string) $source['last_scan_at'] . ' UTC') : 0;
            if (!$force && $last !== false && $last + (int) $source['poll_interval'] > time()) {
                continue;
            }
            $results[$id] = $this->scan($source);
        }

        return $results;
    }

    /**
     * @param array<string, mixed> $source
     *
     * @return array{imported: int, error: ?string}
     */
    public function scan(array $source): array
    {
        $sourceId = (int) $source['id'];
        $pipelines = $this->db->fetchAll('SELECT id FROM pipelines WHERE source_id = ? AND enabled = 1', [$sourceId]);
        $imported = 0;
        $error = null;
        try {
            if ($pipelines === []) {
                throw new \RuntimeException('No enabled pipeline uses this source.');
            }
            $adapter = $this->connections->fromConfig((string) $source['type'], Json::decode($source['config']));
            $filterConfig = Json::decode($source['filter']);
            $filter = new FileFilter($filterConfig);
            $files = $adapter->listFiles((string) $source['path'], ($filterConfig['recursive'] ?? false) === true);
            foreach ($files as $file) {
                if (!$filter->accepts($file)) {
                    continue;
                }
                $fingerprint = $file->fingerprint();
                $seen = $this->db->fetchValue('SELECT id FROM seen_files WHERE source_id = ? AND fingerprint = ?', [$sourceId, $fingerprint]);
                if ($seen !== null) {
                    continue;
                }
                $dir = $this->uploadDirectory . '/source_' . $sourceId;
                if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
                    throw new \RuntimeException('Cannot create upload directory.');
                }
                $local = $dir . '/' . gmdate('Ymd_His') . '_' . substr($fingerprint, 0, 8) . '_' . self::safe($file->name());
                $adapter->download($file->path, $local);

                $jobIds = [];
                foreach ($pipelines as $pipeline) {
                    $jobIds[] = (int) $this->commands->dispatch(new CreateJob(
                        (int) $pipeline['id'],
                        $file->name(),
                        $local,
                        'source',
                        $sourceId,
                        null,
                        ['_remote_path' => $file->path],
                    ));
                }
                $this->db->insert('seen_files', [
                    'source_id' => $sourceId,
                    'remote_path' => mb_substr($file->path, 0, 1000),
                    'fingerprint' => $fingerprint,
                    'size' => $file->size,
                    'seen_at' => Database::now(),
                ]);
                foreach ($pipelines as $index => $pipeline) {
                    $this->notifier?->newFile((int) $pipeline['id'], (string) $source['name'], $file->name(), [$jobIds[$index]]);
                }
                if ($source['after_import'] === 'delete') {
                    $adapter->delete($file->path);
                }
                ++$imported;
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
        $this->db->update('sources', [
            'last_scan_at' => Database::now(),
            'last_error' => $error,
        ], ['id' => $sourceId]);

        return ['imported' => $imported, 'error' => $error];
    }

    private static function safe(string $name): string
    {
        return trim((string) preg_replace('/[^a-zA-Z0-9._-]+/', '_', $name), '_') ?: 'audio';
    }
}
