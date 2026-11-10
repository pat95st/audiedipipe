<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Security;

use AudiEdiPipe\Infrastructure\Security\Base32;
use AudiEdiPipe\Infrastructure\Security\Totp;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TotpTest extends TestCase
{
    private const RFC_SECRET = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ'; // "12345678901234567890"

    /**
     * RFC 6238 Appendix B test vectors (SHA-1, 8 digits).
     *
     * @return list<array{int, string}>
     */
    public static function rfcVectors(): array
    {
        return [
            [59, '94287082'],
            [1111111109, '07081804'],
            [1111111111, '14050471'],
            [1234567890, '89005924'],
            [2000000000, '69279037'],
        ];
    }

    #[DataProvider('rfcVectors')]
    public function test_rfc6238_vectors(int $timestamp, string $expected): void
    {
        $totp = new Totp(digits: 8);
        self::assertSame($expected, $totp->code(self::RFC_SECRET, $timestamp));
    }

    public function test_generate_secret_is_valid_base32_of_requested_length(): void
    {
        $secret = Totp::generateSecret(20);
        self::assertSame(20, strlen(Base32::decode($secret)));
    }

    public function test_verify_accepts_current_code_and_returns_step(): void
    {
        $totp = new Totp();
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $code = $totp->code($secret, $now);
        self::assertSame($totp->step($now), $totp->verify($secret, $code, 1, $now));
    }

    public function test_verify_accepts_previous_window_but_rejects_far_codes(): void
    {
        $totp = new Totp();
        $secret = Totp::generateSecret();
        $now = 1_700_000_000;
        $previous = $totp->code($secret, $now - 30);
        self::assertNotNull($totp->verify($secret, $previous, 1, $now));

        $tooOld = $totp->code($secret, $now - 300);
        self::assertNull($totp->verify($secret, $tooOld, 1, $now));
    }

    public function test_verify_rejects_wrong_length(): void
    {
        $totp = new Totp();
        self::assertNull($totp->verify(Totp::generateSecret(), '123', 1));
    }

    public function test_provisioning_uri_contains_issuer_and_secret(): void
    {
        $totp = new Totp();
        $uri = $totp->provisioningUri('ABC234', 'alice@example.org', 'audiEdiPipe');
        self::assertStringStartsWith('otpauth://totp/', $uri);
        self::assertStringContainsString('secret=ABC234', $uri);
        self::assertStringContainsString('issuer=audiEdiPipe', $uri);
    }
}
