<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Security;

use AudiEdiPipe\Infrastructure\Security\Crypto;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CryptoTest extends TestCase
{
    public function test_encrypt_decrypt_roundtrip(): void
    {
        $crypto = new Crypto(Crypto::generateKey());
        $secret = 'super-secret-ftp-password';
        $cipher = $crypto->encrypt($secret);

        self::assertNotSame($secret, $cipher);
        self::assertTrue(Crypto::isEncrypted($cipher));
        self::assertSame($secret, $crypto->decrypt($cipher));
    }

    public function test_ciphertext_is_randomised_per_call(): void
    {
        $crypto = new Crypto(Crypto::generateKey());
        self::assertNotSame($crypto->encrypt('x'), $crypto->encrypt('x'));
    }

    public function test_decrypt_passes_through_plaintext(): void
    {
        $crypto = new Crypto(Crypto::generateKey());
        self::assertSame('not-encrypted', $crypto->decrypt('not-encrypted'));
    }

    public function test_wrong_key_cannot_decrypt(): void
    {
        $cipher = (new Crypto(Crypto::generateKey()))->encrypt('secret');
        $this->expectException(RuntimeException::class);
        (new Crypto(Crypto::generateKey()))->decrypt($cipher);
    }

    public function test_encrypt_fields_only_touches_listed_secret_keys(): void
    {
        $crypto = new Crypto(Crypto::generateKey());
        $config = ['host' => 'example.org', 'password' => 'pw', 'token' => 't'];
        $encrypted = $crypto->encryptFields($config, ['password', 'token']);

        self::assertSame('example.org', $encrypted['host']);
        self::assertTrue(Crypto::isEncrypted($encrypted['password']));
        self::assertTrue(Crypto::isEncrypted($encrypted['token']));

        $decrypted = $crypto->decryptFields($encrypted, ['password', 'token']);
        self::assertSame('pw', $decrypted['password']);
        self::assertSame('t', $decrypted['token']);
    }

    public function test_hmac_is_stable_for_same_key(): void
    {
        $key = Crypto::generateKey();
        self::assertSame((new Crypto($key))->hmac('data'), (new Crypto($key))->hmac('data'));
    }
}
