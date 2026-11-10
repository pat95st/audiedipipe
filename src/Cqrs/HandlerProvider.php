<?php

declare(strict_types=1);

namespace AudiEdiPipe\Cqrs;

/**
 * A handler class declares which messages it handles and with which method.
 */
interface HandlerProvider
{
    /**
     * @return array<class-string<Command|Query>, string> message class => method name
     */
    public static function handledMessages(): array;
}
