<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Notification;

use AudiEdiPipe\Infrastructure\I18n\Translator;
use AudiEdiPipe\Infrastructure\Mail\Mailer;
use AudiEdiPipe\Infrastructure\Mail\Message;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;
use AudiEdiPipe\Infrastructure\Support\Json;
use Throwable;

/**
 * Sends e-mail notifications for pipeline events, localized per recipient.
 */
final class Notifier
{
    /** @var list<string> */
    private array $errors = [];

    public function __construct(
        private readonly Database $db,
        private readonly Mailer $mailer,
        private readonly Translator $translator,
        private readonly SettingsRepository $settings,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Notify about a job event (waiting_manual, waiting_review, success, failed, rejected).
     */
    public function jobEvent(int $jobId, string $event): int
    {
        $job = $this->db->fetchOne(
            'SELECT j.*, p.name AS pipeline_name, p.notifications FROM jobs j JOIN pipelines p ON p.id = j.pipeline_id WHERE j.id = ?',
            [$jobId],
        );
        if ($job === null) {
            return 0;
        }
        $config = Json::decode($job['notifications']);
        $events = array_map('strval', (array) ($config['events'] ?? []));
        $automaticRole = match ($event) {
            'waiting_manual' => 'cutter',
            'waiting_review' => 'reviewer',
            default => null,
        };
        if (!in_array($event, $events, true)) {
            return 0;
        }
        $roles = array_map('strval', (array) ($config['roles'] ?? []));
        if ($automaticRole !== null) {
            $roles[] = $automaticRole;
        }
        $recipients = $this->recipients(Json::str($config, 'recipients'), $roles);

        $params = [
            'app' => $this->settings->string('app_name'),
            'pipeline' => (string) $job['pipeline_name'],
            'file' => (string) $job['original_name'],
            'job' => (string) $jobId,
            'error' => (string) ($job['error'] ?? ''),
            'link' => rtrim($this->baseUrl, '/') . '/jobs/' . $jobId,
        ];

        return $this->deliver($recipients, 'mail.job.' . $event . '.subject', 'mail.job.' . $event . '.body', $params);
    }

    /**
     * Notify about a newly detected file on a watched storage location.
     *
     * @param list<int> $jobIds
     */
    public function newFile(int $pipelineId, string $sourceName, string $fileName, array $jobIds): int
    {
        $pipeline = $this->db->fetchOne('SELECT name, notifications FROM pipelines WHERE id = ?', [$pipelineId]);
        if ($pipeline === null) {
            return 0;
        }
        $config = Json::decode($pipeline['notifications']);
        if (!in_array('new_file', array_map('strval', (array) ($config['events'] ?? [])), true)) {
            return 0;
        }
        $recipients = $this->recipients(Json::str($config, 'recipients'), array_map('strval', (array) ($config['roles'] ?? [])));
        $params = [
            'app' => $this->settings->string('app_name'),
            'pipeline' => (string) $pipeline['name'],
            'source' => $sourceName,
            'file' => $fileName,
            'link' => rtrim($this->baseUrl, '/') . ($jobIds !== [] ? '/jobs/' . $jobIds[0] : '/jobs'),
        ];

        return $this->deliver($recipients, 'mail.new_file.subject', 'mail.new_file.body', $params);
    }

    /**
     * Custom notification from a "notify" pipeline stage.
     *
     * @param array<string, mixed>  $stageParams
     * @param array<string, string> $placeholders
     */
    public function custom(array $stageParams, array $placeholders): int
    {
        $roles = array_map('strval', (array) ($stageParams['roles'] ?? []));
        $recipients = $this->recipients((string) ($stageParams['recipients'] ?? ''), $roles);
        $subject = (string) ($stageParams['subject'] ?? '');
        $body = (string) ($stageParams['message'] ?? '');
        $sent = 0;
        foreach ($recipients as $email => $locale) {
            $vars = $placeholders + ['app' => $this->settings->string('app_name')];
            $s = $subject !== '' ? self::fill($subject, $vars) : $this->translator->trans('mail.custom.subject', $vars, $locale);
            $b = $body !== '' ? self::fill($body, $vars) : $this->translator->trans('mail.custom.body', $vars, $locale);
            if (($stageParams['attach_link'] ?? true) === true && isset($vars['link'])) {
                $b .= "\n\n" . $vars['link'];
            }
            $sent += $this->sendOne($email, $s, $b);
        }

        return $sent;
    }

    /**
     * Send a plain message to one address (2FA codes, test mails).
     */
    public function sendRaw(string $email, string $subject, string $body): bool
    {
        return $this->sendOne($email, $subject, $body) === 1;
    }

    /**
     * @param array<string, string> $vars
     */
    public static function fill(string $template, array $vars): string
    {
        foreach ($vars as $key => $value) {
            $template = str_replace('{' . $key . '}', $value, $template);
        }

        return $template;
    }

    /**
     * @param array<string> $roles
     *
     * @return array<string, string> email => locale
     */
    private function recipients(string $explicit, array $roles): array
    {
        $default = $this->settings->string('default_locale') ?: 'en';
        $result = [];
        foreach (preg_split('/[\s,;]+/', $explicit) ?: [] as $email) {
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                $result[strtolower($email)] = $default;
            }
        }
        $roles = array_values(array_unique(array_intersect(['admin', 'cutter', 'reviewer'], $roles)));
        if ($roles !== []) {
            $rows = $this->db->fetchAll(
                'SELECT email, locale FROM users WHERE active = 1 AND role IN (' . implode(', ', array_fill(0, count($roles), '?')) . ')',
                $roles,
            );
            foreach ($rows as $row) {
                $result[strtolower((string) $row['email'])] = (string) ($row['locale'] ?: $default);
            }
        }

        return $result;
    }

    /**
     * @param array<string, string> $recipients
     * @param array<string, string> $params
     */
    private function deliver(array $recipients, string $subjectKey, string $bodyKey, array $params): int
    {
        $sent = 0;
        foreach ($recipients as $email => $locale) {
            $sent += $this->sendOne(
                $email,
                $this->translator->trans($subjectKey, $params, $locale),
                $this->translator->trans($bodyKey, $params, $locale),
            );
        }

        return $sent;
    }

    private function sendOne(string $email, string $subject, string $body): int
    {
        try {
            $this->mailer->send(new Message([$email], $subject, $body));

            return 1;
        } catch (Throwable $e) {
            $this->errors[] = $email . ': ' . $e->getMessage();

            return 0;
        }
    }
}
