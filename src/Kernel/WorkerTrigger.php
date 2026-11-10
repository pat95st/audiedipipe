<?php

declare(strict_types=1);

namespace AudiEdiPipe\Kernel;

/**
 * Lets a request ask the front controller to run the worker after the
 * response has been sent (fastcgi_finish_request), so freshly uploaded or
 * resumed jobs start immediately even without a real cron.
 */
final class WorkerTrigger
{
    private static bool $requested = false;

    public static function request(): void
    {
        self::$requested = true;
    }

    public static function isRequested(): bool
    {
        return self::$requested;
    }

    public static function reset(): void
    {
        self::$requested = false;
    }
}
