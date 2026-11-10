<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Persistence;

use PDOException;

/**
 * Key/value application settings stored as JSON.
 */
final class SettingsRepository
{
    public const DEFAULTS = [
        'app_name' => 'audiEdiPipe',
        'logo' => '',
        'primary_color' => '#e8590c',
        'secondary_color' => '#1c7ed6',
        'default_locale' => 'en',
        'default_theme' => 'auto',
        'smtp' => [
            'host' => '',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => 'audiEdiPipe',
        ],
        'upload_max_mb' => 1024,
        'keep_work_files_days' => 14,
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Database $db)
    {
    }

    public function get(string $name, mixed $default = null): mixed
    {
        $all = $this->all();

        return $all[$name] ?? $default ?? (self::DEFAULTS[$name] ?? null);
    }

    public function string(string $name): string
    {
        $value = $this->get($name);

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if ($this->cache === null) {
            $values = self::DEFAULTS;

            try {
                foreach ($this->db->fetchAll('SELECT name, value FROM settings') as $row) {
                    $decoded = json_decode((string) $row['value'], true);
                    $values[(string) $row['name']] = $decoded;
                }
            } catch (PDOException) {
                // not installed yet
            }
            $this->cache = $values;
        }

        return $this->cache;
    }

    public function set(string $name, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $updated = $this->db->update('settings', ['value' => $json], ['name' => $name]);
        if ($updated === 0 && $this->db->fetchValue('SELECT id FROM settings WHERE name = ?', [$name]) === null) {
            $this->db->insert('settings', ['name' => $name, 'value' => $json]);
        }
        $this->cache = null;
    }
}
