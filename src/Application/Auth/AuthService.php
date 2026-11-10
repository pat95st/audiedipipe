<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Auth;

use AudiEdiPipe\Application\Notification\Notifier;
use AudiEdiPipe\Http\Session;
use AudiEdiPipe\Infrastructure\I18n\Translator;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Security\Totp;
use AudiEdiPipe\Infrastructure\Security\WebAuthn\WebAuthnVerifier;
use InvalidArgumentException;

/**
 * Authentication: password, second factor (TOTP or e-mail code) and passkeys.
 */
final class AuthService
{
    public const MAX_ATTEMPTS = 5;
    public const LOCK_MINUTES = 15;
    public const EMAIL_CODE_MINUTES = 10;

    private const SESSION_USER = 'auth_user_id';
    private const SESSION_PENDING = 'auth_pending_user_id';
    private const SESSION_PENDING_AT = 'auth_pending_at';
    private const SESSION_CHALLENGE = 'webauthn_challenge';

    public function __construct(
        private readonly Database $db,
        private readonly Crypto $crypto,
        private readonly Notifier $notifier,
        private readonly Translator $translator,
        private readonly Totp $totp = new Totp(),
    ) {
    }

    public function isLockedOut(string $identifier, string $ip): bool
    {
        $since = gmdate('Y-m-d H:i:s', time() - self::LOCK_MINUTES * 60);
        $count = (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM login_attempts WHERE identifier IN (?, ?) AND attempted_at >= ?',
            ['user:' . strtolower($identifier), 'ip:' . $ip, $since],
        );

        return $count >= self::MAX_ATTEMPTS * 2;
    }

    /**
     * @return array<string, mixed>|null the user on valid credentials
     */
    public function attemptPassword(string $identifier, string $password, string $ip): ?array
    {
        $identifier = trim($identifier);
        if ($identifier === '' || $this->isLockedOut($identifier, $ip)) {
            return null;
        }
        $user = $this->db->fetchOne(
            'SELECT * FROM users WHERE (username = ? OR email = ?) AND active = 1',
            [$identifier, strtolower($identifier)],
        );
        // Always verify against a hash to keep timing similar for unknown users.
        $hash = $user !== null ? (string) $user['password_hash'] : '$2y$10$abcdefghijklmnopqrstuuVf6Tb1v1ZqzZ3QnH0d7mE8zTfKJ5W2e';
        if (!password_verify($password, $hash) || $user === null) {
            $this->recordFailure($identifier, $ip);

            return null;
        }
        if (password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)) {
            $this->db->update('users', ['password_hash' => password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT)], ['id' => (int) $user['id']]);
        }
        $this->clearFailures($identifier, $ip);

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function needsSecondFactor(array $user): bool
    {
        return (bool) $user['totp_enabled'] || (bool) $user['email_2fa_enabled'];
    }

    /**
     * @param array<string, mixed> $user
     */
    public function startSecondFactor(Session $session, array $user): void
    {
        $session->regenerate();
        $session->set(self::SESSION_PENDING, (int) $user['id']);
        $session->set(self::SESSION_PENDING_AT, time());
    }

    /**
     * @return array<string, mixed>|null
     */
    public function pendingUser(Session $session): ?array
    {
        $id = (int) $session->get(self::SESSION_PENDING, 0);
        $at = (int) $session->get(self::SESSION_PENDING_AT, 0);
        if ($id === 0 || $at < time() - 600) {
            return null;
        }

        return $this->db->fetchOne('SELECT * FROM users WHERE id = ? AND active = 1', [$id]);
    }

    /**
     * @param array<string, mixed> $user
     */
    public function sendEmailCode(array $user): bool
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $this->db->execute("UPDATE auth_codes SET used_at = ? WHERE user_id = ? AND purpose = 'login' AND used_at IS NULL", [Database::now(), (int) $user['id']]);
        $this->db->insert('auth_codes', [
            'user_id' => (int) $user['id'],
            'purpose' => 'login',
            'code_hash' => $this->crypto->hmac($code),
            'attempts' => 0,
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::EMAIL_CODE_MINUTES * 60),
            'created_at' => Database::now(),
        ]);
        $locale = (string) ($user['locale'] ?: $this->translator->locale());

        return $this->notifier->sendRaw(
            (string) $user['email'],
            $this->translator->trans('mail.code.subject', ['code' => $code], $locale),
            $this->translator->trans('mail.code.body', ['code' => $code, 'minutes' => self::EMAIL_CODE_MINUTES], $locale),
        );
    }

    /**
     * @param array<string, mixed> $user
     */
    public function verifySecondFactor(array $user, string $method, string $code): bool
    {
        $code = (string) preg_replace('/\D/', '', $code);
        if ($method === 'totp' && (bool) $user['totp_enabled']) {
            $secret = $this->crypto->decrypt((string) $user['totp_secret']);
            $step = $this->totp->verify($secret, $code);
            if ($step === null || $step <= (int) $user['totp_last_step']) {
                return false;
            }
            $this->db->update('users', ['totp_last_step' => $step], ['id' => (int) $user['id']]);

            return true;
        }
        if ($method === 'email' && (bool) $user['email_2fa_enabled']) {
            $row = $this->db->fetchOne(
                "SELECT * FROM auth_codes WHERE user_id = ? AND purpose = 'login' AND used_at IS NULL AND expires_at >= ? ORDER BY id DESC LIMIT 1",
                [(int) $user['id'], Database::now()],
            );
            if ($row === null || (int) $row['attempts'] >= 5) {
                return false;
            }
            if (!hash_equals((string) $row['code_hash'], $this->crypto->hmac($code))) {
                $this->db->update('auth_codes', ['attempts' => (int) $row['attempts'] + 1], ['id' => (int) $row['id']]);

                return false;
            }
            $this->db->update('auth_codes', ['used_at' => Database::now()], ['id' => (int) $row['id']]);

            return true;
        }

        return false;
    }

    /**
     * @param array<string, mixed> $user
     */
    public function login(Session $session, array $user): void
    {
        $session->regenerate();
        $session->remove(self::SESSION_PENDING);
        $session->remove(self::SESSION_PENDING_AT);
        $session->set(self::SESSION_USER, (int) $user['id']);
        $session->set('csrf_token', bin2hex(random_bytes(32)));
        $this->db->update('users', ['last_login_at' => Database::now()], ['id' => (int) $user['id']]);
    }

    public function logout(Session $session): void
    {
        $session->destroy();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentUser(Session $session): ?array
    {
        $id = (int) $session->get(self::SESSION_USER, 0);
        if ($id === 0) {
            return null;
        }
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = ? AND active = 1', [$id]);
        if ($user === null) {
            $session->remove(self::SESSION_USER);
        }

        return $user;
    }

    /**
     * @param array<string, mixed> $user
     *
     * @return array<string, mixed>
     */
    public function passkeyRegistrationOptions(Session $session, WebAuthnVerifier $webauthn, array $user): array
    {
        $challenge = random_bytes(32);
        $session->set(self::SESSION_CHALLENGE, base64_encode($challenge));
        $existing = array_map(
            static fn (array $row): string => WebAuthnVerifier::base64UrlDecode((string) $row['credential_id']),
            $this->db->fetchAll('SELECT credential_id FROM passkeys WHERE user_id = ?', [(int) $user['id']]),
        );

        return $webauthn->creationOptions(
            $challenge,
            'aep-user-' . (int) $user['id'],
            (string) $user['username'],
            (string) ($user['display_name'] ?: $user['username']),
            $existing,
        );
    }

    /**
     * @param array<string, mixed> $credential client response (base64url fields)
     *
     * @return array{credentialId: string, publicKeyPem: string, signCount: int, userVerified: bool}
     */
    public function verifyPasskeyRegistration(Session $session, WebAuthnVerifier $webauthn, array $credential): array
    {
        $challenge = $this->takeChallenge($session);
        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];

        return $webauthn->verifyRegistration(
            WebAuthnVerifier::base64UrlDecode((string) ($response['clientDataJSON'] ?? '')),
            WebAuthnVerifier::base64UrlDecode((string) ($response['attestationObject'] ?? '')),
            $challenge,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function passkeyLoginOptions(Session $session, WebAuthnVerifier $webauthn): array
    {
        $challenge = random_bytes(32);
        $session->set(self::SESSION_CHALLENGE, base64_encode($challenge));

        return $webauthn->requestOptions($challenge);
    }

    /**
     * @param array<string, mixed> $credential
     *
     * @return array<string, mixed> the authenticated user
     */
    public function verifyPasskeyLogin(Session $session, WebAuthnVerifier $webauthn, array $credential): array
    {
        $challenge = $this->takeChallenge($session);
        $rawId = (string) ($credential['rawId'] ?? $credential['id'] ?? '');
        $passkey = $this->db->fetchOne(
            'SELECT p.*, u.active FROM passkeys p JOIN users u ON u.id = p.user_id WHERE p.credential_id = ?',
            [$rawId],
        );
        if ($passkey === null || (int) $passkey['active'] !== 1) {
            throw new InvalidArgumentException('Unknown passkey.');
        }
        $response = is_array($credential['response'] ?? null) ? $credential['response'] : [];
        $userHandle = (string) ($response['userHandle'] ?? '');
        if ($userHandle !== '' && WebAuthnVerifier::base64UrlDecode($userHandle) !== 'aep-user-' . (int) $passkey['user_id']) {
            throw new InvalidArgumentException('User handle mismatch.');
        }
        $signCount = $webauthn->verifyAssertion(
            WebAuthnVerifier::base64UrlDecode((string) ($response['clientDataJSON'] ?? '')),
            WebAuthnVerifier::base64UrlDecode((string) ($response['authenticatorData'] ?? '')),
            WebAuthnVerifier::base64UrlDecode((string) ($response['signature'] ?? '')),
            $challenge,
            (string) $passkey['public_key'],
            (int) $passkey['sign_count'],
        );
        $this->db->update('passkeys', ['sign_count' => $signCount, 'last_used_at' => Database::now()], ['id' => (int) $passkey['id']]);
        $user = $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [(int) $passkey['user_id']]);
        if ($user === null) {
            throw new InvalidArgumentException('User not found.');
        }

        return $user;
    }

    private function takeChallenge(Session $session): string
    {
        $challenge = base64_decode((string) $session->get(self::SESSION_CHALLENGE, ''), true);
        $session->remove(self::SESSION_CHALLENGE);
        if ($challenge === false || strlen($challenge) !== 32) {
            throw new InvalidArgumentException('No pending passkey challenge.');
        }

        return $challenge;
    }

    private function recordFailure(string $identifier, string $ip): void
    {
        $now = Database::now();
        $this->db->insert('login_attempts', ['identifier' => 'user:' . strtolower($identifier), 'attempted_at' => $now]);
        $this->db->insert('login_attempts', ['identifier' => 'ip:' . $ip, 'attempted_at' => $now]);
        $this->db->execute('DELETE FROM login_attempts WHERE attempted_at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
    }

    private function clearFailures(string $identifier, string $ip): void
    {
        $this->db->execute('DELETE FROM login_attempts WHERE identifier IN (?, ?)', ['user:' . strtolower($identifier), 'ip:' . $ip]);
    }
}
