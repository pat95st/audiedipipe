<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Mail;

/**
 * Collects messages in memory and optionally appends them to a log file.
 * Used in tests and when SMTP is not configured.
 */
final class ArrayMailer implements Mailer
{
    /** @var list<Message> */
    public array $sent = [];

    public function __construct(private readonly ?string $logFile = null)
    {
    }

    public function send(Message $message): void
    {
        $this->sent[] = $message;
        if ($this->logFile !== null) {
            if (!is_dir(dirname($this->logFile))) {
                @mkdir(dirname($this->logFile), 0775, true);
            }
            @file_put_contents(
                $this->logFile,
                sprintf("[%s] To: %s | %s\n%s\n\n", gmdate('c'), implode(', ', $message->to), $message->subject, $message->text),
                FILE_APPEND,
            );
        }
    }
}
