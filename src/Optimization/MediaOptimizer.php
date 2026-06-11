<?php

namespace Kirbycode\MediaHub;

use Kirby\Cms\App;
use Kirby\Cms\File;

class MediaOptimizer
{
    // ── Public entry points ────────────────────────────────────────────────────

    /**
     * Called from file.create:after hook — AFTER uploadedby is written to the sidecar.
     */
    public static function optimizeOnUpload(File $file): array
    {
        return self::run($file);
    }

    /**
     * Called from POST media-hub/files/(:any)/optimize API route.
     */
    public static function optimize(File $file): array
    {
        return self::run($file);
    }

    // ── Core logic ─────────────────────────────────────────────────────────────

    private static function run(File $file): array
    {
        if ($file->type() !== 'image') return self::noop();
        if (in_array($file->extension(), ['svg', 'gif'], true)) return self::noop();

        $opt = App::instance()->option('kirbycode.media-hub.optimization', []);
        if (($opt['enabled'] ?? true) === false) return self::noop();

        $ext = strtolower($file->extension());

        // JPEG/PNG → convert to WebP (sidecar copy preserves UUID + all metadata)
        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return self::convertToWebP($file, $opt);
        }

        // Already WebP → compress in-place
        if ($ext === 'webp') {
            $compressed = self::compressInPlace($file, $opt);
            return [
                'converted'   => false,
                'compressed'  => $compressed,
                'newFilename' => $file->filename(),
                'newId'       => $file->id(),
                'uuid'        => $file->uuid()->id(),
            ];
        }

        return self::noop();
    }

    // ── WebP conversion ────────────────────────────────────────────────────────

    /**
     * Convert JPEG/PNG to WebP via GD, copy the .txt sidecar (preserving UUID and
     * all metadata), then delete the original files.
     */
    private static function convertToWebP(File $file, array $opt): array
    {
        if (!extension_loaded('gd')) {
            return array_merge(self::noop(), ['error' => 'GD extension not available']);
        }

        $ext  = strtolower($file->extension());
        $root = $file->root();

        if (!is_readable($root)) return self::noop();

        // Load source image
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $image = @imagecreatefromjpeg($root);
                break;
            case 'png':
                $image = @imagecreatefrompng($root);
                // Preserve transparency
                if ($image) {
                    imagepalettetotruecolor($image);
                    imagealphablending($image, false);
                    imagesavealpha($image, true);
                }
                break;
            default:
                return self::noop();
        }

        if (!$image) return self::noop();

        $quality  = ($opt['quality'] ?? [])['webp'] ?? 82;
        $origSize = (int) filesize($root);
        $webpRoot = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $root);

        $ok = imagewebp($image, $webpRoot, $quality);
        imagedestroy($image);

        if (!$ok || !file_exists($webpRoot)) return self::noop();

        // Copy sidecar — preserves UUID, title, alt, description, tags, uploadedby
        $sidecarSrc = $root . '.txt';
        $sidecarDst = $webpRoot . '.txt';
        if (file_exists($sidecarSrc)) {
            @copy($sidecarSrc, $sidecarDst);
        }

        // Delete originals
        @unlink($root);
        @unlink($sidecarSrc);
        @clearstatcache();

        $newSize     = (int) filesize($webpRoot);
        $newFilename = basename($webpRoot);
        $newId       = $file->parent()->id() . '/' . $newFilename;

        return [
            'converted'   => true,
            'compressed'  => [
                'originalSize' => $origSize,
                'newSize'      => $newSize,
                'saved'        => $origSize - $newSize,
                'percent'      => $origSize > 0
                    ? round(100 - ($newSize / $origSize * 100), 1)
                    : 0,
            ],
            'newFilename' => $newFilename,
            'newId'       => $newId,
            'uuid'        => $file->uuid()->id(),
        ];
    }

    // ── In-place WebP compression ──────────────────────────────────────────────

    /**
     * Re-encode an existing WebP at the configured quality. Replaces the file
     * only if the result is smaller.
     */
    private static function compressInPlace(File $file, array $opt): ?array
    {
        if (!extension_loaded('gd')) return null;

        $root = $file->root();
        if (!is_readable($root) || !is_writable($root)) return null;

        $quality = ($opt['quality'] ?? [])['webp'] ?? 82;
        $image   = @imagecreatefromwebp($root);
        if (!$image) return null;

        $origSize = (int) filesize($root);
        $tmp      = $root . '.opt_tmp';
        $ok       = imagewebp($image, $tmp, $quality);
        imagedestroy($image);

        if (!$ok || !file_exists($tmp)) {
            @unlink($tmp);
            return null;
        }

        $newSize = (int) filesize($tmp);

        if ($newSize >= $origSize) {
            @unlink($tmp);
            return [
                'originalSize' => $origSize,
                'newSize'      => $origSize,
                'saved'        => 0,
                'percent'      => 0,
            ];
        }

        @rename($tmp, $root);
        @clearstatcache(true, $root);

        return [
            'originalSize' => $origSize,
            'newSize'      => $newSize,
            'saved'        => $origSize - $newSize,
            'percent'      => $origSize > 0
                ? round(100 - ($newSize / $origSize * 100), 1)
                : 0,
        ];
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    private static function noop(): array
    {
        return [
            'converted'   => false,
            'compressed'  => null,
            'newFilename' => null,
            'newId'       => null,
            'uuid'        => null,
        ];
    }
}
