<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Pipeline\Engine\Stage;

use AudiEdiPipe\Application\Pipeline\Engine\StageContext;
use AudiEdiPipe\Application\Pipeline\Engine\StageHandler;
use AudiEdiPipe\Application\Pipeline\Engine\StageOutcome;
use AudiEdiPipe\Domain\Job\JobStatus;

/**
 * Quality gate: a reviewer listens to the current result and approves or rejects it.
 */
final class ReviewStage implements StageHandler
{
    public function supports(string $type): bool
    {
        return $type === 'review';
    }

    public function run(StageContext $context): StageOutcome
    {
        $decision = $context->params['decision'] ?? null;
        if (!is_array($decision)) {
            $context->log('Waiting for review.');

            return StageOutcome::wait(JobStatus::WaitingReview);
        }
        if (($decision['approved'] ?? false) === true) {
            $comment = trim((string) ($decision['comment'] ?? ''));
            $context->log('Approved' . ($comment !== '' ? ': ' . $comment : '.'));

            return StageOutcome::success();
        }

        return StageOutcome::failed('Rejected: ' . (string) ($decision['comment'] ?? ''));
    }
}
