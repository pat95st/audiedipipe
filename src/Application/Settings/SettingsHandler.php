<?php

declare(strict_types=1);

namespace AudiEdiPipe\Application\Settings;

use AudiEdiPipe\Application\Settings\Command\SaveSettings;
use AudiEdiPipe\Application\Settings\Query\GetSettings;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\HandlerProvider;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;
use AudiEdiPipe\Infrastructure\Security\Crypto;

final class SettingsHandler implements HandlerProvider
{
    public function __construct(private readonly SettingsRepository $settings, private readonly Crypto $crypto)
    {
    }

    public static function handledMessages(): array
    {
        return [
            SaveSettings::class => 'save',
            GetSettings::class => 'get',
        ];
    }

    public function save(SaveSettings $command): void
    {
        foreach ($command->values as $name => $value) {
            if (!is_string($name) || !array_key_exists($name, SettingsRepository::DEFAULTS)) {
                continue;
            }
            $value = match ($name) {
                'primary_color', 'secondary_color' => self::color($value),
                'smtp' => $this->smtp(is_array($value) ? $value : []),
                'upload_max_mb', 'keep_work_files_days' => max(1, (int) (is_numeric($value) ? $value : 1)),
                'default_theme' => in_array($value, ['auto', 'light', 'dark'], true) ? $value : 'auto',
                default => is_scalar($value) ? mb_substr(trim((string) $value), 0, 500) : '',
            };
            $this->settings->set($name, $value);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function get(GetSettings $query): array
    {
        $all = $this->settings->all();
        $smtp = is_array($all['smtp'] ?? null) ? $all['smtp'] : [];
        $smtp['password_set'] = ($smtp['password'] ?? '') !== '';
        $smtp['password'] = '';
        $all['smtp'] = $smtp;

        return $all;
    }

    private static function color(mixed $value): string
    {
        $value = is_string($value) ? trim($value) : '';
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $value) !== 1) {
            throw new ValidationException('settings.error.invalid_color');
        }

        return strtolower($value);
    }

    /**
     * @param array<mixed> $input
     *
     * @return array<string, mixed>
     */
    private function smtp(array $input): array
    {
        $current = $this->settings->get('smtp');
        $current = is_array($current) ? $current : [];
        $password = (string) ($input['password'] ?? '');
        if ($password === '') {
            $password = (string) ($current['password'] ?? '');
        } else {
            $password = $this->crypto->encrypt($password);
        }
        $from = trim((string) ($input['from_email'] ?? ''));
        if ($from !== '' && filter_var($from, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('settings.error.invalid_email');
        }

        return [
            'host' => trim((string) ($input['host'] ?? '')),
            'port' => max(1, min(65535, (int) ($input['port'] ?? 587))),
            'encryption' => in_array($input['encryption'] ?? '', ['none', 'tls', 'ssl'], true) ? (string) $input['encryption'] : 'tls',
            'username' => trim((string) ($input['username'] ?? '')),
            'password' => $password,
            'from_email' => $from,
            'from_name' => trim((string) ($input['from_name'] ?? 'audiEdiPipe')),
        ];
    }
}
