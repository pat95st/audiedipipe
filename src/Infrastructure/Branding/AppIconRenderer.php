<?php

declare(strict_types=1);

namespace AudiEdiPipe\Infrastructure\Branding;

/**
 * Renders the icons of the installed app (PWA) from a raster image: 192/512 px
 * for the manifest, a "maskable" variant with a safe zone for Android and
 * 180 px as Apple touch icon. Needs ext-gd; without it null is returned and
 * the caller falls back to the bundled default icons.
 */
final class AppIconRenderer
{
    /** Smaller sources would look blurry on the home screen. */
    public const MIN_SOURCE_SIZE = 144;

    /**
     * Raster source bytes (PNG, JPEG, WebP) or null when the file is missing,
     * not a supported raster image or too small.
     */
    public function rasterSource(string $path): ?string
    {
        if (!is_file($path) || !in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg', 'webp'], true)) {
            return null;
        }
        $data = (string) file_get_contents($path);
        $size = @getimagesizefromstring($data);
        if ($size === false || !in_array($size[2], [IMAGETYPE_PNG, IMAGETYPE_JPEG, IMAGETYPE_WEBP], true)) {
            return null;
        }
        if (min($size[0], $size[1]) < self::MIN_SOURCE_SIZE) {
            return null;
        }

        return $data;
    }

    /**
     * Square PNG of the given size. $padded: opaque white background and the
     * motif scaled to 80 % (maskable/Apple icons get cropped by the OS and
     * iOS renders transparency black).
     */
    public function render(string $image, int $size, bool $padded): ?string
    {
        if (!extension_loaded('gd')) {
            return null;
        }
        $source = @imagecreatefromstring($image);
        if ($source === false) {
            return null;
        }
        $canvas = imagecreatetruecolor($size, $size);
        imagesavealpha($canvas, true);
        imagealphablending($canvas, false);
        $background = $padded
            ? (int) imagecolorallocatealpha($canvas, 255, 255, 255, 0)
            : (int) imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $background);
        imagealphablending($canvas, true);

        $width = imagesx($source);
        $height = imagesy($source);
        $box = $padded ? (int) round($size * 0.8) : $size;
        $scale = min($box / $width, $box / $height);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        imagecopyresampled($canvas, $source, (int) (($size - $targetWidth) / 2), (int) (($size - $targetHeight) / 2), 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagepng($canvas, null, 9);
        $out = ob_get_clean();

        return $out === false || $out === '' ? null : $out;
    }
}
