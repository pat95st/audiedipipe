<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Security;

/**
 * Time-based one-time passwords (RFC 6238) compatible with common
 * authenticator apps (Google Authenticator, Aegis, 1Password, ...).
 */
final class Totp
{
    public function __construct(
        private readonly int $digits = 6,
        private readonly int $period = 30,
        private readonly string $algorithm = 'sha1',
    ) {
    }

    public static function generateSecret(int $bytes = 20): string
    {
        return Base32::encode(random_bytes(max(1, $bytes)));
    }

    public function code(string $base32Secret, ?int $timestamp = null): string
    {
        return $this->codeForStep($base32Secret, $this->step($timestamp));
    }

    public function step(?int $timestamp = null): int
    {
        return intdiv($timestamp ?? time(), $this->period);
    }

    public function codeForStep(string $base32Secret, int $step): string
    {
        $key = Base32::decode($base32Secret);
        $counter = PHP_INT_SIZE === 8 ? pack('J', $step) : pack('N', 0) . pack('N', $step);
        $hash = hash_hmac($this->algorithm, $counter, $key, true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0F;
        $binary = ((ord($hash[$offset]) & 0x7F) << 24)
            | ((ord($hash[$offset + 1]) & 0xFF) << 16)
            | ((ord($hash[$offset + 2]) & 0xFF) << 8)
            | (ord($hash[$offset + 3]) & 0xFF);
        $otp = $binary % (10 ** $this->digits);

        return str_pad((string) $otp, $this->digits, '0', STR_PAD_LEFT);
    }

    /**
     * Verify a code within +/- $window steps. Returns the matched step or null.
     * Callers must reject steps <= the last used step to prevent replay.
     */
    public function verify(string $base32Secret, string $code, int $window = 1, ?int $timestamp = null): ?int
    {
        $code = (string) preg_replace('/\D/', '', $code);
        if (strlen($code) !== $this->digits) {
            return null;
        }
        $current = $this->step($timestamp);
        for ($i = -$window; $i <= $window; ++$i) {
            if (hash_equals($this->codeForStep($base32Secret, $current + $i), $code)) {
                return $current + $i;
            }
        }

        return null;
    }

    public function provisioningUri(string $base32Secret, string $account, string $issuer): string
    {
        return sprintf(
            'otpauth://totp/%s:%s?secret=%s&issuer=%s&algorithm=%s&digits=%d&period=%d',
            rawurlencode($issuer),
            rawurlencode($account),
            $base32Secret,
            rawurlencode($issuer),
            strtoupper($this->algorithm),
            $this->digits,
            $this->period,
        );
    }
}
