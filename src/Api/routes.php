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

    $tagsRaw = (string) $file->content()->get('tags')->value();
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
        'tags'         => $tagsRaw ? array_values(array_filter(array_map('trim', explode(',', $tagsRaw)))) : [],
        'uploadedby'   => (string) $file->content()->get('uploadedby')->value(),
        'uploaddate'   => (string) $file->content()->get('uploaddate')->value(),
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

            $tag        = trim((string) $request->get('tag', ''));
            $dateFrom   = trim((string) $request->get('dateFrom', ''));
            $dateTo     = trim((string) $request->get('dateTo', ''));
            $uploadedBy = trim((string) $request->get('uploadedBy', ''));
            $minSize    = (int) $request->get('minSize', 0);
            $maxSize    = (int) $request->get('maxSize', 0);

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

            // Tag filter
            if ($tag !== '') {
                $tagLower = mb_strtolower($tag);
                $allFiles = array_values(array_filter($allFiles, function ($f) use ($tagLower) {
                    $raw = (string) $f->content()->get('tags')->value();
                    if ($raw === '') return false;
                    $fileTags = array_map('mb_strtolower', array_map('trim', explode(',', $raw)));
                    return in_array($tagLower, $fileTags, true);
                }));
            }

            // Date range filter (uses uploaddate field, falls back to file modified date)
            if ($dateFrom !== '' || $dateTo !== '') {
                $allFiles = array_values(array_filter($allFiles, function ($f) use ($dateFrom, $dateTo) {
                    $d = (string) $f->content()->get('uploaddate')->value() ?: $f->modified('Y-m-d');
                    if ($dateFrom !== '' && $d < $dateFrom) return false;
                    if ($dateTo   !== '' && $d > $dateTo)   return false;
                    return true;
                }));
            }

            // Uploaded-by filter
            if ($uploadedBy !== '') {
                $allFiles = array_values(array_filter($allFiles, function ($f) use ($uploadedBy) {
                    return (string) $f->content()->get('uploadedby')->value() === $uploadedBy;
                }));
            }

            // File size filter (minSize / maxSize in KB)
            if ($minSize > 0 || $maxSize > 0) {
                $allFiles = array_values(array_filter($allFiles, function ($f) use ($minSize, $maxSize) {
                    $kb = $f->size() / 1024;
                    if ($minSize > 0 && $kb < $minSize) return false;
                    if ($maxSize > 0 && $kb > $maxSize) return false;
                    return true;
                }));
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
            $allowed = ['title', 'alt', 'description', 'copyright', 'photographer', 'tags'];
            $content = [];

            foreach ($allowed as $field) {
                if (array_key_exists($field, $body)) {
                    $content[$field] = $body[$field];
                }
            }

            try {
                $kirby->impersonate('kirby', fn() => $file->update($content));
            } catch (\Throwable $e) {
                return \Kirby\Http\Response::json(['status' => 'error', 'message' => $e->getMessage()], 400);
            }

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

    // ── 5. Optimize a file (convert JPEG/PNG→WebP or compress existing WebP) ──
    [
        'pattern' => 'media-hub/files/(:any)/optimize',
        'method'  => 'POST',
        'auth'    => true,
        'action'  => function (string $encodedId) {
            require_once dirname(__DIR__) . '/Optimization/MediaOptimizer.php';

            $kirby = App::instance();
            $id    = str_replace('+', '/', rawurldecode($encodedId));
            $file  = $kirby->file($id);

            if (!$file) {
                return ['status' => 'error', 'message' => 'File not found'];
            }

            $result = \Kirbycode\MediaHub\MediaOptimizer::optimize($file);

            return ['status' => 'ok', 'data' => $result];
        },
    ],

    // ── 6. List folders ─────────────────────────────────────────────────────
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
                $children = [];
                foreach ($p->children()->listed() as $c) {
                    $children[] = [
                        'id'        => $c->id(),
                        'slug'      => $c->slug(),
                        'path'      => $p->slug() . '/' . $c->slug(),
                        'title'     => $c->title()->value(),
                        'fileCount' => $c->files()->count(),
                        'children'  => [],
                    ];
                }
                $data[] = [
                    'id'        => $p->id(),
                    'slug'      => $p->slug(),
                    'path'      => $p->slug(),
                    'title'     => $p->title()->value(),
                    'fileCount' => $p->files()->count(),
                    'children'  => $children,
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
            $kirby      = App::instance();
            $slug       = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $root       = $kirby->page($slug);
            $title      = trim((string) $kirby->request()->get('title', ''));
            $parentPath = trim((string) $kirby->request()->get('parent', ''));

            if (!$root) {
                return ['status' => 'error', 'message' => 'Media Hub page not found'];
            }

            if ($title === '') {
                return ['status' => 'error', 'message' => 'Folder name is required'];
            }

            $parent = $parentPath !== ''
                ? $kirby->page($slug . '/' . $parentPath)
                : $root;

            if (!$parent) {
                return ['status' => 'error', 'message' => 'Parent folder not found'];
            }

            $folderSlug = Str::slug($title);

            if ($parent->findPageOrDraft($folderSlug)) {
                return ['status' => 'error', 'message' => 'A folder with that name already exists'];
            }

            try {
                $folder = $kirby->impersonate('kirby', function () use ($kirby, $parent, $folderSlug, $title, $slug, $parentPath) {
                    $f = $parent->createChild([
                        'template' => 'media-hub-folder',
                        'slug'     => $folderSlug,
                        'content'  => ['title' => $title],
                    ]);
                    $f->changeStatus('listed');
                    $fullPath = $parentPath !== '' ? $parentPath . '/' . $folderSlug : $folderSlug;
                    return $kirby->page($slug . '/' . $fullPath);
                });
            } catch (\Throwable $e) {
                return ['status' => 'error', 'message' => $e->getMessage()];
            }

            if (!$folder) {
                return ['status' => 'error', 'message' => 'Folder could not be created'];
            }

            $relPath = $parentPath !== '' ? $parentPath . '/' . $folderSlug : $folderSlug;

            return [
                'status' => 'ok',
                'data'   => [
                    'id'        => $folder->id(),
                    'slug'      => $folder->slug(),
                    'path'      => $relPath,
                    'title'     => $folder->title()->value(),
                    'fileCount' => 0,
                    'children'  => [],
                ],
            ];
        },
    ],

    // ── 7. Delete a folder ──────────────────────────────────────────────────
    [
        'pattern' => 'media-hub/folders/(:any)',
        'method'  => 'DELETE',
        'auth'    => true,
        'action'  => function (string $encodedPath) {
            $kirby  = App::instance();
            $slug   = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $path   = str_replace('+', '/', rawurldecode($encodedPath));
            $folder = $kirby->page($slug . '/' . $path);

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
            $folders = $root->index()->filterBy('intendedTemplate', 'media-hub-folder')->count();
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
            $tag    = trim((string) $kirby->request()->get('tag', ''));
            $page   = max(1, (int) $kirby->request()->get('page', 1));
            $limit  = 30;

            if (!$root) {
                return ['data' => [], 'folders' => [], 'pagination' => ['total' => 0, 'page' => 1, 'limit' => $limit]];
            }

            // Build folder tree for the picker sidebar
            $folderTree = [];
            foreach ($root->children()->listed() as $p) {
                $children = [];
                foreach ($p->children()->listed() as $c) {
                    $children[] = [
                        'path'  => $p->slug() . '/' . $c->slug(),
                        'title' => $c->title()->value(),
                    ];
                }
                $folderTree[] = [
                    'path'     => $p->slug(),
                    'title'    => $p->title()->value(),
                    'children' => $children,
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

            // Build tag list from type-filtered files so sidebar only shows tags
            // that actually have matching files in the current context
            $tagCounts = [];
            foreach ($allFiles as $f) {
                $raw = (string) $f->content()->get('tags')->value();
                if ($raw === '') continue;
                foreach (array_map('trim', explode(',', $raw)) as $t) {
                    if ($t !== '') $tagCounts[$t] = ($tagCounts[$t] ?? 0) + 1;
                }
            }
            arsort($tagCounts);
            $tagList = [];
            foreach ($tagCounts as $t => $count) {
                $tagList[] = ['tag' => $t, 'count' => $count];
            }

            // Tag filter
            if ($tag !== '') {
                $allFiles = array_values(array_filter($allFiles, function ($f) use ($tag) {
                    $raw  = (string) $f->content()->get('tags')->value();
                    $tags = array_filter(array_map('trim', explode(',', $raw)));
                    return in_array($tag, $tags, true);
                }));
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
                'data'        => $data,
                'folderTree'  => $folderTree,
                'tags'        => $tagList,
                'pagination'  => [
                    'total' => $total,
                    'page'  => $page,
                    'limit' => $limit,
                ],
            ];
        },
    ],

    // ── 11. Unique uploaders (for Smart Filter dropdown) ───────────────────
    [
        'pattern' => 'media-hub/uploaders',
        'method'  => 'GET',
        'auth'    => true,
        'action'  => function () {
            $kirby = App::instance();
            $slug  = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $root  = $kirby->page($slug);

            if (!$root) return ['data' => []];

            $allFiles = [];
            foreach ($root->files() as $f) $allFiles[] = $f;
            foreach ($root->index() as $child) {
                foreach ($child->files() as $f) $allFiles[] = $f;
            }

            $uploaders = [];
            foreach ($allFiles as $f) {
                $u = (string) $f->content()->get('uploadedby')->value();
                if ($u && !in_array($u, $uploaders, true)) {
                    $uploaders[] = $u;
                }
            }
            sort($uploaders);

            return ['data' => $uploaders];
        },
    ],

    // ── 12. All unique tags across the media hub ────────────────────────────
    [
        'pattern' => 'media-hub/tags',
        'method'  => 'GET',
        'auth'    => true,
        'action'  => function () {
            $kirby = App::instance();
            $slug  = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $root  = $kirby->page($slug);

            if (!$root) {
                return ['data' => []];
            }

            $allFiles = [];
            foreach ($root->files() as $f) {
                $allFiles[] = $f;
            }
            foreach ($root->index() as $child) {
                foreach ($child->files() as $f) {
                    $allFiles[] = $f;
                }
            }

            $tagCounts = [];
            foreach ($allFiles as $f) {
                $raw = (string) $f->content()->get('tags')->value();
                if ($raw === '') continue;
                foreach (array_map('trim', explode(',', $raw)) as $tag) {
                    if ($tag === '') continue;
                    $tagCounts[$tag] = ($tagCounts[$tag] ?? 0) + 1;
                }
            }

            arsort($tagCounts);

            $data = [];
            foreach ($tagCounts as $tag => $count) {
                $data[] = ['tag' => $tag, 'count' => $count];
            }

            return ['data' => $data];
        },
    ],

    // ── 13. Delete a tag from all files ────────────────────────────────────
    [
        'pattern' => 'media-hub/tags/(:any)',
        'method'  => 'DELETE',
        'auth'    => true,
        'action'  => function (string $encodedTag) {
            $kirby = App::instance();
            $slug  = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $root  = $kirby->page($slug);
            $tag   = trim(rawurldecode($encodedTag));

            if (!$root || $tag === '') {
                return ['status' => 'error', 'message' => 'Invalid tag'];
            }

            $allFiles = [];
            foreach ($root->files() as $f) {
                $allFiles[] = $f;
            }
            foreach ($root->index() as $child) {
                foreach ($child->files() as $f) {
                    $allFiles[] = $f;
                }
            }

            $updated = 0;
            $kirby->impersonate('kirby', function () use ($allFiles, $tag, &$updated) {
                foreach ($allFiles as $file) {
                    $raw      = (string) $file->content()->get('tags')->value();
                    $existing = array_filter(array_map('trim', explode(',', $raw)));
                    if (!in_array($tag, $existing, true)) continue;
                    $newTags  = array_values(array_filter($existing, fn($t) => $t !== $tag));
                    $file->update(['tags' => implode(', ', $newTags)]);
                    $updated++;
                }
            });

            return ['status' => 'ok', 'data' => ['updated' => $updated, 'tag' => $tag]];
        },
    ],

    // ── 14. Bulk delete ─────────────────────────────────────────────────────
    [
        'pattern' => 'media-hub/bulk/delete',
        'method'  => 'POST',
        'auth'    => true,
        'action'  => function () {
            $kirby   = App::instance();
            $ids     = (array) $kirby->request()->get('ids', []);
            $deleted = 0;
            $errors  = [];

            foreach ($ids as $encodedId) {
                $id   = str_replace('+', '/', rawurldecode((string) $encodedId));
                $file = $kirby->file($id);
                if (!$file) { $errors[] = basename($id) . ' not found'; continue; }
                try {
                    $file->delete();
                    $deleted++;
                } catch (\Throwable $e) {
                    $errors[] = basename($id) . ': ' . $e->getMessage();
                }
            }

            return ['status' => 'ok', 'deleted' => $deleted, 'errors' => $errors];
        },
    ],

    // ── 14. Bulk move ────────────────────────────────────────────────────────
    [
        'pattern' => 'media-hub/bulk/move',
        'method'  => 'POST',
        'auth'    => true,
        'action'  => function () {
            $kirby        = App::instance();
            $slug         = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $ids          = (array) $kirby->request()->get('ids', []);
            $targetFolder = trim((string) $kirby->request()->get('targetFolder', ''));

            $target = $targetFolder !== ''
                ? $kirby->page($slug . '/' . $targetFolder)
                : $kirby->page($slug);

            if (!$target) {
                return ['status' => 'error', 'message' => 'Target folder not found'];
            }

            $moved  = 0;
            $errors = [];

            foreach ($ids as $encodedId) {
                $id   = str_replace('+', '/', rawurldecode((string) $encodedId));
                $file = $kirby->file($id);
                if (!$file) { $errors[] = basename($id) . ' not found'; continue; }
                if ($file->parent()->id() === $target->id()) { $moved++; continue; }

                try {
                    $kirby->impersonate('kirby', function () use ($file, $target) {
                        $content = $file->content()->toArray();
                        $oldUuid = $file->uuid()->id();

                        $newFile = $target->createFile([
                            'source'   => $file->root(),
                            'filename' => $file->filename(),
                            'template' => 'media-hub-asset',
                            'content'  => $content,
                        ]);
                        // Re-stamp the old UUID so existing references remain valid
                        $newFile->update(['uuid' => $oldUuid]);
                        $file->delete();
                    });
                    $moved++;
                } catch (\Throwable $e) {
                    $errors[] = $file->filename() . ': ' . $e->getMessage();
                }
            }

            return ['status' => 'ok', 'moved' => $moved, 'errors' => $errors];
        },
    ],

    // ── 15. Bulk rename ──────────────────────────────────────────────────────
    [
        'pattern' => 'media-hub/bulk/rename',
        'method'  => 'POST',
        'auth'    => true,
        'action'  => function () {
            $kirby   = App::instance();
            $ids     = (array) $kirby->request()->get('ids', []);
            $pattern = trim((string) $kirby->request()->get('pattern', ''));
            $startAt = max(1, (int) $kirby->request()->get('startAt', 1));

            if ($pattern === '') {
                return ['status' => 'error', 'message' => 'Pattern is required'];
            }

            $renamed = 0;
            $errors  = [];
            $i       = $startAt;

            foreach ($ids as $encodedId) {
                $id   = str_replace('+', '/', rawurldecode((string) $encodedId));
                $file = $kirby->file($id);
                if (!$file) { $errors[] = basename($id) . ' not found'; $i++; continue; }

                $newSlug = Str::slug(str_replace('{n}', $i, $pattern));
                if ($newSlug === '') { $errors[] = $file->filename() . ': empty slug'; $i++; continue; }

                try {
                    $kirby->impersonate('kirby', function () use ($file, $newSlug) {
                        $file->changeName($newSlug);
                    });
                    $renamed++;
                } catch (\Throwable $e) {
                    $errors[] = $file->filename() . ': ' . $e->getMessage();
                }
                $i++;
            }

            return ['status' => 'ok', 'renamed' => $renamed, 'errors' => $errors];
        },
    ],

    // ── 16. Bulk tag ─────────────────────────────────────────────────────────
    [
        'pattern' => 'media-hub/bulk/tag',
        'method'  => 'POST',
        'auth'    => true,
        'action'  => function () {
            $kirby  = App::instance();
            $ids    = (array) $kirby->request()->get('ids', []);
            $tags   = array_values(array_filter(array_map('trim', (array) $kirby->request()->get('tags', []))));
            $action = trim((string) $kirby->request()->get('action', 'add')); // add | remove | set

            $updated = 0;
            $errors  = [];

            foreach ($ids as $encodedId) {
                $id   = str_replace('+', '/', rawurldecode((string) $encodedId));
                $file = $kirby->file($id);
                if (!$file) { $errors[] = basename($id) . ' not found'; continue; }

                $raw      = (string) $file->content()->get('tags')->value();
                $existing = $raw !== '' ? array_map('trim', explode(',', $raw)) : [];

                if ($action === 'set') {
                    $newTags = $tags;
                } elseif ($action === 'remove') {
                    $newTags = array_values(array_diff($existing, $tags));
                } else {
                    $newTags = array_values(array_unique(array_merge($existing, $tags)));
                }

                try {
                    $kirby->impersonate('kirby', function () use ($file, $newTags) {
                        $file->update(['tags' => implode(', ', $newTags)]);
                    });
                    $updated++;
                } catch (\Throwable $e) {
                    $errors[] = $file->filename() . ': ' . $e->getMessage();
                }
            }

            return ['status' => 'ok', 'updated' => $updated, 'errors' => $errors];
        },
    ],

    // ── 17. Duplicate detection ──────────────────────────────────────────────
    [
        'pattern' => 'media-hub/duplicates',
        'method'  => 'GET',
        'auth'    => true,
        'action'  => function () {
            $kirby = App::instance();
            $slug  = $kirby->option('kirbycode.media-hub.root-slug', 'media-hub');
            $root  = $kirby->page($slug);

            if (!$root) {
                return ['exact' => [], 'similar' => [], 'stats' => ['exactGroups' => 0, 'exactWasted' => 0, 'similarGroups' => 0]];
            }

            // Gather all files once
            $allFiles = [];
            foreach ($root->files() as $f)  $allFiles[] = $f;
            foreach ($root->index() as $child) {
                foreach ($child->files() as $f) $allFiles[] = $f;
            }

            // Compute MD5 hashes once (reused for both exact + similar checks)
            $hashById = [];
            foreach ($allFiles as $file) {
                $hashById[$file->id()] = md5_file($file->root());
            }

            // ── Exact duplicates (identical content by hash) ─────────────────
            $hashMap = [];
            foreach ($allFiles as $file) {
                $h = $hashById[$file->id()];
                $hashMap[$h][] = $file;
            }

            $exactGroups    = [];
            $exactHashSet   = [];
            foreach ($hashMap as $hash => $group) {
                if (count($group) < 2) continue;
                $exactHashSet[] = $hash;
                usort($group, fn ($a, $b) => $a->modified() <=> $b->modified());
                $exactGroups[] = [
                    'hash'  => $hash,
                    'files' => array_map(fn ($f) => mediaHubSerializeFile($f), $group),
                ];
            }

            // ── Similar names (same base after stripping version suffixes) ────
            $getBase = function (string $filename): string {
                $name = mb_strtolower(pathinfo($filename, PATHINFO_FILENAME));
                $patterns = [
                    '/-+final$/i', '/-+copy$/i', '/-+v\d+$/i', '/-+\d+$/',
                    '/_+\d+$/',    '/\(\d+\)$/', '/-+new$/i',   '/-+old$/i',
                    '/-+backup$/i','/-+revised$/i','/-+updated$/i','/-+original$/i',
                ];
                $prev = null;
                while ($prev !== $name) {
                    $prev = $name;
                    foreach ($patterns as $p) {
                        $name = preg_replace($p, '', $name);
                    }
                }
                return trim($name, '-_ ');
            };

            $nameMap = [];
            foreach ($allFiles as $file) {
                $base = $getBase($file->filename());
                if (mb_strlen($base) < 3) continue; // skip very short stems
                $nameMap[$base][] = $file;
            }

            $similarGroups = [];
            foreach ($nameMap as $base => $group) {
                if (count($group) < 2) continue;
                // If the whole group shares one hash, it's already in exactGroups
                $groupHashes = array_unique(array_map(fn ($f) => $hashById[$f->id()], $group));
                if (count($groupHashes) === 1 && in_array(reset($groupHashes), $exactHashSet, true)) continue;
                usort($group, fn ($a, $b) => strcmp($a->filename(), $b->filename()));
                $similarGroups[] = [
                    'baseName' => $base,
                    'files'    => array_map(fn ($f) => mediaHubSerializeFile($f), $group),
                ];
            }

            $exactWasted = (int) array_sum(array_map(fn ($g) => count($g['files']) - 1, $exactGroups));

            return [
                'exact'   => $exactGroups,
                'similar' => $similarGroups,
                'stats'   => [
                    'exactGroups'   => count($exactGroups),
                    'exactWasted'   => $exactWasted,
                    'similarGroups' => count($similarGroups),
                ],
            ];
        },
    ],

];
