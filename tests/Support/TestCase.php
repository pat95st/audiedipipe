<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Support;

use AudiEdiPipe\Infrastructure\Persistence\Database;
use AudiEdiPipe\Infrastructure\Persistence\Migrator;
use AudiEdiPipe\Infrastructure\Security\Crypto;
use AudiEdiPipe\Kernel\App;
use AudiEdiPipe\Kernel\Config;
use PHPUnit\Framework\TestCase as BaseTestCase;

/**
 * Base test case with helpers for temporary directories, in-memory/file
 * SQLite databases and a fully wired application instance.
 */
abstract class TestCase extends BaseTestCase
{
    /** @var list<string> */
    private array $tempPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->tempPaths) as $path) {
            $this->removeRecursive($path);
        }
        $this->tempPaths = [];
        parent::tearDown();
    }

    protected function tempDir(string $prefix = 'aep'): string
    {
        $dir = sys_get_temp_dir() . '/' . $prefix . '_' . bin2hex(random_bytes(6));
        mkdir($dir, 0775, true);
        $this->tempPaths[] = $dir;

        return $dir;
    }

    /**
     * A SQLite database backed by a temporary file with the schema migrated.
     */
    protected function migratedDatabase(): Database
    {
        $dir = $this->tempDir('aepdb');
        $db = Database::fromConfig(['driver' => 'sqlite', 'path' => $dir . '/test.sqlite']);
        $migrator = new Migrator($db, APP_ROOT . '/migrations');
        $migrator->migrate();

        return $db;
    }

    /**
     * A fully wired, "installed" application on an isolated temporary root.
     * Templates, language files and migrations are symlinked from the project
     * so the HTTP/view layer works without touching the real storage/config.
     *
     * @param array<string, mixed> $overrides merged into the config array
     */
    protected function installedApp(array $overrides = []): App
    {
        $root = $this->tempDir('aeproot');
        foreach (['templates', 'lang', 'migrations'] as $shared) {
            @symlink(APP_ROOT . '/' . $shared, $root . '/' . $shared);
        }
        foreach (['config', 'storage', 'public/uploads'] as $writable) {
            mkdir($root . '/' . $writable, 0775, true);
        }
        file_put_contents($root . '/config/installed.lock', date('c'));

        $config = new Config(array_replace_recursive([
            'app' => [
                'key' => Crypto::generateKey(),
                'url' => 'http://localhost',
                'debug' => true,
                'default_locale' => 'en',
            ],
            'database' => ['driver' => 'sqlite', 'path' => $root . '/storage/test.sqlite'],
            'paths' => ['storage' => $root . '/storage'],
        ], $overrides));

        $app = new App($config, $root);
        $app->get(Migrator::class)->migrate();

        return $app;
    }

    private function removeRecursive(string $path): void
    {
        if (is_link($path)) {
            @unlink($path);

            return;
        }
        if (is_dir($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeRecursive($path . '/' . $entry);
                }
            }
            @rmdir($path);

            return;
        }
        @unlink($path);
    }
}
