<?php

namespace Kirbycode\MediaHub\Optimization;

use Kirby\Cms\App;
use Kirby\Cms\File;

class MediaOptimizer
{
    // Prevents re-entrant optimization if replace/rename fires file hooks
    private static bool $converting = false;

    // ── Public entry points ────────────────────────────────────────────────────

    /**
     * Called from file.create:after hook.
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
        if (self::$converting) return self::noop();
        if ($file->type() !== 'image') return self::noop();
        if (in_array($file->extension(), ['svg', 'gif'], true)) return self::noop();

        $opt = App::instance()->option('kirbycode.media-hub.optimization', []);
        if (($opt['enabled'] ?? true) === false) return self::noop();

        $ext = strtolower($file->extension());

        if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            return self::convertToWebP($file, $opt);
        }

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
     * Convert JPEG/PNG to WebP on the same Kirby File so UUID, parent, and
     * file:// references stay intact. Never creates a second file.
     */
    private static function convertToWebP(File $file, array $opt): array
    {
        if (!extension_loaded('gd')) {
            return array_merge(self::noop(), ['error' => 'GD extension not available']);
        }

        $guard = self::guardSize($file);
        if ($guard !== null) return $guard;

        $ext  = strtolower($file->extension());
        $root = $file->root();

        if (!is_readable($root)) return self::noop();

        $image = null;
        switch ($ext) {
            case 'jpg':
            case 'jpeg':
                $image = imagecreatefromjpeg($root);
                break;
            case 'png':
                $image = imagecreatefrompng($root);
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

        $quality     = ($opt['quality'] ?? [])['webp'] ?? 82;
        $origSize    = (int) filesize($root);
        $newFilename = (string) preg_replace('/\.(jpe?g|png)$/i', '.webp', $file->filename());
        $tmpPath     = sys_get_temp_dir() . '/' . uniqid('mh_', true) . '.webp';

        $ok = imagewebp($image, $tmpPath, $quality);
        imagedestroy($image);

        if (!$ok || !file_exists($tmpPath)) return self::noop();

        $newSize = (int) filesize($tmpPath);
        $oldUuid = $file->uuid()->id();
        $parent  = $file->parent();

        // A sibling .webp from a previous failed convert would make changeName throw.
        // Abort without creating another file — keep the original.
        if ($parent->file($newFilename)) {
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
            error_log('[MediaHub] convertToWebP skipped: ' . $newFilename . ' already exists');
            return self::noop();
        }

        $newFile = null;

        self::$converting = true;
        try {
            $kirby = App::instance();

            // Same File: swap bytes, then rename extension. Reload from Kirby
            // after replace so Kirby 5 immutable storage does not block changeName.
            $newFile = $kirby->impersonate('kirby', function () use ($file, $tmpPath, $kirby) {
                $live = $file->parent()->file($file->filename()) ?? $file;
                $replaced = $live->replace($tmpPath, true);

                $parentId = $replaced->parent()->id();
                $filename = $replaced->filename();
                $parent   = $kirby->page($parentId);
                $fresh    = $parent ? $parent->file($filename) : $replaced;

                return $fresh->changeName($fresh->name(), false, 'webp');
            });
        } catch (\Throwable $e) {
            error_log('[MediaHub] convertToWebP failed: ' . $e->getMessage());
            return self::noop();
        } finally {
            self::$converting = false;
            if (file_exists($tmpPath)) {
                unlink($tmpPath);
            }
        }

        if (!$newFile) {
            return self::noop();
        }

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
            'newFilename' => $newFile->filename(),
            'newId'       => $newFile->id(),
            'uuid'        => $oldUuid,
        ];
    }

    // ── In-place WebP compression ──────────────────────────────────────────────

    /**
     * Re-encode an existing WebP at the configured quality. Replaces file content
     * only if the result is smaller; metadata and UUID sidecar are untouched.
     */
    private static function compressInPlace(File $file, array $opt): ?array
    {
        if (!extension_loaded('gd')) return null;

        $root = $file->root();
        if (!is_readable($root) || !is_writable($root)) return null;

        $maxBytes = 25 * 1024 * 1024;
        if (filesize($root) > $maxBytes) return null;
        $dims = @getimagesize($root);
        if ($dims && ($dims[0] > 8000 || $dims[1] > 8000)) return null;

        $quality  = ($opt['quality'] ?? [])['webp'] ?? 82;
        $image    = imagecreatefromwebp($root);
        if (!$image) return null;

        $origSize = (int) filesize($root);
        $tmp      = $root . '.opt_tmp';
        $ok       = imagewebp($image, $tmp, $quality);
        imagedestroy($image);

        if (!$ok || !file_exists($tmp)) {
            if (file_exists($tmp)) {
                unlink($tmp);
            }
            return null;
        }

        $newSize = (int) filesize($tmp);

        if ($newSize >= $origSize) {
            unlink($tmp);
            return [
                'originalSize' => $origSize,
                'newSize'      => $origSize,
                'saved'        => 0,
                'percent'      => 0,
            ];
        }

        if (!rename($tmp, $root)) {
            error_log('[MediaHub] compressInPlace: rename failed for ' . basename($root));
            unlink($tmp);
            return null;
        }

        clearstatcache(true, $root);

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

    /**
     * Returns a skipped-noop array if the file exceeds safe GD processing limits,
     * or null if the file is within limits and safe to process.
     */
    private static function guardSize(File $file): ?array
    {
        $maxBytes = 25 * 1024 * 1024;
        if (filesize($file->root()) > $maxBytes) {
            return array_merge(self::noop(), ['skipped' => true, 'reason' => 'File too large for optimization']);
        }
        $dims = @getimagesize($file->root());
        if ($dims && ($dims[0] > 8000 || $dims[1] > 8000)) {
            return array_merge(self::noop(), ['skipped' => true, 'reason' => 'Image dimensions too large']);
        }
        return null;
    }

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
