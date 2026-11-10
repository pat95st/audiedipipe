<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Job;

enum JobStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case WaitingManual = 'waiting_manual';
    case WaitingReview = 'waiting_review';
    case Success = 'success';
    case Failed = 'failed';
    case Rejected = 'rejected';
    case Canceled = 'canceled';

    public function isFinal(): bool
    {
        return in_array($this, [self::Success, self::Failed, self::Rejected, self::Canceled], true);
    }

    public function isWaiting(): bool
    {
        return $this === self::WaitingManual || $this === self::WaitingReview;
    }

    public function badge(): string
    {
        return match ($this) {
            self::Queued => 'secondary',
            self::Running => 'primary',
            self::WaitingManual, self::WaitingReview => 'warning',
            self::Success => 'success',
            self::Failed, self::Rejected => 'danger',
            self::Canceled => 'dark',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Queued => 'bi-clock',
            self::Running => 'bi-arrow-repeat',
            self::WaitingManual => 'bi-scissors',
            self::WaitingReview => 'bi-eye',
            self::Success => 'bi-check-circle-fill',
            self::Failed => 'bi-x-circle-fill',
            self::Rejected => 'bi-hand-thumbs-down-fill',
            self::Canceled => 'bi-slash-circle',
        };
    }
}
