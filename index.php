<?php

use Kirby\Cms\App;

require_once __DIR__ . '/src/Setup/MediaHubSetup.php';

App::plugin('kirbycode/media-hub', [

    // ── Panel area ──────────────────────────────────────────────────────────
    'areas' => [
        'media-hub' => function () {
            $kirby = App::instance();
            $slug  = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');

            return [
                'label'  => 'Media Hub',
                'icon'   => 'image',
                'menu'   => true,
                'link'   => 'media-hub',

                'views'  => [

                    // Main library view
                    [
                        'pattern' => 'media-hub',
                        'action'  => function () use ($slug) {
                            $kirby  = App::instance();
                            $root   = $kirby->page($slug);
                            $apiUrl = $kirby->url('api') . '/media-hub';

                            $folders = [];
                            if ($root) {
                                foreach ($root->children()->listed() as $p) {
                                    $folders[] = [
                                        'id'        => $p->id(),
                                        'slug'      => $p->slug(),
                                        'title'     => $p->title()->value(),
                                        'fileCount' => $p->files()->count(),
                                    ];
                                }
                            }

                            return [
                                'component' => 'k-media-hub-view',
                                'title'     => 'Media Hub',
                                'props'     => [
                                    'folders'       => $folders,
                                    'currentFolder' => null,
                                    'apiUrl'        => $apiUrl,
                                    'uploadApiBase' => 'pages/' . $slug,
                                ],
                            ];
                        },
                    ],

                    // Folder view
                    [
                        'pattern' => 'media-hub/(:any)',
                        'action'  => function (string $folderSlug) use ($slug) {
                            $kirby  = App::instance();
                            $root   = $kirby->page($slug);
                            $folder = $root ? $kirby->page($slug . '/' . $folderSlug) : null;
                            $apiUrl = $kirby->url('api') . '/media-hub';

                            $folders = [];
                            if ($root) {
                                foreach ($root->children()->listed() as $p) {
                                    $folders[] = [
                                        'id'        => $p->id(),
                                        'slug'      => $p->slug(),
                                        'title'     => $p->title()->value(),
                                        'fileCount' => $p->files()->count(),
                                    ];
                                }
                            }

                            return [
                                'component' => 'k-media-hub-view',
                                'title'     => $folder ? $folder->title()->value() : 'Media Hub',
                                'props'     => [
                                    'folders'       => $folders,
                                    'currentFolder' => $folderSlug,
                                    'apiUrl'        => $apiUrl,
                                    'uploadApiBase' => 'pages/' . $slug . '+' . $folderSlug,
                                ],
                            ];
                        },
                    ],

                ],
            ];
        },
    ],

    // ── Custom API routes ───────────────────────────────────────────────────
    'api' => [
        'routes' => require __DIR__ . '/src/Api/routes.php',
    ],

    // ── Custom field type ───────────────────────────────────────────────────
    'fields' => [
        'mediahubpicker' => require __DIR__ . '/src/Fields/mediahubpicker.php',
    ],

    // ── Blueprint registration ──────────────────────────────────────────────
    'blueprints' => [
        'pages/media-hub'        => __DIR__ . '/blueprints/pages/media-hub.yml',
        'pages/media-hub-folder' => __DIR__ . '/blueprints/pages/media-hub-folder.yml',
        'files/media-hub-asset'  => __DIR__ . '/blueprints/files/media-hub-asset.yml',
    ],

    // ── Hooks ───────────────────────────────────────────────────────────────
    'hooks' => [
        'system.loadPlugins:after' => function () {
            \Kirbycode\MediaHub\MediaHubSetup::ensureStructure();
        },
    ],

]);
