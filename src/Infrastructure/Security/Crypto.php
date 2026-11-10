<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Security;

use InvalidArgumentException;
use RuntimeException;

/**
 * Symmetric encryption for secrets stored in the database (storage passwords,
 * API tokens, TOTP secrets) using libsodium secretbox.
 */
final class Crypto
{
    private const PREFIX = 'enc:v1:';

    private string $key;

    public function __construct(string $base64Key)
    {
        $key = base64_decode($base64Key, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            // Derive a key from arbitrary input so a misconfigured key still works deterministically.
            $key = sodium_crypto_generichash($base64Key, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }
        $this->key = $key;
    }

    public static function generateKey(): string
    {
        return base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
    }

    public function encrypt(string $plaintext): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return self::PREFIX . base64_encode($nonce . $cipher);
    }

    public function decrypt(string $value): string
    {
        if (!self::isEncrypted($value)) {
            return $value;
        }
        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new InvalidArgumentException('Malformed encrypted value.');
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException('Unable to decrypt value (wrong app key?).');
        }

        return $plain;
    }

    public static function isEncrypted(string $value): bool
    {
        return str_starts_with($value, self::PREFIX);
    }

    /**
     * Encrypt all secret fields of a configuration array.
     *
     * @param array<string, mixed> $config
     * @param list<string>         $secretKeys
     *
     * @return array<string, mixed>
     */
    public function encryptFields(array $config, array $secretKeys): array
    {
        foreach ($secretKeys as $key) {
            if (isset($config[$key]) && is_string($config[$key]) && $config[$key] !== '' && !self::isEncrypted($config[$key])) {
                $config[$key] = $this->encrypt($config[$key]);
            }
        }

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @param list<string>         $secretKeys
     *
     * @return array<string, mixed>
     */
    public function decryptFields(array $config, array $secretKeys): array
    {
        foreach ($secretKeys as $key) {
            if (isset($config[$key]) && is_string($config[$key])) {
                $config[$key] = $this->decrypt($config[$key]);
            }
        }

        return $config;
    }

    public function hmac(string $data): string
    {
        return hash_hmac('sha256', $data, $this->key);
    }
}
