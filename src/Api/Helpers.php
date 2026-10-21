<?php

namespace Kirbycode\MediaHub\Api;

use Kirby\Cms\App;
use Kirby\Cms\File;
use Kirby\Cms\ModelWithContent;
use Kirby\Cms\Page;
use Kirby\Http\Response;

class Helpers
{
    public static function requirePro(): ?Response
    {
        if (!\Kirbycode\MediaHub\Licensing\LicenseManager::isPro()) {
            return Response::json([
                'status'  => 'error',
                'code'    => 402,
                'message' => 'This feature requires Media Hub Pro. Get your license at kirbycode.com',
            ], 402);
        }
        return null;
    }

    public static function requireAdmin(): ?Response
    {
        $user = App::instance()->user();
        if (!$user || $user->role()->id() !== 'admin') {
            return Response::json([
                'status'  => 'error',
                'message' => 'Admin access required',
            ], 403);
        }
        return null;
    }

    /**
     * Whether the CURRENT (real) user may perform $action on $model, honouring
     * both the role permissions and the model's blueprint options.
     *
     * Always call this before any impersonate('kirby') block — the almighty
     * kirby user skips all of these checks.
     */
    public static function can(ModelWithContent $model, string $action): bool
    {
        try {
            return $model->permissions()->can($action) === true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Whether the current user may delete $file from the Media Hub.
     *
     * Kirby's own file permissions (role + file blueprint) always apply. On top
     * of that, a site may restrict deletion to named roles via the option
     * `kirbycode.media-hub.delete-roles`, e.g. ['admin', 'manager']. Left unset
     * (the default) the Kirby permissions alone decide.
     */
    public static function canDeleteFile(File $file): bool
    {
        if (!self::can($file, 'delete')) {
            return false;
        }

        return self::roleAllowed('delete-roles');
    }

    /**
     * Role-level counterpart of canDeleteFile(), for Panel view props and the
     * file list: whether this user may delete files at all. Per-file blueprint
     * rules are still enforced on every actual delete.
     */
    public static function canDeleteFiles(): bool
    {
        $user = App::instance()->user();

        if ($user === null || $user->role()->permissions()->for('files', 'delete') !== true) {
            return false;
        }

        return self::roleAllowed('delete-roles');
    }

    /**
     * Whether the current user may rename $file. Kirby's `changeName`
     * permission applies first; `kirbycode.media-hub.rename-roles` can then
     * narrow it to named roles, exactly like `delete-roles` above.
     */
    public static function canRenameFile(File $file): bool
    {
        if (!self::can($file, 'changeName')) {
            return false;
        }

        return self::roleAllowed('rename-roles');
    }

    /**
     * Whether the current user may rename the Media Hub folder $folder: Kirby's
     * `changeTitle` and `changeSlug` permissions, narrowed by `rename-roles`
     * (the same option as for files).
     */
    public static function canRenameFolder(Page $folder): bool
    {
        if (!self::can($folder, 'changeTitle') || !self::can($folder, 'changeSlug')) {
            return false;
        }

        return self::roleAllowed('rename-roles');
    }

    /**
     * Role-level counterpart of canRenameFolder(), for the Panel view props.
     * Per-folder blueprint rules are still enforced on every actual rename.
     */
    public static function canRenameFolders(): bool
    {
        $user = App::instance()->user();

        if (
            $user === null ||
            $user->role()->permissions()->for('pages', 'changeTitle') !== true ||
            $user->role()->permissions()->for('pages', 'changeSlug') !== true
        ) {
            return false;
        }

        return self::roleAllowed('rename-roles');
    }

    /**
     * Checks the current user's role against `kirbycode.media-hub.{$key}`,
     * e.g. 'delete-roles'. No option set → every role that Kirby already
     * permits may perform the action.
     */
    private static function roleAllowed(string $key): bool
    {
        $roles = App::instance()->option('kirbycode.media-hub.' . $key);

        if ($roles === null) {
            return true;
        }

        $user = App::instance()->user();

        return $user !== null && in_array($user->role()->id(), (array) $roles, true);
    }

    /**
     * Whether the current user may create a file with the given template in $parent.
     * Uses an unsaved model so the file blueprint's `create` option is evaluated.
     */
    public static function canCreateFile(Page $parent, string $filename, string $template = 'media-hub-asset'): bool
    {
        return self::can(File::factory([
            'filename' => $filename,
            'parent'   => $parent,
            'template' => $template,
        ]), 'create');
    }

    /**
     * Whether the current user may create a child page with the given template.
     */
    public static function canCreatePage(Page $parent, string $slug, string $template): bool
    {
        return self::can(Page::factory([
            'slug'     => $slug,
            'template' => $template,
            'model'    => $template,
            'parent'   => $parent,
            'isDraft'  => true,
        ]), 'create');
    }

    /**
     * Honour the Panel area permission (`access: media-hub: false` in a user
     * blueprint). Kirby only enforces it for Panel views, not for plugin API
     * routes, so the routes check it themselves.
     */
    public static function requireAreaAccess(): ?Response
    {
        $user = App::instance()->user();
        if ($user === null || \Kirby\Panel\Panel::hasAccess($user, 'media-hub') !== true) {
            return self::forbidden('You do not have access to the Media Hub');
        }
        return null;
    }

    /**
     * Wraps every route action with requireAreaAccess(), except the patterns
     * in $open. New routes are therefore protected by default.
     */
    public static function guardAreaAccess(array $routes, array $open): array
    {
        foreach ($routes as $i => $route) {
            if (in_array($route['method'] . ' ' . $route['pattern'], $open, true)) {
                continue;
            }
            $action = $route['action'];
            // Fully-qualified names only: Kirby runs route actions via
            // Closure::call($api), which rebinds scope — `self::` would then
            // resolve to Kirby\Api\Api. The inner action keeps the same $this.
            $routes[$i]['action'] = function (...$args) use ($action) {
                if ($guard = \Kirbycode\MediaHub\Api\Helpers::requireAreaAccess()) return $guard;
                return $action->call($this, ...$args);
            };
        }
        return $routes;
    }

    /**
     * Cleans up what Kirby leaves behind when deleting a Media Hub folder that
     * contains orphaned sidecars ("name.ext.txt" whose "name.ext" is gone).
     *
     * Kirby picks such an orphan as the folder's content file, so its delete
     * removes the orphan and leaves the real "media-hub-folder.txt". This
     * recursively deletes orphaned sidecars and leftover media-hub-folder
     * content files (incl. language variants), then removes directories that
     * became empty. Nothing else is touched. Returns the paths (relative to
     * $dir) of anything that is left.
     */
    public static function removeOrphanSidecars(string $dir): array
    {
        $left = [];
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') continue;
            $path = $dir . '/' . $name;

            if (is_dir($path)) {
                foreach (self::removeOrphanSidecars($path) as $sub) {
                    $left[] = $name . '/' . $sub;
                }
                continue;
            }

            // "photo.jpg.txt" is a sidecar of "photo.jpg"; plain "folder.txt" is not
            $media = substr($path, 0, -4);
            $isOrphanSidecar = str_ends_with($name, '.txt')
                && pathinfo(substr($name, 0, -4), PATHINFO_EXTENSION) !== ''
                && !file_exists($media);

            $isFolderContent = preg_match('/^media-hub-folder(\.[a-z]{2,3}(-[a-z]{2,4})?)?\.txt$/i', $name) === 1;

            if (!($isOrphanSidecar || $isFolderContent) || !@unlink($path)) {
                $left[] = $name;
            }
        }

        if ($left === []) {
            @rmdir($dir);
        }

        return $left;
    }

    public static function forbidden(string $message = 'You are not allowed to do this'): Response
    {
        return Response::json(['status' => 'error', 'message' => $message], 403);
    }

    public static function validatePath(string $path, string $root): bool
    {
        if ($path === '') return true;
        if (preg_match('#(^|/)\.\.(/|$)#', $path)) return false;
        if (str_contains($path, "\0")) return false;

        $page = App::instance()->page($root . '/' . $path);
        if (!$page) return false;

        return str_starts_with($page->id(), $root . '/');
    }

    /**
     * Returns true when $id is exactly the root slug or a direct descendant of it.
     * Uses an exact boundary check — str_starts_with($id, $root) alone would allow
     * a page named 'media-hub-evil' to match a root slug of 'media-hub'.
     */
    public static function isInsideRoot(string $id, string $root): bool
    {
        return $id === $root || str_starts_with($id, $root . '/');
    }

    /**
     * Load a file by ID and verify it lives within the Media Hub directory.
     * Returns 404 for both missing and out-of-scope files so the API does not
     * confirm whether a file exists outside the Media Hub.
     */
    public static function loadScopedFile(string $id, string $slug): File|Response
    {
        $file = App::instance()->file($id);
        if (!$file) {
            return Response::json(['status' => 'error', 'message' => 'File not found'], 404);
        }
        if (!self::isInsideRoot($file->parent()->id(), $slug)) {
            return Response::json(['status' => 'error', 'message' => 'File not found'], 404);
        }
        return $file;
    }

    public static function serializeFile(File $file, bool $detailed = false): array
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
            'aigenerated'  => $file->content()->get('aigenerated')->toBool(),
            'tags'         => $tagsRaw ? array_values(array_filter(array_map('trim', explode(',', $tagsRaw)))) : [],
            'uploadedby'   => (string) $file->content()->get('uploadedby')->value(),
            'uploaddate'   => (string) $file->content()->get('uploaddate')->value(),
            'focus'        => (string) $file->content()->get('focus')->value(),
        ];

        if ($detailed) {
            $data['canUpdate']  = $file->permissions()->can('update') === true;
            $data['canReplace'] = $file->permissions()->can('replace') === true;
            $data['canDelete']  = self::canDeleteFile($file);
            $data['canRename']  = self::canRenameFile($file);
            $data['width']     = $file->type() === 'image' ? $file->width()  : null;
            $data['height']    = $file->type() === 'image' ? $file->height() : null;
        }

        return $data;
    }
}
