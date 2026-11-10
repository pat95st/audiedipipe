<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Storage;

/**
 * Decides which remote files are picked up by a watched source.
 */
final class FileFilter
{
    /**
     * @param array<mixed> $filter include/exclude glob lists, regex, extensions, min_size_kb, min_age_seconds
     */
    public function __construct(private readonly array $filter)
    {
    }

    public function accepts(RemoteFile $file, ?int $now = null): bool
    {
        $now ??= time();
        $name = $file->name();
        if (str_starts_with($name, '.') || str_ends_with($name, '.part') || str_ends_with($name, '.tmp')) {
            return false;
        }

        $extensions = self::list((string) ($this->filter['extensions'] ?? ''));
        if ($extensions !== []) {
            $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
            if (!in_array($ext, array_map(static fn (string $e): string => ltrim(strtolower($e), '.'), $extensions), true)) {
                return false;
            }
        }

        $include = self::list((string) ($this->filter['include'] ?? ''));
        if ($include !== [] && !self::matchesAny($include, $name, $file->path)) {
            return false;
        }
        $exclude = self::list((string) ($this->filter['exclude'] ?? ''));
        if ($exclude !== [] && self::matchesAny($exclude, $name, $file->path)) {
            return false;
        }

        $regex = trim((string) ($this->filter['regex'] ?? ''));
        if ($regex !== '' && @preg_match('#' . str_replace('#', '\#', $regex) . '#i', $name) !== 1) {
            return false;
        }

        $minSize = (int) ($this->filter['min_size_kb'] ?? 0) * 1024;
        if ($file->size < $minSize) {
            return false;
        }
        $maxSize = (int) ($this->filter['max_size_mb'] ?? 0) * 1048576;
        if ($maxSize > 0 && $file->size > $maxSize) {
            return false;
        }

        // Files that are still being uploaded change their mtime - wait until they are old enough.
        $minAge = (int) ($this->filter['min_age_seconds'] ?? 0);
        if ($minAge > 0 && $file->modified > 0 && ($now - $file->modified) < $minAge) {
            return false;
        }

        return true;
    }

    /**
     * @return list<string>
     */
    private static function list(string $value): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $value) ?: []), static fn (string $v): bool => $v !== ''));
    }

    /**
     * @param list<string> $patterns
     */
    private static function matchesAny(array $patterns, string $name, string $path): bool
    {
        foreach ($patterns as $pattern) {
            $subject = str_contains($pattern, '/') ? ltrim($path, '/') : $name;
            if (fnmatch(ltrim($pattern, '/'), $subject, FNM_CASEFOLD)) {
                return true;
            }
        }

        return false;
    }
}
