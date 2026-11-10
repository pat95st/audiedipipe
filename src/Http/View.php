<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http;

use AudiEdiPipe\Domain\User\Role;
use AudiEdiPipe\Infrastructure\I18n\Translator;
use RuntimeException;

/**
 * Plain PHP template renderer. Templates get $v (this view) and their variables.
 */
final class View
{
    /** @var array<string, mixed> */
    private array $shared = [];

    public function __construct(
        private readonly string $directory,
        private readonly Translator $translator,
        private readonly string $basePath = '',
    ) {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function shared(string $key, mixed $default = null): mixed
    {
        return $this->shared[$key] ?? $default;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function render(string $template, array $data = [], ?string $layout = 'layout'): string
    {
        $content = $this->partial($template, $data);
        if ($layout === null) {
            return $content;
        }

        return $this->partial($layout, $data + ['content' => $content]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function partial(string $template, array $data = []): string
    {
        $file = $this->directory . '/' . $template . '.php';
        if (!is_file($file)) {
            throw new RuntimeException('Template not found: ' . $template);
        }
        $v = $this;
        extract($this->shared + $data, EXTR_SKIP);
        ob_start();

        try {
            include $file;
        } finally {
            $output = (string) ob_get_clean();
        }

        return $output;
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars(is_scalar($value) ? (string) $value : '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param array<string, string|int|float> $params
     */
    public function t(string $key, array $params = []): string
    {
        return $this->translator->trans($key, $params);
    }

    /**
     * Escaped translation.
     *
     * @param array<string, string|int|float> $params
     */
    public function te(string $key, array $params = []): string
    {
        return $this->e($this->t($key, $params));
    }

    public function url(string $path = '/'): string
    {
        return $this->basePath . '/' . ltrim($path, '/');
    }

    public function asset(string $path): string
    {
        $file = APP_ROOT . '/public/assets/' . ltrim($path, '/');
        $version = is_file($file) ? substr((string) filemtime($file), -6) : '0';

        return $this->url('assets/' . ltrim($path, '/')) . '?v=' . $version;
    }

    public function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e($this->shared('csrf', '')) . '">';
    }

    public function can(string $permission): bool
    {
        $user = $this->shared('user');
        if (!is_array($user)) {
            return false;
        }

        return Role::fromString((string) $user['role'])->can($permission);
    }

    public function locale(): string
    {
        return $this->translator->locale();
    }

    public function translator(): Translator
    {
        return $this->translator;
    }

    public function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function date(mixed $value, bool $withTime = true): string
    {
        if (!is_string($value) || $value === '') {
            return '–';
        }
        $ts = strtotime($value . ' UTC');
        if ($ts === false) {
            return $this->e($value);
        }
        $format = $this->locale() === 'de' ? ($withTime ? 'd.m.Y H:i' : 'd.m.Y') : ($withTime ? 'Y-m-d H:i' : 'Y-m-d');

        return '<time datetime="' . gmdate('c', $ts) . '" data-local-time>' . gmdate($format, $ts) . ' UTC</time>';
    }

    public function duration(float $seconds): string
    {
        $seconds = (int) round($seconds);

        return $seconds >= 3600
            ? sprintf('%d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60)
            : sprintf('%d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
