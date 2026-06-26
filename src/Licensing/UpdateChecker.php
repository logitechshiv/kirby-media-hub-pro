<?php

namespace Kirbycode\MediaHub\Licensing;

use Kirby\Cms\App;

class UpdateChecker
{
    const CURRENT_VERSION = '1.0.4';
    const PACKAGIST_URL   = 'https://repo.packagist.org/p2/kirbycode/media-hub-pro.json';
    const CACHE_KEY       = 'update-check';
    const CACHE_TTL       = 1440; // 1 day in minutes

    /**
     * Returns the latest stable version string if newer than CURRENT_VERSION, null otherwise.
     * Fails silently — never throws, never blocks the Panel.
     */
    public static function latestVersion(): ?string
    {
        try {
            $cache  = App::instance()->cache(LicenseManager::CACHE_DRIVER);
            $cached = $cache->get(self::CACHE_KEY);

            // null = not cached yet; empty string = cached "no update"
            if ($cached !== null) {
                return $cached !== '' ? $cached : null;
            }

            $ctx  = stream_context_create(['http' => [
                'method'        => 'GET',
                'header'        => "Accept: application/json\r\n",
                'timeout'       => 3,
                'ignore_errors' => true,
            ]]);
            $body   = @file_get_contents(self::PACKAGIST_URL, false, $ctx);
            $latest = null;

            if ($body) {
                $data     = json_decode($body, true);
                $versions = array_keys($data['packages']['kirbycode/media-hub-pro'] ?? []);
                $stable   = array_filter($versions, fn($v) => preg_match('/^\d+\.\d+\.\d+$/', ltrim($v, 'v')));
                if ($stable) {
                    usort($stable, 'version_compare');
                    $top    = ltrim((string) end($stable), 'v');
                    $latest = version_compare($top, self::CURRENT_VERSION, '>') ? $top : null;
                }
            }

            $cache->set(self::CACHE_KEY, $latest ?? '', self::CACHE_TTL);
            return $latest;
        } catch (\Throwable $e) {
            return null;
        }
    }

    public static function hasUpdate(): bool
    {
        return self::latestVersion() !== null;
    }
}
