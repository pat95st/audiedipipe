<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Domain;

use AudiEdiPipe\Domain\User\Role;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function test_three_roles_exist(): void
    {
        self::assertSame(['admin', 'cutter', 'reviewer'], Role::values());
    }

    public function test_admin_can_do_everything(): void
    {
        self::assertTrue(Role::Admin->can('jobs.review'));
        self::assertTrue(Role::Admin->can('anything.at.all'));
    }

    public function test_cutter_permissions(): void
    {
        self::assertTrue(Role::Cutter->can('jobs.edit'));
        self::assertTrue(Role::Cutter->can('jobs.upload'));
        self::assertFalse(Role::Cutter->can('jobs.review'));
    }

    public function test_reviewer_permissions(): void
    {
        self::assertTrue(Role::Reviewer->can('jobs.review'));
        self::assertFalse(Role::Reviewer->can('jobs.edit'));
    }

    public function test_from_string_defaults_to_cutter_for_unknown(): void
    {
        self::assertSame(Role::Cutter, Role::fromString('nonsense'));
        self::assertSame(Role::Admin, Role::fromString('admin'));
    }
}
