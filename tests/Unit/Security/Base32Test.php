<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Security;

use AudiEdiPipe\Infrastructure\Security\Base32;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class Base32Test extends TestCase
{
    /**
     * RFC 4648 test vectors.
     *
     * @return list<array{string, string}>
     */
    public static function vectors(): array
    {
        return [
            ['', ''],
            ['f', 'MY======'],
            ['fo', 'MZXQ===='],
            ['foo', 'MZXW6==='],
            ['foob', 'MZXW6YQ='],
            ['fooba', 'MZXW6YTB'],
            ['foobar', 'MZXW6YTBOI======'],
        ];
    }

    #[DataProvider('vectors')]
    public function test_encode_matches_rfc4648(string $plain, string $encoded): void
    {
        // The implementation omits padding; compare against the unpadded form.
        $expected = rtrim($encoded, '=');
        self::assertSame($expected, Base32::encode($plain));
    }

    #[DataProvider('vectors')]
    public function test_decode_is_inverse_of_encode(string $plain): void
    {
        self::assertSame($plain, Base32::decode(Base32::encode($plain)));
    }

    public function test_decode_ignores_spaces_padding_and_case(): void
    {
        self::assertSame('foobar', Base32::decode('mzxw6 ytb-oi======'));
    }

    public function test_decode_rejects_invalid_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Base32::decode('MZXW6YT1'); // '1' is not in the alphabet
    }
}
