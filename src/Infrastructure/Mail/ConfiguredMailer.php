<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Mail;

use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;
use AudiEdiPipe\Infrastructure\Security\Crypto;

/**
 * Resolves the SMTP configuration from the settings at send time.
 * Without SMTP configuration mails are written to the mail log.
 */
final class ConfiguredMailer implements Mailer
{
    public function __construct(
        private readonly SettingsRepository $settings,
        private readonly Crypto $crypto,
        private readonly string $logFile,
    ) {
    }

    public function send(Message $message): void
    {
        $this->resolve()->send($message);
    }

    public function resolve(): Mailer
    {
        $smtp = $this->settings->get('smtp');
        $smtp = is_array($smtp) ? $smtp : [];
        if (($smtp['host'] ?? '') === '') {
            return new ArrayMailer($this->logFile);
        }

        return new SmtpMailer(
            (string) $smtp['host'],
            (int) ($smtp['port'] ?? 587),
            (string) ($smtp['encryption'] ?? 'tls'),
            (string) ($smtp['username'] ?? ''),
            $this->crypto->decrypt((string) ($smtp['password'] ?? '')),
            (string) ($smtp['from_email'] ?? ''),
            (string) ($smtp['from_name'] ?? 'audiEdiPipe'),
        );
    }
}
