<?php

declare(strict_types=1);

namespace AudiEdiPipe\Tests\Unit\Http;

use AudiEdiPipe\Infrastructure\Branding\AppIconRenderer;
use AudiEdiPipe\Infrastructure\Branding\AppIcons;
use AudiEdiPipe\Tests\Support\TestCase;

final class AppIconsTest extends TestCase
{
    public function test_without_logo_the_bundled_icons_are_used(): void
    {
        $icons = new AppIcons($this->tempDir(), '');

        self::assertFalse($icons->hasRasterLogo());
        self::assertFalse($icons->hasSvgLogo());
        self::assertNull($icons->render('512'));
        self::assertSame('assets/img/icon-maskable-512.png', AppIcons::fallback('maskable'));
        self::assertSame('assets/img/apple-touch-icon.png', AppIcons::fallback('apple'));
    }

    public function test_svg_logo_is_detected(): void
    {
        $public = $this->tempDir();
        mkdir($public . '/uploads/branding', 0775, true);
        file_put_contents($public . '/uploads/branding/logo-1.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>');
        $icons = new AppIcons($public, 'uploads/branding/logo-1.svg');

        self::assertTrue($icons->hasSvgLogo());
        self::assertFalse($icons->hasRasterLogo());
        self::assertNull($icons->render('192'));
    }

    public function test_path_traversal_is_rejected(): void
    {
        $icons = new AppIcons($this->tempDir(), '../../etc/passwd.png');

        self::assertFalse($icons->hasRasterLogo());
    }

    public function test_icons_are_rendered_from_a_raster_logo(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd not available');
        }
        $public = $this->tempDir();
        mkdir($public . '/uploads/branding', 0775, true);
        $image = imagecreatetruecolor(300, 200);
        imagefill($image, 0, 0, (int) imagecolorallocate($image, 200, 30, 30));
        imagepng($image, $public . '/uploads/branding/logo-2.png');

        $icons = new AppIcons($public, 'uploads/branding/logo-2.png');
        self::assertTrue($icons->hasUsableRasterLogo());
        self::assertSame(10, strlen($icons->version()));
        self::assertNotSame((new AppIcons($public, 'uploads/branding/logo-3.png'))->version(), $icons->version());

        foreach (['192' => 192, '512' => 512, 'maskable' => 512, 'apple' => 180] as $variant => $size) {
            $png = $icons->render($variant);
            self::assertNotNull($png, $variant);
            $info = getimagesizefromstring($png);
            self::assertNotFalse($info);
            self::assertSame([$size, $size, IMAGETYPE_PNG], [$info[0], $info[1], $info[2]], $variant);
        }

        // padded variants have an opaque background in the corner, the plain ones are transparent
        $maskable = imagecreatefromstring((string) $icons->render('maskable'));
        $plain = imagecreatefromstring((string) $icons->render('512'));
        self::assertNotFalse($maskable);
        self::assertNotFalse($plain);
        self::assertSame(0, (imagecolorat($maskable, 1, 1) >> 24) & 0x7F);
        self::assertSame(127, (imagecolorat($plain, 1, 1) >> 24) & 0x7F);
    }

    public function test_too_small_logo_is_not_used(): void
    {
        if (!extension_loaded('gd')) {
            self::markTestSkipped('ext-gd not available');
        }
        $public = $this->tempDir();
        $image = imagecreatetruecolor(64, 64);
        imagepng($image, $public . '/small.png');

        self::assertNull((new AppIconRenderer())->rasterSource($public . '/small.png'));
        self::assertFalse((new AppIcons($public, 'small.png'))->hasUsableRasterLogo());
    }
}
