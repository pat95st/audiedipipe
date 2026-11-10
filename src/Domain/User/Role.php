<?php

declare(strict_types=1);

namespace AudiEdiPipe\Domain\User;

/**
 * User roles. Administrators may do everything, cutters handle manual
 * editing stages and uploads, reviewers approve or reject review stages.
 */
enum Role: string
{
    case Admin = 'admin';
    case Cutter = 'cutter';
    case Reviewer = 'reviewer';

    private const PERMISSIONS = [
        'admin' => ['*'],
        'cutter' => ['jobs.view', 'jobs.upload', 'jobs.edit', 'templates.manage', 'jobs.retry'],
        'reviewer' => ['jobs.view', 'jobs.review'],
    ];

    public function can(string $permission): bool
    {
        $permissions = self::PERMISSIONS[$this->value];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public static function fromString(string $value): self
    {
        return self::tryFrom($value) ?? self::Cutter;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $r): string => $r->value, self::cases());
    }
}
