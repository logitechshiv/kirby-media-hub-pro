<?php

use Kirby\Cms\App;

if (!class_exists(\Kirbycode\MediaHub\Licensing\LicenseManager::class)) {
    require_once __DIR__ . '/src/Setup/MediaHubSetup.php';
    require_once __DIR__ . '/src/Optimization/MediaOptimizer.php';
    require_once __DIR__ . '/src/Files/FileReplacer.php';
    require_once __DIR__ . '/src/Licensing/LicenseManager.php';
    require_once __DIR__ . '/src/Licensing/UpdateChecker.php';
    require_once __DIR__ . '/src/Api/Helpers.php';
}

App::plugin(
    'kirbycode/media-hub',
    [

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
                                    'updateAvailable' => ($updateLatest = \Kirbycode\MediaHub\Licensing\UpdateChecker::latestVersion()) !== null,
                                    'latestVersion'   => $updateLatest ?? '',
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
                                    'updateAvailable' => ($updateLatest = \Kirbycode\MediaHub\Licensing\UpdateChecker::latestVersion()) !== null,
                                    'latestVersion'   => $updateLatest ?? '',
                                ],
                            ];
                        },
                    ],

                    // Folder view — /media-hub/photos or /media-hub/photos/2024
                    // (registered after 'media-hub/license', which wins for that exact path)
                    [
                        'pattern' => 'media-hub/(:all)',
                        'action'  => function (string $folderPath) use ($slug) {
                            $kirby  = App::instance();
                            $root   = $kirby->page($slug);
                            $folder = $root && \Kirbycode\MediaHub\Api\Helpers::validatePath($folderPath, $slug)
                                ? $kirby->page($slug . '/' . $folderPath)
                                : null;
                            // Unknown/invalid path → behave like the main view
                            $folderPath = $folder ? $folderPath : null;
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
                                    'currentFolder' => $folderPath,
                                    'apiUrl'        => $apiUrl,
                                    // Always the root: the view appends the active folder itself
                                    'uploadApiBase' => 'pages/' . $slug,
                                    'isPro'           => \Kirbycode\MediaHub\Licensing\LicenseManager::isPro(),
                                    'isAdmin'         => $kirby->user() ? $kirby->user()->isAdmin() : false,
                                    'updateAvailable' => ($updateLatest = \Kirbycode\MediaHub\Licensing\UpdateChecker::latestVersion()) !== null,
                                    'latestVersion'   => $updateLatest ?? '',
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
        // Callable so sites can replace the upload whitelist:
        // 'kirbycode.media-hub.accept' => ['extension' => ['jpg', 'png'], 'maxsize' => 10485760]
        'files/media-hub-asset'  => function () {
            $blueprint = \Kirby\Data\Yaml::read(__DIR__ . '/blueprints/files/media-hub-asset.yml');
            $accept    = App::instance()->option('kirbycode.media-hub.accept');
            if (is_array($accept) || is_string($accept)) {
                $blueprint['accept'] = $accept;
            }
            return $blueprint;
        },
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
            // Exact boundary check — a plain prefix match would also catch pages
            // like 'media-hub-docs' and convert their uploads
            $parent = $file->parent();
            if (!$parent instanceof \Kirby\Cms\Page
                || !\Kirbycode\MediaHub\Api\Helpers::isInsideRoot($parent->id(), $slug)) {
                return;
            }
            $user = $kirby->user();
            if (!$user) return;

            $working = $file;
            try {
                $display = $user->name()->isNotEmpty()
                    ? (string) $user->name()
                    : $user->email();
                $working = $kirby->impersonate('kirby', function () use ($file, $display) {
                    // Kirby 5: update() freezes $file — always use the returned instance
                    return $file->update(['uploadedby' => $display]);
                });
            } catch (\Throwable $e) {
                // non-critical — don't break the upload if this fails
            }

            // Convert to WebP and compress — V2 Pro feature
            if (\Kirbycode\MediaHub\Licensing\LicenseManager::isPro()) {
                try {
                    $result = \Kirbycode\MediaHub\Optimization\MediaOptimizer::optimizeOnUpload($working);
                    if (!empty($result['converted']) && !empty($result['newId'])) {
                        $working = $kirby->file($result['newId']) ?? $working;
                    }
                } catch (\Throwable $e) {
                    // non-critical — never break the upload
                }
            }

            // Kirby uses an after-hook's return value as the result of createFile().
            // Returning the final (renamed) file keeps the upload API response on
            // the real .webp. Otherwise Kirby serialises the stale .jpg object, which
            // writes an orphan "<name>.jpg.txt" (Uuid + Template only) next to the
            // .webp — and that orphan made folder deletes need a second attempt.
            return $working;
        },
    ],

],
    version: \Kirbycode\MediaHub\Licensing\UpdateChecker::CURRENT_VERSION
);
