<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\User;

use AudiEdiPipe\Application\User\Command\ChangePassword;
use AudiEdiPipe\Application\User\Command\CreateUser;
use AudiEdiPipe\Application\User\Command\DeletePasskey;
use AudiEdiPipe\Application\User\Command\DeleteUser;
use AudiEdiPipe\Application\User\Command\DisableTotp;
use AudiEdiPipe\Application\User\Command\EnableTotp;
use AudiEdiPipe\Application\User\Command\RegisterPasskey;
use AudiEdiPipe\Application\User\Command\SetEmailTwoFactor;
use AudiEdiPipe\Application\User\Command\UpdateProfile;
use AudiEdiPipe\Application\User\Command\UpdateUser;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Domain\User\Role;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Security\Totp;
use AudiEdiPipe\Infrastructure\Security\WebAuthn\WebAuthnVerifier;

final class UserCommandHandler implements HandlerProvider
{
    public const MIN_PASSWORD_LENGTH = 10;

    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
        private readonly Totp $totp = new Totp(),
    ) {
    }

    public static function handledMessages(): array
    {
        return [
            CreateUser::class => 'createUser',
            UpdateUser::class => 'updateUser',
            DeleteUser::class => 'deleteUser',
            UpdateProfile::class => 'updateProfile',
            ChangePassword::class => 'changePassword',
            EnableTotp::class => 'enableTotp',
            DisableTotp::class => 'disableTotp',
            SetEmailTwoFactor::class => 'setEmailTwoFactor',
            RegisterPasskey::class => 'registerPasskey',
            DeletePasskey::class => 'deletePasskey',
        ];
    }

    public function createUser(CreateUser $command): int
    {
        $this->validateIdentity($command->username, $command->email, null);
        $this->validatePassword($command->password);
        $now = Database::now();

        return $this->db->insert('users', [
            'username' => $command->username,
            'email' => strtolower($command->email),
            'display_name' => $command->displayName,
            'password_hash' => self::hash($command->password),
            'role' => Role::fromString($command->role)->value,
            'locale' => $command->locale,
            'theme' => 'auto',
            'totp_enabled' => false,
            'totp_last_step' => 0,
            'email_2fa_enabled' => false,
            'active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function updateUser(UpdateUser $command): void
    {
        $this->assertExists($command->id);
        $this->validateIdentity($command->username, $command->email, $command->id);
        $role = Role::fromString($command->role);
        if ($role !== Role::Admin || !$command->active) {
            $this->assertNotLastAdmin($command->id);
        }
        $data = [
            'username' => $command->username,
            'email' => strtolower($command->email),
            'display_name' => $command->displayName,
            'role' => $role->value,
            'active' => $command->active,
            'updated_at' => Database::now(),
        ];
        if ($command->password !== null && $command->password !== '') {
            $this->validatePassword($command->password);
            $data['password_hash'] = self::hash($command->password);
        }
        $this->db->update('users', $data, ['id' => $command->id]);
    }

    public function deleteUser(DeleteUser $command): void
    {
        if ($command->id === $command->actorId) {
            throw new ValidationException('users.error.delete_self');
        }
        $this->assertNotLastAdmin($command->id);
        $this->db->delete('users', ['id' => $command->id]);
    }

    public function updateProfile(UpdateProfile $command): void
    {
        $user = $this->assertExists($command->userId);
        $this->validateIdentity((string) $user['username'], $command->email, $command->userId);
        $this->db->update('users', [
            'email' => strtolower($command->email),
            'display_name' => $command->displayName,
            'locale' => $command->locale,
            'theme' => in_array($command->theme, ['auto', 'light', 'dark'], true) ? $command->theme : 'auto',
            'updated_at' => Database::now(),
        ], ['id' => $command->userId]);
    }

    public function changePassword(ChangePassword $command): void
    {
        $user = $this->assertExists($command->userId);
        if (!password_verify($command->currentPassword, (string) $user['password_hash'])) {
            throw new ValidationException('account.error.wrong_password');
        }
        $this->validatePassword($command->newPassword);
        $this->db->update('users', [
            'password_hash' => self::hash($command->newPassword),
            'updated_at' => Database::now(),
        ], ['id' => $command->userId]);
    }

    public function enableTotp(EnableTotp $command): void
    {
        $this->assertExists($command->userId);
        $step = $this->totp->verify($command->secret, $command->code);
        if ($step === null) {
            throw new ValidationException('account.error.invalid_code');
        }
        $this->db->update('users', [
            'totp_secret' => $this->crypto->encrypt($command->secret),
            'totp_enabled' => true,
            'totp_last_step' => $step,
            'updated_at' => Database::now(),
        ], ['id' => $command->userId]);
    }

    public function disableTotp(DisableTotp $command): void
    {
        $this->db->update('users', [
            'totp_secret' => null,
            'totp_enabled' => false,
            'totp_last_step' => 0,
            'updated_at' => Database::now(),
        ], ['id' => $command->userId]);
    }

    public function setEmailTwoFactor(SetEmailTwoFactor $command): void
    {
        $this->db->update('users', [
            'email_2fa_enabled' => $command->enabled,
            'updated_at' => Database::now(),
        ], ['id' => $command->userId]);
    }

    public function registerPasskey(RegisterPasskey $command): int
    {
        $this->assertExists($command->userId);
        $encodedId = WebAuthnVerifier::base64UrlEncode($command->credentialId);
        if ($this->db->fetchValue('SELECT id FROM passkeys WHERE credential_id = ?', [$encodedId]) !== null) {
            throw new ValidationException('account.error.passkey_exists');
        }
        $name = trim($command->name) !== '' ? mb_substr(trim($command->name), 0, 100) : 'Passkey';

        return $this->db->insert('passkeys', [
            'user_id' => $command->userId,
            'credential_id' => $encodedId,
            'public_key' => $command->publicKeyPem,
            'sign_count' => $command->signCount,
            'name' => $name,
            'created_at' => Database::now(),
        ]);
    }

    public function deletePasskey(DeletePasskey $command): void
    {
        $this->db->delete('passkeys', ['id' => $command->passkeyId, 'user_id' => $command->userId]);
    }

    public static function hash(string $password): string
    {
        $algorithm = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;

        return password_hash($password, $algorithm);
    }

    /**
     * @return array<string, mixed>
     */
    private function assertExists(int $id): array
    {
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
        if ($user === null) {
            throw new ValidationException('users.error.not_found');
        }

        return $user;
    }

    private function validateIdentity(string $username, string $email, ?int $exceptId): void
    {
        if (preg_match('/^[a-zA-Z0-9._-]{3,100}$/', $username) !== 1) {
            throw new ValidationException('users.error.invalid_username');
        }
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('users.error.invalid_email');
        }
        $existing = $this->db->fetchOne(
            'SELECT id FROM users WHERE (username = ? OR email = ?) AND id <> ?',
            [$username, strtolower($email), $exceptId ?? 0],
        );
        if ($existing !== null) {
            throw new ValidationException('users.error.duplicate');
        }
    }

    private function validatePassword(string $password): void
    {
        if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH) {
            throw new ValidationException('users.error.password_too_short', ['min' => self::MIN_PASSWORD_LENGTH]);
        }
    }

    private function assertNotLastAdmin(int $userId): void
    {
        $user = $this->db->fetchOne('SELECT role, active FROM users WHERE id = ?', [$userId]);
        if ($user === null || $user['role'] !== Role::Admin->value) {
            return;
        }
        $admins = (int) $this->db->fetchValue("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> ?", [$userId]);
        if ($admins === 0) {
            throw new ValidationException('users.error.last_admin');
        }
    }
}
