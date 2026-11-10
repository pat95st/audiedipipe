<?php

declare(strict_types=1);

namespace AudiEdiPipe\Http\Controller;

use AudiEdiPipe\Http\Response;
use AudiEdiPipe\Infrastructure\I18n\Translator;
use AudiEdiPipe\Infrastructure\Persistence\SettingsRepository;

/**
 * Dynamic assets that depend on settings: PWA manifest, theme colors, translations.
 */
final class AssetController extends Controller
{
    public function manifest(): Response
    {
        $settings = $this->app->get(SettingsRepository::class);
        $name = $settings->string('app_name') ?: 'audiEdiPipe';
        $manifest = [
            'name' => $name,
            'short_name' => mb_substr($name, 0, 12),
            'description' => 'Pipeline based audio editor',
            'start_url' => $this->view->url('/'),
            'scope' => $this->view->url('/'),
            'display' => 'standalone',
            'background_color' => '#111418',
            'theme_color' => $settings->string('primary_color') ?: '#e8590c',
            'lang' => $this->view->locale(),
            'icons' => [
                ['src' => $this->view->url('assets/img/icon-192.png'), 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $this->view->url('assets/img/icon-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => $this->view->url('assets/img/icon-maskable-512.png'), 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable'],
            ],
            'shortcuts' => [
                ['name' => $this->view->t('nav.upload'), 'url' => $this->view->url('/upload')],
                ['name' => $this->view->t('nav.jobs'), 'url' => $this->view->url('/jobs')],
            ],
        ];

        return Response::json($manifest)->withHeader('Content-Type', 'application/manifest+json');
    }

    public function themeCss(): Response
    {
        $settings = $this->app->get(SettingsRepository::class);
        $primary = self::hex($settings->string('primary_color'), '#e8590c');
        $secondary = self::hex($settings->string('secondary_color'), '#1c7ed6');

        return Response::text(self::css($primary, $secondary), 200, 'text/css; charset=utf-8')
            ->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function translations(): Response
    {
        $locale = basename($this->request->attribute('locale'), '.json');
        $translator = $this->app->get(Translator::class);
        if (!$translator->supports($locale)) {
            $locale = 'en';
        }
        $all = $translator->catalogue('en');
        $all = array_merge($all, $translator->catalogue($locale));
        $js = array_filter($all, static fn (string $key): bool => str_starts_with($key, 'js.') || str_starts_with($key, 'fx.') || str_starts_with($key, 'stage.'), ARRAY_FILTER_USE_KEY);

        return Response::json($js)->withHeader('Cache-Control', 'public, max-age=300');
    }

    public function offline(): Response
    {
        return Response::html($this->view->render('errors/offline', ['title' => 'Offline'], 'layout_guest'));
    }

    public static function css(string $primary, string $secondary): string
    {
        [$pr, $pg, $pb] = self::rgb($primary);
        [$sr, $sg, $sb] = self::rgb($secondary);
        $hover = self::shade($primary, -0.12);
        $contrast = self::luminance($primary) > 0.55 ? '#000' : '#fff';

        return <<<CSS
            :root, [data-bs-theme] {
              --bs-primary: {$primary};
              --bs-primary-rgb: {$pr}, {$pg}, {$pb};
              --bs-link-color: {$primary};
              --bs-link-color-rgb: {$pr}, {$pg}, {$pb};
              --bs-link-hover-color: {$hover};
              --aep-secondary: {$secondary};
              --aep-secondary-rgb: {$sr}, {$sg}, {$sb};
              --aep-accent: {$primary};
            }
            .btn-primary {
              --bs-btn-bg: {$primary}; --bs-btn-border-color: {$primary}; --bs-btn-color: {$contrast};
              --bs-btn-hover-bg: {$hover}; --bs-btn-hover-border-color: {$hover}; --bs-btn-hover-color: {$contrast};
              --bs-btn-active-bg: {$hover}; --bs-btn-active-border-color: {$hover}; --bs-btn-disabled-bg: {$primary}; --bs-btn-disabled-border-color: {$primary};
            }
            .btn-outline-primary {
              --bs-btn-color: {$primary}; --bs-btn-border-color: {$primary};
              --bs-btn-hover-bg: {$primary}; --bs-btn-hover-border-color: {$primary}; --bs-btn-hover-color: {$contrast};
              --bs-btn-active-bg: {$primary}; --bs-btn-active-border-color: {$primary};
            }
            .form-control:focus, .form-select:focus { border-color: {$primary}; box-shadow: 0 0 0 .25rem rgba({$pr}, {$pg}, {$pb}, .25); }
            .form-check-input:checked { background-color: {$primary}; border-color: {$primary}; }
            .nav-pills .nav-link.active { background-color: {$primary}; color: {$contrast}; }
            .text-bg-primary { background-color: {$primary} !important; color: {$contrast} !important; }

            CSS;
    }

    private static function hex(string $value, string $default): string
    {
        return preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? strtolower($value) : $default;
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private static function rgb(string $hex): array
    {
        return [(int) hexdec(substr($hex, 1, 2)), (int) hexdec(substr($hex, 3, 2)), (int) hexdec(substr($hex, 5, 2))];
    }

    private static function shade(string $hex, float $factor): string
    {
        $rgb = array_map(static fn (int $c): int => (int) max(0, min(255, round($c * (1 + $factor)))), self::rgb($hex));

        return sprintf('#%02x%02x%02x', ...$rgb);
    }

    private static function luminance(string $hex): float
    {
        [$r, $g, $b] = self::rgb($hex);

        return (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    }
}
