<?php

namespace Kirbycode\MediaHub\Focus;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Image\Focus;

/**
 * Applies each image's focus point (Kirby's native `focus` field, set in the
 * Media Hub or Kirby's file view) to every <img> in a rendered page.
 *
 * Adds `object-position: X% Y%` so `object-fit: cover` images keep the chosen
 * spot in view. Images without a focus point, and images that already set an
 * inline object-position, are left alone, so the stylesheet stays in charge.
 *
 * Also available on its own, for sites without the Media Hub, as the
 * Media Focus Point add-on: https://github.com/logitechshiv/media-focus-point
 */
class FocusPoint
{
    /**
     * The file's focus point as "X% Y%" (each 0–100, one decimal), or null when
     * none is set. Accepts every format Kirby does: "X% Y%", keywords such as
     * "top left", and the JSON of the former Focus plugin.
     */
    public static function position(File $file): ?string
    {
        $value = trim((string) $file->content()->get('focus')->value());
        if ($value === '') {
            return null;
        }

        try {
            $value = Focus::normalize($value);
        } catch (\Throwable $e) {
            return null;
        }

        if (!preg_match('/^(-?\d+(?:\.\d+)?)% (-?\d+(?:\.\d+)?)%$/', $value, $m)) {
            return null;
        }

        $x = round(min(100, max(0, (float) $m[1])), 1);
        $y = round(min(100, max(0, (float) $m[2])), 1);

        return $x . '% ' . $y . '%';
    }

    /**
     * "object-position: X% Y%" for an image file, or '' when there is none.
     */
    public static function style(File $file): string
    {
        if ($file->type() !== 'image') {
            return '';
        }

        $position = self::position($file);

        return $position ? 'object-position: ' . $position : '';
    }

    /**
     * Whether the page.render:after hook rewrites <img> tags on this site.
     * Read by the Media Focus Point add-on too, so it steps aside.
     */
    public static function frontendEnabled(): bool
    {
        return App::instance()->option('kirbycode.media-hub.focus-frontend', true) !== false;
    }

    /**
     * Resolves a media URL (original or thumb) to its File. Media URLs look like
     * …/media/pages/<page-id>/<hash>/<name> or …/media/site/<hash>/<name>, and
     * the hash is unique per file, so it identifies the file even for thumbs.
     */
    public static function fileFromUrl(App $kirby, string $url): ?File
    {
        $path = parse_url($url, PHP_URL_PATH) ?: '';

        if (preg_match('#/media/pages/(.+)/([a-z0-9]+-\d+)/[^/]+$#i', $path, $m)) {
            $parent = $kirby->site()->findPageOrDraft(rawurldecode($m[1]));
            $hash   = $m[2];
        } elseif (preg_match('#/media/site/([a-z0-9]+-\d+)/[^/]+$#i', $path, $m)) {
            $parent = $kirby->site();
            $hash   = $m[1];
        } else {
            return null;
        }

        if ($parent === null) {
            return null;
        }

        foreach ($parent->files() as $file) {
            if ($file->mediaHash() === $hash) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Adds the focus point to every <img> in $html whose src (or data-src)
     * points to an image with a focus point.
     */
    public static function apply(string $html): string
    {
        if (stripos($html, '<img') === false) {
            return $html;
        }

        $kirby = App::instance();
        $cache = [];

        return preg_replace_callback('/<img\b[^>]*>/i', function (array $tag) use ($kirby, &$cache) {
            $img = $tag[0];

            // An explicit inline position wins over the focus point
            if (preg_match('/\sstyle\s*=\s*(["\'])[^"\']*object-position/i', $img)) {
                return $img;
            }

            if (!preg_match('/\s(?:data-)?src\s*=\s*(["\'])(.*?)\1/i', $img, $src)) {
                return $img;
            }

            $url = html_entity_decode($src[2], ENT_QUOTES);

            if (!array_key_exists($url, $cache)) {
                $file        = self::fileFromUrl($kirby, $url);
                $cache[$url] = $file ? self::style($file) : '';
            }

            if ($cache[$url] === '') {
                return $img;
            }

            $style = esc($cache[$url]);

            // Append to an existing style attribute, or add one
            if (preg_match('/\sstyle\s*=\s*(["\'])(.*?)\1/i', $img, $existing)) {
                $merged = rtrim(trim($existing[2]), ';');
                $merged = ($merged !== '' ? $merged . '; ' : '') . $style;
                return str_replace($existing[0], ' style=' . $existing[1] . $merged . $existing[1], $img);
            }

            return preg_replace('/\s*(\/?)>$/', ' style="' . $style . '"$1>', $img, 1);
        }, $html) ?? $html;
    }
}
