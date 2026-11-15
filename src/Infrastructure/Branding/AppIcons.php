<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Branding;

/**
 * Resolves the app icons (PWA manifest, Apple touch icon) from the logo
 * uploaded under Settings → Branding. Without a usable logo the bundled
 * default icons in public/assets/img/ are used.
 *
 * Icon URLs carry a version parameter derived from the logo file name, which
 * changes with every upload, so browsers and the service worker can cache
 * them forever.
 */
final class AppIcons
{
    /** variant => [size, padded, bundled fallback] */
    public const VARIANTS = [
        '192' => [192, false, 'icon-192.png'],
        '512' => [512, false, 'icon-512.png'],
        'maskable' => [512, true, 'icon-maskable-512.png'],
        'apple' => [180, true, 'apple-touch-icon.png'],
    ];

    public function __construct(
        private readonly string $publicDir,
        private readonly string $logo,
        private readonly AppIconRenderer $renderer = new AppIconRenderer(),
    ) {
    }

    /** Logo is a raster image (cheap check, without reading the file). */
    public function hasRasterLogo(): bool
    {
        return $this->logo !== ''
            && in_array($this->extension(), ['png', 'jpg', 'jpeg', 'webp'], true)
            && is_file($this->path());
    }

    /** Logo is a raster image that is large enough to be used as app icon. */
    public function hasUsableRasterLogo(): bool
    {
        return $this->hasRasterLogo() && $this->renderer->rasterSource($this->path()) !== null;
    }

    public function hasSvgLogo(): bool
    {
        return $this->logo !== '' && $this->extension() === 'svg' && is_file($this->path());
    }

    public function version(): string
    {
        return substr(md5($this->logo), 0, 10);
    }

    /** Relative path of the logo (e.g. "uploads/branding/logo-ab12.svg"). */
    public function logo(): string
    {
        return $this->logo;
    }

    /** Rendered PNG for a variant, null when the default icon should be used. */
    public function render(string $variant): ?string
    {
        if (!isset(self::VARIANTS[$variant]) || !$this->hasRasterLogo()) {
            return null;
        }
        $source = $this->renderer->rasterSource($this->path());
        if ($source === null) {
            return null;
        }
        [$size, $padded] = self::VARIANTS[$variant];

        return $this->renderer->render($source, $size, $padded);
    }

    public static function fallback(string $variant): string
    {
        return 'assets/img/' . (self::VARIANTS[$variant][2] ?? self::VARIANTS['512'][2]);
    }

    private function path(): string
    {
        if (str_contains($this->logo, '..')) {
            return '';
        }

        return rtrim($this->publicDir, '/') . '/' . ltrim($this->logo, '/');
    }

    private function extension(): string
    {
        return strtolower(pathinfo($this->logo, PATHINFO_EXTENSION));
    }
}
