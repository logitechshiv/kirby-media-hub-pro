<?php

use Kirby\Cms\App;

if (!class_exists(\Kirbycode\MediaHub\Licensing\LicenseManager::class)) {
    require_once __DIR__ . '/src/Setup/MediaHubSetup.php';
    require_once __DIR__ . '/src/Optimization/MediaOptimizer.php';
    require_once __DIR__ . '/src/Licensing/LicenseManager.php';
    require_once __DIR__ . '/src/Licensing/UpdateChecker.php';
}

App::plugin('kirbycode/media-hub', [

    // ── License cache driver ────────────────────────────────────────────────
    'cache' => [
        'kirbycode-media-hub-license' => true,
    ],

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
                                    $childList = [];
                                    foreach ($p->children()->listed() as $c) {
                                        $childList[] = [
                                            'id'        => $c->id(),
                                            'slug'      => $c->slug(),
                                            'path'      => $p->slug() . '/' . $c->slug(),
                                            'title'     => $c->title()->value(),
                                            'fileCount' => $c->files()->count(),
                                            'children'  => [],
                                        ];
                                    }
                                    $folders[] = [
                                        'id'        => $p->id(),
                                        'slug'      => $p->slug(),
                                        'path'      => $p->slug(),
                                        'title'     => $p->title()->value(),
                                        'fileCount' => $p->files()->count(),
                                        'children'  => $childList,
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
                                    'isPro'           => \Kirbycode\MediaHub\Licensing\LicenseManager::isPro(),
                                    'isAdmin'         => $kirby->user() ? $kirby->user()->isAdmin() : false,
                                    'updateAvailable' => \Kirbycode\MediaHub\Licensing\UpdateChecker::hasUpdate(),
                                    'latestVersion'   => \Kirbycode\MediaHub\Licensing\UpdateChecker::latestVersion() ?? '',
                                ],
                            ];
                        },
                    ],

                    // License status view
                    [
                        'pattern' => 'media-hub/license',
                        'action'  => function () use ($slug) {
                            $kirby  = App::instance();
                            $apiUrl = $kirby->url('api') . '/media-hub';
                            return [
                                'component' => 'k-media-hub-license-view',
                                'title'     => 'Media Hub — License',
                                'props'     => [
                                    'apiUrl'          => $apiUrl,
                                    'status'          => \Kirbycode\MediaHub\Licensing\LicenseManager::getStatus(),
                                    'updateAvailable' => \Kirbycode\MediaHub\Licensing\UpdateChecker::hasUpdate(),
                                    'latestVersion'   => \Kirbycode\MediaHub\Licensing\UpdateChecker::latestVersion() ?? '',
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
                                    $childList = [];
                                    foreach ($p->children()->listed() as $c) {
                                        $childList[] = [
                                            'id'        => $c->id(),
                                            'slug'      => $c->slug(),
                                            'path'      => $p->slug() . '/' . $c->slug(),
                                            'title'     => $c->title()->value(),
                                            'fileCount' => $c->files()->count(),
                                            'children'  => [],
                                        ];
                                    }
                                    $folders[] = [
                                        'id'        => $p->id(),
                                        'slug'      => $p->slug(),
                                        'path'      => $p->slug(),
                                        'title'     => $p->title()->value(),
                                        'fileCount' => $p->files()->count(),
                                        'children'  => $childList,
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
                                    'isPro'           => \Kirbycode\MediaHub\Licensing\LicenseManager::isPro(),
                                    'isAdmin'         => $kirby->user() ? $kirby->user()->isAdmin() : false,
                                    'updateAvailable' => \Kirbycode\MediaHub\Licensing\UpdateChecker::hasUpdate(),
                                    'latestVersion'   => \Kirbycode\MediaHub\Licensing\UpdateChecker::latestVersion() ?? '',
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
            \Kirbycode\MediaHub\Setup\MediaHubSetup::ensureStructure();
        },

        // Capture the uploading user whenever a file is created inside media-hub
        'file.create:after' => function ($file) {
            $kirby = App::instance();
            $slug  = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            if (!str_starts_with($file->parent()->id(), $slug)) {
                return;
            }
            $user = $kirby->user();
            if (!$user) return;
            try {
                $display = $user->name()->isNotEmpty()
                    ? (string) $user->name()
                    : $user->email();
                $kirby->impersonate('kirby', function () use ($file, $display) {
                    $file->update(['uploadedby' => $display]);
                });
            } catch (\Throwable $e) {
                // non-critical — don't break the upload if this fails
            }

            // Convert to WebP and compress — V2 Pro feature
            if (\Kirbycode\MediaHub\Licensing\LicenseManager::isPro()) {
                try {
                    \Kirbycode\MediaHub\Optimization\MediaOptimizer::optimizeOnUpload($file);
                } catch (\Throwable $e) {
                    // non-critical — never break the upload
                }
            }
        },
    ],

]);
