<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Security\WebAuthn;

use InvalidArgumentException;

/**
 * Minimal CBOR (RFC 8949) decoder - enough for WebAuthn attestation objects
 * and COSE keys. Byte strings are returned as ByteString to distinguish them
 * from text strings.
 */
final class Cbor
{
    private int $offset = 0;

    private function __construct(private readonly string $data)
    {
    }

    public static function decode(string $data): mixed
    {
        $decoder = new self($data);
        $value = $decoder->item();
        if ($decoder->offset !== strlen($data)) {
            throw new InvalidArgumentException('Unexpected trailing CBOR data.');
        }

        return $value;
    }

    /**
     * Decode the first item and return [value, bytesConsumed]. Used for COSE
     * keys embedded in authenticator data followed by extensions.
     *
     * @return array{0: mixed, 1: int}
     */
    public static function decodeFirst(string $data, int $offset = 0): array
    {
        $decoder = new self($data);
        $decoder->offset = $offset;
        $value = $decoder->item();

        return [$value, $decoder->offset - $offset];
    }

    private function item(): mixed
    {
        $initial = $this->byte();
        $major = $initial >> 5;
        $info = $initial & 0x1F;

        if ($major === 7) {
            return match ($info) {
                20 => false,
                21 => true,
                22, 23 => null,
                25 => $this->halfFloat(),
                26 => unpack('G', $this->read(4))[1] ?? 0.0,
                27 => unpack('E', $this->read(8))[1] ?? 0.0,
                default => throw new InvalidArgumentException('Unsupported CBOR simple value ' . $info),
            };
        }

        $length = $this->length($info);

        return match ($major) {
            0 => $length,
            1 => -1 - $length,
            2 => new ByteString($this->read($length)),
            3 => $this->read($length),
            4 => $this->array($length),
            5 => $this->map($length),
            6 => $this->item(), // tags are ignored, the tagged value is returned
            default => throw new InvalidArgumentException('Unsupported CBOR major type ' . $major),
        };
    }

    private function length(int $info): int
    {
        if ($info < 24) {
            return $info;
        }

        return match ($info) {
            24 => $this->byte(),
            25 => (int) (unpack('n', $this->read(2))[1] ?? 0),
            26 => (int) (unpack('N', $this->read(4))[1] ?? 0),
            27 => (int) (unpack('J', $this->read(8))[1] ?? 0),
            default => throw new InvalidArgumentException('Indefinite length CBOR is not supported.'),
        };
    }

    /**
     * @return list<mixed>
     */
    private function array(int $length): array
    {
        $items = [];
        for ($i = 0; $i < $length; ++$i) {
            $items[] = $this->item();
        }

        return $items;
    }

    /**
     * @return array<int|string, mixed>
     */
    private function map(int $length): array
    {
        $map = [];
        for ($i = 0; $i < $length; ++$i) {
            $key = $this->item();
            if ($key instanceof ByteString) {
                $key = $key->bytes;
            }
            if (!is_int($key) && !is_string($key)) {
                throw new InvalidArgumentException('Unsupported CBOR map key type.');
            }
            $map[$key] = $this->item();
        }

        return $map;
    }

    private function halfFloat(): float
    {
        $half = (int) (unpack('n', $this->read(2))[1] ?? 0);
        $exp = ($half >> 10) & 0x1F;
        $mant = $half & 0x3FF;
        $value = match (true) {
            $exp === 0 => $mant * 2 ** -24,
            $exp !== 31 => ($mant + 1024) * 2 ** ($exp - 25),
            $mant === 0 => INF,
            default => NAN,
        };

        return ($half & 0x8000) !== 0 ? -$value : $value;
    }

    private function byte(): int
    {
        return ord($this->read(1));
    }

    private function read(int $length): string
    {
        if ($length < 0 || $this->offset + $length > strlen($this->data)) {
            throw new InvalidArgumentException('Unexpected end of CBOR data.');
        }
        $chunk = substr($this->data, $this->offset, $length);
        $this->offset += $length;

        return $chunk;
    }
}
