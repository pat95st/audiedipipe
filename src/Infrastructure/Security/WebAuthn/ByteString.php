<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Security\WebAuthn;

final class ByteString
{
    public function __construct(public readonly string $bytes)
    {
    }
}
