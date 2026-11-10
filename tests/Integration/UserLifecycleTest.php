<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Integration;

use AudiEdiPipe\Application\User\Command\CreateUser;
use AudiEdiPipe\Application\User\Query\GetUser;
use AudiEdiPipe\Application\User\Query\ListUsers;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\CommandBus;
use AudiEdiPipe\Cqrs\QueryBus;
use AudiEdiPipe\Tests\Support\TestCase;

/**
 * Exercises the full CQRS stack (buses + handlers + database) end to end.
 */
final class UserLifecycleTest extends TestCase
{
    public function test_create_user_then_query_through_the_buses(): void
    {
        $app = $this->installedApp();
        $commands = $app->get(CommandBus::class);
        $queries = $app->get(QueryBus::class);

        $id = $commands->dispatch(new CreateUser(
            username: 'alice',
            email: 'Alice@Example.ORG',
            password: 'correct horse battery',
            role: 'admin',
        ));
        self::assertIsInt($id);
        self::assertGreaterThan(0, $id);

        $user = $queries->ask(new GetUser($id));
        self::assertNotNull($user);
        self::assertSame('alice', $user['username']);
        self::assertSame('alice@example.org', $user['email'], 'email should be lower-cased');
        self::assertSame('admin', $user['role']);

        $all = $queries->ask(new ListUsers());
        self::assertCount(1, $all);

        // password hash must never be exposed by the read model
        self::assertArrayNotHasKey('password_hash', $user);
    }

    public function test_short_password_is_rejected_and_nothing_is_written(): void
    {
        $app = $this->installedApp();
        $commands = $app->get(CommandBus::class);
        $queries = $app->get(QueryBus::class);

        try {
            $commands->dispatch(new CreateUser('bob', 'bob@example.org', 'short', 'cutter'));
            self::fail('expected ValidationException');
        } catch (ValidationException $e) {
            self::assertSame('users.error.password_too_short', $e->getMessage());
        }

        self::assertCount(0, $queries->ask(new ListUsers()), 'failed command must not persist a user');
    }

    public function test_duplicate_username_is_rejected(): void
    {
        $app = $this->installedApp();
        $commands = $app->get(CommandBus::class);

        $commands->dispatch(new CreateUser('carol', 'carol@example.org', 'longenoughpassword', 'cutter'));

        $this->expectException(ValidationException::class);
        $commands->dispatch(new CreateUser('carol', 'carol2@example.org', 'longenoughpassword', 'cutter'));
    }
}
