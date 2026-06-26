<?php

namespace Kirbycode\MediaHub\Setup;

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;

class MediaHubSetup
{
    /**
     * Creates content/media-hub/ on first plugin load.
     * Guarded: does nothing if the directory already exists.
     */
    public static function ensureStructure(): void
    {
        $kirby = App::instance();
        $slug  = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
        if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', (string) $slug)) {
            $slug = 'media-hub';
        }
        $root  = $kirby->root('content') . '/' . $slug;

        if (Dir::exists($root)) {
            return;
        }

        Dir::make($root);

        // Minimal content file — Kirby assigns a UUID on first page access
        $content = "Title: Media Hub\n";
        file_put_contents($root . '/' . $slug . '.txt', $content);
    }
}
