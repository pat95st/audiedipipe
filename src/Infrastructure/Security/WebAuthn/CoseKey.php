<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Security\WebAuthn;

use InvalidArgumentException;

/**
 * Converts COSE public keys (RFC 9053) to PEM so they can be used with openssl.
 * Supports ES256 (EC2 / P-256) and RS256 (RSA).
 */
final class CoseKey
{
    public const ALG_ES256 = -7;
    public const ALG_RS256 = -257;

    /**
     * @param array<int|string, mixed> $cose
     */
    public static function toPem(array $cose): string
    {
        $kty = $cose[1] ?? null;
        if ($kty === 2) {
            $x = $cose[-2] ?? null;
            $y = $cose[-3] ?? null;
            if (($cose[-1] ?? null) !== 1 || !$x instanceof ByteString || !$y instanceof ByteString) {
                throw new InvalidArgumentException('Only P-256 EC keys are supported.');
            }
            if (strlen($x->bytes) !== 32 || strlen($y->bytes) !== 32) {
                throw new InvalidArgumentException('Invalid EC coordinates.');
            }
            $der = (string) hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . "\x04" . $x->bytes . $y->bytes;

            return self::pem($der);
        }
        if ($kty === 3) {
            $n = $cose[-1] ?? null;
            $e = $cose[-2] ?? null;
            if (!$n instanceof ByteString || !$e instanceof ByteString) {
                throw new InvalidArgumentException('Invalid RSA key.');
            }
            $rsaKey = self::sequence(self::integer($n->bytes) . self::integer($e->bytes));
            $algorithm = self::sequence((string) hex2bin('06092a864886f70d0101010500'));
            $der = self::sequence($algorithm . self::bitString($rsaKey));

            return self::pem($der);
        }
        throw new InvalidArgumentException('Unsupported COSE key type.');
    }

    private static function pem(string $der): string
    {
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    private static function length(int $length): string
    {
        if ($length < 0x80) {
            return chr($length);
        }
        $bytes = ltrim(pack('N', $length), "\x00");

        return chr(0x80 | strlen($bytes)) . $bytes;
    }

    private static function sequence(string $content): string
    {
        return "\x30" . self::length(strlen($content)) . $content;
    }

    private static function integer(string $bytes): string
    {
        $bytes = ltrim($bytes, "\x00");
        if ($bytes === '' || (ord($bytes[0]) & 0x80) !== 0) {
            $bytes = "\x00" . $bytes;
        }

        return "\x02" . self::length(strlen($bytes)) . $bytes;
    }

    private static function bitString(string $content): string
    {
        return "\x03" . self::length(strlen($content) + 1) . "\x00" . $content;
    }
}
