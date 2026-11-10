<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\I18n;

/**
 * JSON based translator. Each language is one file lang/<code>.json with
 * flat dotted keys. Adding a language = adding a file; the "_meta.name" key
 * holds the display name. Missing keys fall back to English, then to the key.
 */
final class Translator
{
    /** @var array<string, array<string, string>> */
    private array $catalogues = [];

    private string $locale;

    public function __construct(
        private readonly string $directory,
        string $locale = 'en',
        private readonly string $fallback = 'en',
    ) {
        $this->locale = $this->supports($locale) ? $locale : $fallback;
    }

    public function locale(): string
    {
        return $this->locale;
    }

    public function setLocale(string $locale): void
    {
        if ($this->supports($locale)) {
            $this->locale = $locale;
        }
    }

    public function supports(string $locale): bool
    {
        return preg_match('/^[a-z]{2}(-[A-Z]{2})?$/', $locale) === 1 && is_file($this->file($locale));
    }

    /**
     * @return array<string, string> code => display name
     */
    public function available(): array
    {
        $languages = [];
        foreach (glob($this->directory . '/*.json') ?: [] as $file) {
            $code = basename($file, '.json');
            $languages[$code] = $this->catalogue($code)['_meta.name'] ?? $code;
        }
        ksort($languages);

        return $languages;
    }

    /**
     * @param array<string, string|int|float> $params replacements for {placeholders}
     */
    public function trans(string $key, array $params = [], ?string $locale = null): string
    {
        $locale ??= $this->locale;
        $message = $this->catalogue($locale)[$key]
            ?? $this->catalogue($this->fallback)[$key]
            ?? $key;
        foreach ($params as $name => $value) {
            $message = str_replace('{' . $name . '}', (string) $value, $message);
        }

        return $message;
    }

    /**
     * Pick the best supported locale from an Accept-Language header.
     */
    public function negotiate(string $acceptLanguage, string $default): string
    {
        $candidates = [];
        foreach (explode(',', $acceptLanguage) as $part) {
            $pieces = explode(';', trim($part));
            $tag = trim($pieces[0]);
            $q = 1.0;
            if (isset($pieces[1]) && str_starts_with(trim($pieces[1]), 'q=')) {
                $q = (float) substr(trim($pieces[1]), 2);
            }
            if ($tag !== '') {
                $candidates[$tag] = $q;
            }
        }
        arsort($candidates);
        foreach (array_keys($candidates) as $tag) {
            $short = strtolower(substr((string) $tag, 0, 2));
            if ($this->supports($short)) {
                return $short;
            }
        }

        return $this->supports($default) ? $default : $this->fallback;
    }

    /**
     * @return array<string, string>
     */
    public function catalogue(string $locale): array
    {
        if (!isset($this->catalogues[$locale])) {
            $file = $this->file($locale);
            $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
            $this->catalogues[$locale] = is_array($data) ? self::flatten($data) : [];
        }

        return $this->catalogues[$locale];
    }

    private function file(string $locale): string
    {
        return $this->directory . '/' . $locale . '.json';
    }

    /**
     * @param array<mixed> $data
     *
     * @return array<string, string>
     */
    private static function flatten(array $data, string $prefix = ''): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            $fullKey = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            if (is_array($value)) {
                $result += self::flatten($value, $fullKey);
            } else {
                $result[$fullKey] = is_scalar($value) ? (string) $value : '';
            }
        }

        return $result;
    }
}
