<?php

namespace Kirbycode\MediaHub\Files;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Exception\InvalidArgumentException;
use Kirby\Filesystem\F;
use Kirbycode\MediaHub\Licensing\LicenseManager;
use Kirbycode\MediaHub\Optimization\MediaOptimizer;

/**
 * Swaps the bytes of a Media Hub file for a newly uploaded one while keeping
 * the same Kirby File: UUID, metadata sidecar and parent stay put, so every
 * file:// reference on the site shows the new version.
 *
 * Kirby's own File::replace() refuses uploads whose MIME type AND extension
 * both differ (file.mime.differs) — e.g. a camera JPG onto a stored .webp.
 * This bridges that: JPG/PNG is encoded to WebP when the target is WebP
 * (or Pro optimization would convert it anyway); any other extension change
 * renames the file first, then replaces.
 *
 * Runs as the current user, so Kirby's replace/changeName permissions and
 * the media-hub-asset upload rules all apply.
 */
class FileReplacer
{
    public static function replace(File $file, string $source, string $uploadName): File
    {
        $uploadExt = strtolower(F::extension($uploadName));
        $fileType  = $file->type();

        // An image stays an image (a block expecting one would break otherwise)
        if (F::type($uploadName) !== $fileType) {
            $kind    = $fileType ?? 'matching';
            $article = in_array($kind[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
            throw new InvalidArgumentException(
                message: 'Please choose ' . $article . ' ' . $kind . ' file to replace "' . $file->filename() . '"'
            );
        }

        $webpTmp = null;
        try {
            if (in_array($uploadExt, ['jpg', 'jpeg', 'png'], true) && self::wantsWebp($file)) {
                $opt     = App::instance()->option('kirbycode.media-hub.optimization', []);
                $webpTmp = MediaOptimizer::encodeWebp($source, $uploadExt, is_array($opt) ? $opt : []);
                if ($webpTmp !== null) {
                    $uploadExt = 'webp';
                }
            }

            $replaceWith = $webpTmp ?? $source;
            $oldExt      = $file->extension();
            $renamed     = false;

            // Different extension: rename first so Kirby's replace rule
            // (same MIME or same extension) is satisfied
            if ($uploadExt !== strtolower($oldExt)) {
                $file    = $file->changeName($file->name(), false, $uploadExt);
                $renamed = true;
            }

            try {
                $file = $file->replace($replaceWith, true);
            } catch (\Throwable $e) {
                if ($renamed) {
                    try {
                        $live = $file->parent()->file($file->filename()) ?? $file;
                        $live->changeName($live->name(), false, $oldExt);
                    } catch (\Throwable $rollback) {
                        error_log('[MediaHub] replace rollback failed for ' . $file->filename() . ': ' . $rollback->getMessage());
                    }
                }
                throw $e;
            }
        } finally {
            // replace() moved whichever file it used; remove the other one
            foreach ([$webpTmp, $source] as $tmp) {
                if ($tmp !== null && file_exists($tmp)) {
                    @unlink($tmp);
                }
            }
        }

        return $file->parent()->file($file->filename()) ?? $file;
    }

    /**
     * Encode JPG/PNG uploads to WebP when the file is already WebP (keeps its
     * name) or when Pro upload optimization would convert it anyway.
     */
    private static function wantsWebp(File $file): bool
    {
        if (strtolower($file->extension()) === 'webp') {
            return true;
        }

        $opt = App::instance()->option('kirbycode.media-hub.optimization', []);
        if (is_array($opt) && ($opt['enabled'] ?? true) === false) {
            return false;
        }

        return LicenseManager::isPro();
    }
}
