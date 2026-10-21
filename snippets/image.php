<?php

/**
 * Responsive <img> with the file's focus point applied.
 *
 *   <?php snippet('media-hub/image', ['file' => $image, 'class' => 'object-cover w-full h-full']) ?>
 *
 * Width-only thumbs (never squished); with `object-fit: cover` the browser
 * keeps the focus point in view via `object-position`. Pass `crop` to get
 * server-side crops instead — those use the focus point too.
 *
 * @var \Kirby\Cms\File        $file
 * @var string|null            $class
 * @var string|null            $alt           default: alt field, then filename
 * @var string|null            $sizes         default: 100vw
 * @var string|array|null      $srcset        preset name from `thumbs.srcsets` or an array; default: 'default'
 * @var int|null               $fallbackWidth width of the src thumb; default: 1600
 * @var string|null            $format        thumb format, e.g. 'webp'; default: original
 * @var int|null               $quality
 * @var array|null             $crop          [width, height] — server-side crop around the focus point
 * @var string|null            $loading       default: lazy
 * @var string|null            $fetchpriority
 * @var string|int|null        $width
 * @var string|int|null        $height
 * @var string|null            $style         extra inline CSS, merged with the focus style
 * @var array|null             $attrs         any other attributes
 */

if (!isset($file) || !$file instanceof \Kirby\Cms\File) {
    return;
}

$alt           = $alt ?? $file->content()->get('alt')->or($file->filename())->value();
$sizes         = $sizes ?? '100vw';
$loading       = $loading ?? 'lazy';
$fallbackWidth = $fallbackWidth ?? 1600;
$format        = $format ?? null;
$quality       = $quality ?? null;
$crop          = $crop ?? null;
$srcsetPreset  = $srcset ?? 'default';

$thumbOptions = array_filter(['format' => $format, 'quality' => $quality]);
$ext          = strtolower((string) $file->extension());
$resizable    = $file->type() === 'image' && !in_array($ext, ['svg', 'gif'], true);

$src    = $file->url();
$srcset = null;

if ($resizable) {
    if (is_array($crop) && count($crop) === 2) {
        // Kirby's crop() reads the focus field itself
        [$cw, $ch] = array_map('intval', array_values($crop));
        $src    = $file->thumb($thumbOptions + ['width' => $cw, 'height' => $ch, 'crop' => true])->url();
        $srcset = $file->thumb($thumbOptions + ['width' => $cw * 2, 'height' => $ch * 2, 'crop' => true])->url() . ' 2x';
        $width  ??= $cw;
        $height ??= $ch;
    } else {
        $src    = $file->thumb($thumbOptions + ['width' => $fallbackWidth])->url();
        $srcset = $file->srcset($srcsetPreset);
        if (!$srcset) {
            $widths = [];
            foreach ([400, 800, 1200, 1800] as $w) {
                $widths[$w . 'w'] = $thumbOptions + ['width' => $w];
            }
            $srcset = $file->srcset($widths);
        }
    }
}

$styleParts = array_filter([
    $crop ? null : $file->focusStyle(),
    isset($style) ? rtrim(trim((string) $style), ';') : null,
]);

echo \Kirby\Toolkit\Html::img($src, array_merge([
    'alt'           => $alt,
    'srcset'        => $srcset ?: null,
    'sizes'         => ($srcset && !$crop) ? $sizes : null,
    'class'         => $class ?? null,
    'style'         => $styleParts ? implode('; ', $styleParts) : null,
    'loading'       => $loading,
    'decoding'      => 'async',
    'fetchpriority' => $fetchpriority ?? null,
    'width'         => $width ?? null,
    'height'        => $height ?? null,
], $attrs ?? []));
