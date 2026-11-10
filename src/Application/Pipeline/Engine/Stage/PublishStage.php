<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Engine\Stage;

use AudiEdiPipe\Application\Pipeline\Engine\StageContext;
use AudiEdiPipe\Application\Pipeline\Engine\StageHandler;
use AudiEdiPipe\Application\Pipeline\Engine\StageOutcome;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Publishing\Episode;
use AudiEdiPipe\Infrastructure\Publishing\PublisherFactory;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Support\Json;
use Throwable;

/**
 * Uploads the exported file to the configured podcast platforms.
 */
final class PublishStage implements StageHandler
{
    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
        private readonly PublisherFactory $publishers,
    ) {
    }

    public function supports(string $type): bool
    {
        return $type === 'publish';
    }

    public function run(StageContext $context): StageOutcome
    {
        $file = (string) ($context->job['output_path'] ?? '');
        if ($file === '' || !is_file($file)) {
            return StageOutcome::failed('No exported file found - add an export stage before publishing.');
        }
        $targetIds = array_map('intval', (array) ($context->params['target_ids'] ?? []));
        if ($targetIds === []) {
            return StageOutcome::skipped('No publish targets selected.');
        }
        $metadata = $context->metadata;
        $episode = new Episode(
            $file,
            (string) ($metadata['_export_mime'] ?? 'audio/mpeg'),
            (string) (($metadata['title'] ?? '') !== '' ? $metadata['title'] : pathinfo($file, PATHINFO_FILENAME)),
            (string) (($metadata['description'] ?? '') !== '' ? $metadata['description'] : ($metadata['comment'] ?? '')),
            (int) round((float) ($metadata['_duration_out'] ?? $metadata['_duration'] ?? 0)),
            $context->jobId(),
            is_numeric($metadata['episode'] ?? null) ? (int) $metadata['episode'] : null,
            is_numeric($metadata['season'] ?? null) ? (int) $metadata['season'] : null,
        );
        $errors = [];
        foreach ($targetIds as $targetId) {
            $row = $this->db->fetchOne('SELECT * FROM publish_targets WHERE id = ? AND enabled = 1', [$targetId]);
            if ($row === null) {
                $context->log('Target #' . $targetId . ' not found or disabled - skipped.');
                continue;
            }
            $row['config'] = $this->crypto->decryptFields(Json::decode($row['config']), PublisherFactory::SECRET_FIELDS);
            try {
                $result = $this->publishers->create($row)->publish($episode);
                $context->log(sprintf('%s: %s %s', (string) $row['name'], $result->message, $result->url));
            } catch (Throwable $e) {
                $errors[] = (string) $row['name'] . ': ' . $e->getMessage();
                $context->log('ERROR ' . (string) $row['name'] . ': ' . $e->getMessage());
            }
        }

        return $errors === [] ? StageOutcome::success() : StageOutcome::failed(implode('; ', $errors));
    }
}
