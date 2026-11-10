<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Mail;

interface Mailer
{
    /**
     * @throws MailException
     */
    public function send(Message $message): void;
}
