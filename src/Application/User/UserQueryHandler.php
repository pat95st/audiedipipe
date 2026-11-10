<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\User;

use AudiEdiPipe\Application\User\Query\GetUser;
use AudiEdiPipe\Application\User\Query\ListPasskeys;
use AudiEdiPipe\Application\User\Query\ListUsers;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Infrastructure\Persistence\Database;

final class UserQueryHandler implements HandlerProvider
{
    private const PUBLIC_COLUMNS = 'id, username, email, display_name, role, locale, theme, totp_enabled, email_2fa_enabled, active, last_login_at, created_at';

    public function __construct(private readonly Database $db)
    {
    }

    public static function handledMessages(): array
    {
        return [
            ListUsers::class => 'listUsers',
            GetUser::class => 'getUser',
            ListPasskeys::class => 'listPasskeys',
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listUsers(ListUsers $query): array
    {
        return $this->db->fetchAll('SELECT ' . self::PUBLIC_COLUMNS . ' FROM users ORDER BY username');
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUser(GetUser $query): ?array
    {
        $user = $this->db->fetchOne('SELECT ' . self::PUBLIC_COLUMNS . ' FROM users WHERE id = ?', [$query->id]);
        if ($user !== null) {
            $user['passkey_count'] = (int) $this->db->fetchValue('SELECT COUNT(*) FROM passkeys WHERE user_id = ?', [$query->id]);
        }

        return $user;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listPasskeys(ListPasskeys $query): array
    {
        return $this->db->fetchAll(
            'SELECT id, name, created_at, last_used_at FROM passkeys WHERE user_id = ? ORDER BY created_at',
            [$query->userId],
        );
    }
}
