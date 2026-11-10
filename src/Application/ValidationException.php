<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application;

use RuntimeException;

/**
 * Thrown by command handlers when input is invalid. Messages are translation keys.
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, string|int|float> $params
     */
    public function __construct(string $translationKey, public readonly array $params = [])
    {
        parent::__construct($translationKey);
    }
}
