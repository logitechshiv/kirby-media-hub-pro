<?php

namespace Kirbycode\MediaHub\Api;

use Kirby\Cms\App;
use Kirby\Cms\File;
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
            'tags'         => $tagsRaw ? array_values(array_filter(array_map('trim', explode(',', $tagsRaw)))) : [],
            'uploadedby'   => (string) $file->content()->get('uploadedby')->value(),
            'uploaddate'   => (string) $file->content()->get('uploaddate')->value(),
        ];

        if ($detailed) {
            $data['uploaddate'] = (string) $file->content()->get('uploaddate')->value();
        }

        return $data;
    }
}
