<?php

declare(strict_types=1);

namespace AudiEdiPipe\Kernel;

use AudiEdiPipe\Application\User\Command\CreateUser;
use AudiEdiPipe\Application\User\UserCommandHandler;
use AudiEdiPipe\Application\ValidationException;
use AudiEdiPipe\Cqrs\CommandBus;
use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Persistence\Migrator;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;
use AudiEdiPipe\Infrastructure\Process\ProcessRunner;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Infrastructure\Support\Json;
use RuntimeException;

/**
 * Shared installer logic for the web wizard and "bin/console install".
 */
final class Installer
{
    public function __construct(private readonly string $root)
    {
    }

    /**
     * @return list<array{key: string, ok: bool, required: bool, detail: string}>
     */
    public function requirements(string $ffmpeg = 'ffmpeg'): array
    {
        $checks = [];
        $add = static function (string $key, bool $ok, bool $required, string $detail = '') use (&$checks): void {
            $checks[] = ['key' => $key, 'ok' => $ok, 'required' => $required, 'detail' => $detail];
        };
        $add('php', version_compare(PHP_VERSION, '8.2.0', '>='), true, PHP_VERSION);
        foreach (['pdo', 'mbstring', 'json', 'openssl', 'sodium', 'curl', 'dom'] as $ext) {
            $add('ext_' . $ext, extension_loaded($ext), true);
        }
        $drivers = array_keys(array_filter(['pdo_mysql' => extension_loaded('pdo_mysql'), 'pdo_pgsql' => extension_loaded('pdo_pgsql')]));
        $add('ext_pdo_mysql_or_pgsql', $drivers !== [], true, implode(', ', $drivers));
        foreach (['ftp', 'ssh2', 'gd', 'intl'] as $ext) {
            $add('ext_' . $ext, extension_loaded($ext), false);
        }
        $add('proc_open', function_exists('proc_open') || function_exists('exec'), true);
        foreach (['config', 'storage', 'public/uploads'] as $dir) {
            $path = $this->root . '/' . $dir;
            if (!is_dir($path)) {
                @mkdir($path, 0775, true);
            }
            $add('writable_' . str_replace('/', '_', $dir), is_dir($path) && is_writable($path), true, $dir);
        }
        $version = null;
        if (function_exists('proc_open')) {
            $result = (new ProcessRunner())->run([$ffmpeg, '-hide_banner', '-version'], 15);
            if ($result->successful()) {
                $version = trim((string) strtok($result->stdout, "\n"));
            }
        }
        $add('ffmpeg', $version !== null, true, $version ?? $ffmpeg);

        return $checks;
    }

    /**
     * @param list<array{key: string, ok: bool, required: bool, detail: string}> $checks
     */
    public static function requirementsMet(array $checks): bool
    {
        foreach ($checks as $check) {
            if ($check['required'] && !$check['ok']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed> $input keys: db_driver, db_host, db_port, db_name, db_user, db_password, db_path,
     *                                    app_url, app_name, locale, admin_username, admin_email, admin_password,
     *                                    ffmpeg, ffprobe
     *
     * @return array<string, mixed> the written configuration
     */
    public function install(array $input): array
    {
        if (is_file($this->root . '/config/installed.lock')) {
            throw new RuntimeException('audiEdiPipe is already installed.');
        }
        if (preg_match('/^[a-zA-Z0-9._-]{3,100}$/', (string) ($input['admin_username'] ?? '')) !== 1) {
            throw new ValidationException('users.error.invalid_username');
        }
        if (filter_var((string) ($input['admin_email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException('users.error.invalid_email');
        }
        if (mb_strlen((string) ($input['admin_password'] ?? '')) < UserCommandHandler::MIN_PASSWORD_LENGTH) {
            throw new ValidationException('users.error.password_too_short', ['min' => UserCommandHandler::MIN_PASSWORD_LENGTH]);
        }
        $driver = in_array($input['db_driver'] ?? '', ['mysql', 'pgsql', 'sqlite'], true) ? (string) $input['db_driver'] : 'mysql';
        $database = [
            'driver' => $driver,
            'host' => (string) ($input['db_host'] ?? '127.0.0.1'),
            'port' => (int) ($input['db_port'] ?? ($driver === 'pgsql' ? 5432 : 3306)),
            'name' => (string) ($input['db_name'] ?? 'audiedipipe'),
            'user' => (string) ($input['db_user'] ?? ''),
            'password' => (string) ($input['db_password'] ?? ''),
            'path' => (string) ($input['db_path'] ?? $this->root . '/storage/database.sqlite'),
        ];
        // Fails early with a readable PDO error if the connection is wrong.
        $db = Database::fromConfig($database);

        $config = [
            'app' => [
                'key' => Crypto::generateKey(),
                'url' => rtrim((string) ($input['app_url'] ?? 'http://localhost'), '/'),
                'debug' => false,
                'default_locale' => (string) ($input['locale'] ?? 'en'),
            ],
            'database' => $database,
            'paths' => [
                'storage' => $this->root . '/storage',
                'ffmpeg' => (string) ($input['ffmpeg'] ?? 'ffmpeg') ?: 'ffmpeg',
                'ffprobe' => (string) ($input['ffprobe'] ?? 'ffprobe') ?: 'ffprobe',
                'smbclient' => 'smbclient',
            ],
            'worker' => [
                'time_budget' => 50,
                'cron_token' => bin2hex(random_bytes(16)),
                'pseudo_cron' => (bool) ($input['pseudo_cron'] ?? true),
            ],
        ];

        (new Migrator($db, $this->root . '/migrations'))->migrate();
        foreach (['uploads', 'work', 'output', 'logs', 'cache', 'media', 'preview'] as $dir) {
            @mkdir($this->root . '/storage/' . $dir, 0775, true);
        }

        $configFile = $this->root . '/config/config.php';
        if (@file_put_contents($configFile, Config::export($config)) === false) {
            throw new RuntimeException('Cannot write config/config.php');
        }
        @chmod($configFile, 0640);

        $app = new App(new Config($config), $this->root);
        $app->container->instance(Database::class, $db);
        $settings = $app->get(SettingsRepository::class);
        $settings->set('app_name', (string) ($input['app_name'] ?? 'audiEdiPipe') ?: 'audiEdiPipe');
        $settings->set('default_locale', $config['app']['default_locale']);
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM users') === 0) {
            $app->get(CommandBus::class)->dispatch(new CreateUser(
                (string) ($input['admin_username'] ?? 'admin'),
                (string) ($input['admin_email'] ?? ''),
                (string) ($input['admin_password'] ?? ''),
                'admin',
                null,
                $config['app']['default_locale'],
            ));
        }
        $this->seed($db);
        file_put_contents($this->root . '/config/installed.lock', gmdate('c') . "\n");

        return $config;
    }

    /**
     * Built-in effect presets and an example metadata template.
     */
    public function seed(Database $db): void
    {
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM effect_presets WHERE builtin = 1') > 0) {
            return;
        }
        $now = Database::now();
        $presets = [
            ['compressor', 'Voice - gentle', ['threshold' => -20, 'ratio' => 2.5, 'attack' => 15, 'release' => 200, 'makeup' => 3, 'knee' => 4]],
            ['compressor', 'Voice - broadcast', ['threshold' => -24, 'ratio' => 4, 'attack' => 5, 'release' => 120, 'makeup' => 6, 'knee' => 2.8]],
            ['parametric_eq', 'Voice - clarity', ['bands' => [
                ['type' => 'high_pass', 'frequency' => 70, 'gain' => 0, 'q' => 0.7],
                ['type' => 'bell', 'frequency' => 200, 'gain' => -2, 'q' => 1.2],
                ['type' => 'bell', 'frequency' => 450, 'gain' => -1.5, 'q' => 1.4],
                ['type' => 'bell', 'frequency' => 3200, 'gain' => 2, 'q' => 1],
                ['type' => 'bell', 'frequency' => 6500, 'gain' => 0, 'q' => 1],
                ['type' => 'high_shelf', 'frequency' => 10000, 'gain' => 2, 'q' => 0.7],
            ]]],
            ['dynamic_eq', 'De-esser', ['bands' => [['enabled' => true, 'type' => 'bell', 'frequency' => 6500, 'q' => 3, 'threshold' => -30, 'ratio' => 5, 'attack' => 1, 'release' => 60]]]],
            ['master_leveler', 'Podcast (-16 LUFS)', ['target' => -16, 'true_peak' => -1.5, 'lra' => 11]],
            ['master_leveler', 'Spotify (-14 LUFS)', ['target' => -14, 'true_peak' => -1, 'lra' => 11]],
            ['master_leveler', 'EBU R128 (-23 LUFS)', ['target' => -23, 'true_peak' => -1, 'lra' => 15]],
            ['gate', 'Voice gate', ['threshold' => -50, 'range' => -30, 'ratio' => 10, 'attack' => 3, 'release' => 200]],
            ['noise_reduction', 'Light', ['reduction' => 8, 'noise_floor' => -55]],
            ['noise_reduction', 'Strong', ['reduction' => 20, 'noise_floor' => -45]],
        ];
        foreach ($presets as [$type, $name, $params]) {
            $db->insert('effect_presets', [
                'effect_type' => $type, 'name' => $name, 'params' => Json::encode($params), 'builtin' => true,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        if ((int) $db->fetchValue('SELECT COUNT(*) FROM metadata_templates') === 0) {
            $db->insert('metadata_templates', [
                'name' => 'Podcast episode',
                'fields' => Json::encode([
                    'title' => 'Episode {counter:3} - {basename}', 'artist' => '', 'album' => '{pipeline}', 'album_artist' => '',
                    'genre' => 'Podcast', 'date' => '{year}', 'track' => '{counter}', 'comment' => '', 'description' => '',
                    'copyright' => '© {year}', 'language' => 'de', 'episode' => '{counter}', 'season' => '', 'cover' => '',
                ]),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}
