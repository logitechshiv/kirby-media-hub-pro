<?php

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Toolkit\Str;

/**
 * Serialize a Kirby File to an array for API responses.
 */
function mediaHubSerializeFile(File $file, bool $detailed = false): array
{
    $thumb = null;
    if ($file->type() === 'image') {
        try {
            $thumb = $file->thumb(['width' => 400])->url();
        } catch (\Throwable $e) {
            $thumb = $file->url();
        }
    }

    $data = [
        'id'           => $file->id(),
        'uuid'         => $file->uuid()->id(),
        'filename'     => $file->filename(),
        'url'          => $file->url(),
        'thumb'        => $thumb,
        'type'         => $file->type(),
        'extension'    => $file->extension(),
        'niceSize'     => $file->niceSize(),
        'size'         => $file->size(),
        'modified'     => $file->modified('Y-m-d'),
        'parent'       => $file->parent()->id(),
        'title'        => (string) $file->content()->get('title')->value(),
        'alt'          => (string) $file->content()->get('alt')->value(),
        'description'  => (string) $file->content()->get('description')->value(),
        'copyright'    => (string) $file->content()->get('copyright')->value(),
        'photographer' => (string) $file->content()->get('photographer')->value(),
    ];

    if ($detailed) {
        $data['uploaddate'] = (string) $file->content()->get('uploaddate')->value();
    }

    return $data;
}

/**
 * All custom API routes for the Media Hub.
 * Patterns are relative to /api/ — e.g. 'media-hub/files' → GET /api/media-hub/files
 */
return [

    // ── 1. List files ───────────────────────────────────────────────────────
    // If ?folder=slug is set, returns only that folder's files.
    // Otherwise returns ALL files across root + every subfolder.
    [
        'pattern' => 'media-hub/files',
        'method'  => 'GET',
        'auth'    => true,
        'action'  => function () {
            $kirby   = App::instance();
            $request = $kirby->request();
            $slug    = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $folder  = $request->get('folder');
            $query   = trim((string) $request->get('q', ''));
            $type    = (string) $request->get('type', '');
            $page    = max(1, (int) $request->get('page', 1));
            $limit   = 40;

            $root = $kirby->page($slug);
            if (!$root) {
                return ['data' => [], 'pagination' => ['total' => 0, 'page' => 1, 'limit' => $limit]];
            }

            // Build flat PHP array — needed for cross-folder collection
            $allFiles = [];

            if ($folder) {
                // Specific folder only
                $folderPage = $kirby->page($slug . '/' . $folder);
                if ($folderPage) {
                    foreach ($folderPage->files() as $f) {
                        $allFiles[] = $f;
                    }
                }
            } else {
                // All files: root-level + every subfolder
                foreach ($root->files() as $f) {
                    $allFiles[] = $f;
                }
                foreach ($root->index() as $child) {
                    foreach ($child->files() as $f) {
                        $allFiles[] = $f;
                    }
                }
            }

            // Type filter
            if ($type) {
                $allFiles = array_values(array_filter($allFiles, fn ($f) => $f->type() === $type));
            }

            // Search filter (filename, title, alt, description, copyright, photographer)
            if ($query !== '') {
                $q        = mb_strtolower($query);
                $allFiles = array_values(array_filter($allFiles, function ($f) use ($q) {
                    return str_contains(mb_strtolower($f->filename()), $q)
                        || str_contains(mb_strtolower((string) $f->content()->get('title')->value()), $q)
                        || str_contains(mb_strtolower((string) $f->content()->get('alt')->value()), $q)
                        || str_contains(mb_strtolower((string) $f->content()->get('description')->value()), $q)
                        || str_contains(mb_strtolower((string) $f->content()->get('copyright')->value()), $q)
                        || str_contains(mb_strtolower((string) $f->content()->get('photographer')->value()), $q);
                }));
            }

            // Sort by filename
            usort($allFiles, fn ($a, $b) => strcmp($a->filename(), $b->filename()));

            $total  = count($allFiles);
            $offset = ($page - 1) * $limit;
            $slice  = array_slice($allFiles, $offset, $limit);

            $data = [];
            foreach ($slice as $file) {
                $data[] = mediaHubSerializeFile($file);
            }

            return [
                'data'       => $data,
                'pagination' => [
                    'total' => $total,
                    'page'  => $page,
                    'limit' => $limit,
                ],
            ];
        },
    ],

    // ── 2. Single file detail ───────────────────────────────────────────────
    [
        'pattern' => 'media-hub/files/(:any)',
        'method'  => 'GET',
        'auth'    => true,
        'action'  => function (string $encodedId) {
            $kirby = App::instance();
            $id    = str_replace('+', '/', rawurldecode($encodedId));
            $file  = $kirby->file($id);

            if (!$file) {
                return ['status' => 'error', 'message' => 'File not found'];
            }

            return mediaHubSerializeFile($file, true);
        },
    ],

    // ── 3. Update file metadata ─────────────────────────────────────────────
    [
        'pattern' => 'media-hub/files/(:any)/update',
        'method'  => 'PATCH',
        'auth'    => true,
        'action'  => function (string $encodedId) {
            $kirby = App::instance();
            $id    = str_replace('+', '/', rawurldecode($encodedId));
            $file  = $kirby->file($id);

            if (!$file) {
                return ['status' => 'error', 'message' => 'File not found'];
            }

            $body    = $kirby->request()->body()->toArray();
            $allowed = ['title', 'alt', 'description', 'copyright', 'photographer'];
            $content = [];

            foreach ($allowed as $field) {
                if (array_key_exists($field, $body)) {
                    $content[$field] = $body[$field];
                }
            }

            $file->update($content);

            return ['status' => 'ok'];
        },
    ],

    // ── 4. Delete a file ────────────────────────────────────────────────────
    [
        'pattern' => 'media-hub/files/(:any)/delete',
        'method'  => 'DELETE',
        'auth'    => true,
        'action'  => function (string $encodedId) {
            $kirby = App::instance();
            $id    = str_replace('+', '/', rawurldecode($encodedId));
            $file  = $kirby->file($id);

            if (!$file) {
                return ['status' => 'error', 'message' => 'File not found'];
            }

            $file->delete();

            return ['status' => 'ok'];
        },
    ],

    // ── 5. List folders ─────────────────────────────────────────────────────
    [
        'pattern' => 'media-hub/folders',
        'method'  => 'GET',
        'auth'    => true,
        'action'  => function () {
            $kirby = App::instance();
            $slug  = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $root  = $kirby->page($slug);

            if (!$root) {
                return ['data' => []];
            }

            $data = [];
            foreach ($root->children()->listed() as $p) {
                $data[] = [
                    'id'        => $p->id(),
                    'slug'      => $p->slug(),
                    'title'     => $p->title()->value(),
                    'fileCount' => $p->files()->count(),
                ];
            }

            return ['data' => $data];
        },
    ],

    // ── 6. Create a folder ──────────────────────────────────────────────────
    [
        'pattern' => 'media-hub/folders',
        'method'  => 'POST',
        'auth'    => true,
        'action'  => function () {
            $kirby = App::instance();
            $slug  = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $root  = $kirby->page($slug);
            $title = trim((string) $kirby->request()->get('title', ''));

            if (!$root) {
                return ['status' => 'error', 'message' => 'Media Hub page not found'];
            }

            if ($title === '') {
                return ['status' => 'error', 'message' => 'Folder name is required'];
            }

            $folderSlug = Str::slug($title);

            if ($root->findPageOrDraft($folderSlug)) {
                return ['status' => 'error', 'message' => 'A folder with that name already exists'];
            }

            try {
                // impersonate('kirby') bypasses blueprint permission checks
                // (create: false in the media-hub blueprint prevents regular users
                // from adding subpages, but our plugin must be able to do so)
                $folder = $kirby->impersonate('kirby', function () use ($kirby, $root, $folderSlug, $title, $slug) {
                    $f = $root->createChild([
                        'template' => 'media-hub-folder',
                        'slug'     => $folderSlug,
                        'content'  => ['title' => $title],
                    ]);
                    $f->changeStatus('listed');
                    return $kirby->page($slug . '/' . $folderSlug);
                });
            } catch (\Throwable $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }

            if (!$folder) {
                return ['status' => 'error', 'message' => 'Folder could not be created'];
            }

            return [
                'status' => 'ok',
                'data'   => [
                    'id'        => $folder->id(),
                    'slug'      => $folder->slug(),
                    'title'     => $folder->title()->value(),
                    'fileCount' => 0,
                ],
            ];
        },
    ],

    // ── 7. Delete a folder ──────────────────────────────────────────────────
    [
        'pattern' => 'media-hub/folders/(:any)',
        'method'  => 'DELETE',
        'auth'    => true,
        'action'  => function (string $folderSlug) {
            $kirby  = App::instance();
            $slug   = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $folder = $kirby->page($slug . '/' . $folderSlug);

            if (!$folder) {
                return ['status' => 'error', 'message' => 'Folder not found'];
            }

            try {
                $kirby->impersonate('kirby', function () use ($folder) {
                    $folder->delete(true);
                });
            } catch (\Throwable $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }

            return ['status' => 'ok'];
        },
    ],

    // ── 8. Usage tracking — all pages referencing a file UUID ──────────────
    [
        'pattern' => 'media-hub/usage/(:any)',
        'method'  => 'GET',
        'auth'    => true,
        'action'  => function (string $uuid) {
            $kirby  = App::instance();
            $needle = 'file://' . rawurldecode($uuid);
            $usages = [];
            $seen   = [];

            foreach ($kirby->site()->index() as $p) {
                if (isset($seen[$p->id()])) {
                    continue;
                }
                foreach ($p->content()->fields() as $fieldKey => $fieldObj) {
                    if (str_contains((string) $fieldObj->value(), $needle)) {
                        $usages[]       = [
                            'pageId' => $p->id(),
                            'title'  => $p->title()->value(),
                            'url'    => $p->panel()->url(true),
                            'field'  => $fieldKey,
                        ];
                        $seen[$p->id()] = true;
                        break;
                    }
                }
            }

            return [
                'uuid'   => $uuid,
                'count'  => count($usages),
                'usages' => $usages,
            ];
        },
    ],

    // ── 9. Dashboard stats ──────────────────────────────────────────────────
    [
        'pattern' => 'media-hub/stats',
        'method'  => 'GET',
        'auth'    => true,
        'action'  => function () {
            $kirby = App::instance();
            $slug  = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $root  = $kirby->page($slug);

            if (!$root) {
                return ['total' => 0, 'unused' => 0, 'folders' => 0, 'byType' => [], 'recent' => [], 'largest' => []];
            }

            // Gather all files from the media hub tree
            $allFiles = [];
            foreach ($root->files() as $f) {
                $allFiles[] = $f;
            }
            foreach ($root->index() as $child) {
                foreach ($child->files() as $f) {
                    $allFiles[] = $f;
                }
            }

            $total   = count($allFiles);
            $folders = $root->children()->listed()->count();
            $byType  = [];
            $sizes   = [];

            foreach ($allFiles as $file) {
                $t          = $file->type();
                $byType[$t] = ($byType[$t] ?? 0) + 1;
                $sizes[]    = [
                    'filename' => $file->filename(),
                    'size'     => $file->size(),
                    'niceSize' => $file->niceSize(),
                    'id'       => $file->id(),
                    'type'     => $file->type(),
                ];
            }

            usort($sizes, fn ($a, $b) => $b['size'] <=> $a['size']);
            $largest = array_slice($sizes, 0, 5);

            // 5 most recently modified
            usort($allFiles, fn ($a, $b) => $b->modified() <=> $a->modified());
            $recent = [];
            foreach (array_slice($allFiles, 0, 5) as $f) {
                $recent[] = [
                    'filename' => $f->filename(),
                    'modified' => $f->modified('Y-m-d'),
                    'id'       => $f->id(),
                    'type'     => $f->type(),
                ];
            }

            // Unused count — files not referenced outside the media-hub
            $unusedCount = 0;
            foreach ($allFiles as $file) {
                $needle = 'file://' . $file->uuid()->id();
                $used   = false;
                foreach ($kirby->site()->index() as $p) {
                    if (str_starts_with($p->id(), $slug)) {
                        continue;
                    }
                    foreach ($p->content()->fields() as $fieldObj) {
                        if (str_contains((string) $fieldObj->value(), $needle)) {
                            $used = true;
                            break 2;
                        }
                    }
                }
                if (!$used) {
                    $unusedCount++;
                }
            }

            return [
                'total'   => $total,
                'unused'  => $unusedCount,
                'folders' => $folders,
                'byType'  => $byType,
                'recent'  => $recent,
                'largest' => $largest,
            ];
        },
    ],

    // ── 10. Picker — search all media-hub files for the picker field ────────
    [
        'pattern' => 'media-hub/picker',
        'method'  => 'GET',
        'auth'    => true,
        'action'  => function () {
            $kirby  = App::instance();
            $slug   = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $root   = $kirby->page($slug);
            $query  = trim((string) $kirby->request()->get('q', ''));
            $type   = (string) $kirby->request()->get('type', '');
            $folder = trim((string) $kirby->request()->get('folder', ''));
            $page   = max(1, (int) $kirby->request()->get('page', 1));
            $limit  = 30;

            if (!$root) {
                return ['data' => [], 'folders' => [], 'pagination' => ['total' => 0, 'page' => 1, 'limit' => $limit]];
            }

            // Build folders list for the picker UI
            $folderList = [];
            foreach ($root->children()->listed() as $p) {
                $folderList[] = [
                    'slug'  => $p->slug(),
                    'title' => $p->title()->value(),
                ];
            }

            // Collect files — specific folder or all
            $allFiles = [];
            if ($folder) {
                $folderPage = $kirby->page($slug . '/' . $folder);
                if ($folderPage) {
                    foreach ($folderPage->files() as $f) {
                        $allFiles[] = $f;
                    }
                }
            } else {
                foreach ($root->files() as $f) {
                    $allFiles[] = $f;
                }
                foreach ($root->index() as $child) {
                    foreach ($child->files() as $f) {
                        $allFiles[] = $f;
                    }
                }
            }

            // Type filter
            if ($type) {
                $allFiles = array_values(array_filter($allFiles, fn ($f) => $f->type() === $type));
            }

            // Full-text search: filename, title, alt, description, copyright, photographer
            if ($query !== '') {
                $q        = mb_strtolower($query);
                $allFiles = array_values(array_filter($allFiles, fn ($f) =>
                    str_contains(mb_strtolower($f->filename()), $q) ||
                    str_contains(mb_strtolower((string) $f->content()->get('title')->value()), $q) ||
                    str_contains(mb_strtolower((string) $f->content()->get('alt')->value()), $q) ||
                    str_contains(mb_strtolower((string) $f->content()->get('description')->value()), $q) ||
                    str_contains(mb_strtolower((string) $f->content()->get('copyright')->value()), $q) ||
                    str_contains(mb_strtolower((string) $f->content()->get('photographer')->value()), $q)
                ));
            }

            $allFiles = array_values($allFiles);
            $total    = count($allFiles);
            $offset   = ($page - 1) * $limit;
            $slice    = array_slice($allFiles, $offset, $limit);

            $data = [];
            foreach ($slice as $file) {
                $thumb = null;
                if ($file->type() === 'image') {
                    try {
                        $thumb = $file->thumb(['width' => 200])->url();
                    } catch (\Throwable $e) {
                        $thumb = $file->url();
                    }
                }
                $data[] = [
                    'id'       => $file->id(),
                    'uuid'     => 'file://' . $file->uuid()->id(),
                    'filename' => $file->filename(),
                    'url'      => $file->url(),
                    'thumb'    => $thumb,
                    'type'     => $file->type(),
                    'title'    => (string) $file->content()->get('title')->or($file->filename())->value(),
                ];
            }

            return [
                'data'       => $data,
                'folders'    => $folderList,
                'pagination' => [
                    'total' => $total,
                    'page'  => $page,
                    'limit' => $limit,
                ],
            ];
        },
    ],

];
