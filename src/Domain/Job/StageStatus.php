<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\Job;

enum StageStatus: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Waiting = 'waiting';
    case Success = 'success';
    case Failed = 'failed';
    case Skipped = 'skipped';
    case Rejected = 'rejected';
    case Canceled = 'canceled';

    public function badge(): string
    {
        return match ($this) {
            self::Pending => 'secondary',
            self::Running => 'primary',
            self::Waiting => 'warning',
            self::Success => 'success',
            self::Failed, self::Rejected => 'danger',
            self::Skipped, self::Canceled => 'light',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Pending => 'bi-circle',
            self::Running => 'bi-arrow-repeat',
            self::Waiting => 'bi-pause-circle-fill',
            self::Success => 'bi-check-circle-fill',
            self::Failed => 'bi-x-circle-fill',
            self::Rejected => 'bi-hand-thumbs-down-fill',
            self::Skipped => 'bi-skip-forward-circle',
            self::Canceled => 'bi-slash-circle',
        };
    }
}
