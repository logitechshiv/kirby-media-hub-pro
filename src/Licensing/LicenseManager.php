<?php

namespace Kirbycode\MediaHub\Licensing;

use Kirby\Cms\App;

class LicenseManager
{
    const CACHE_DRIVER    = 'kirbycode-media-hub-license';
    const CACHE_KEY       = 'status';
    const CACHE_TTL_VALID = 10080; // 7 days in minutes
    const CACHE_TTL_FAIL  = 1440;  // 1 day for explicit revocation
    const GRACE_HOURS     = 48;
    const VALIDATION_URL  = 'https://kirbycode.com/api/license/validate';
    const LICENSE_OPT     = 'kirbycode.media-hub.license';
    const VALIDATION_OPT  = 'kirbycode.media-hub.validation-url';

    public static function isPro(): bool
    {
        try {
            $key = App::instance()->option(self::LICENSE_OPT, '');
            if (empty(trim($key))) return false;

            $cached = self::getCachedStatus();
            if ($cached !== null) return $cached;

            return self::validate($key);
        } catch (\Throwable $e) {
            return self::failOpen();
        }
    }

    public static function bustCache(): void
    {
        try {
            App::instance()->cache(self::CACHE_DRIVER)->remove(self::CACHE_KEY);
            self::clearGraceFile();
        } catch (\Throwable $e) {}
    }

    private static function validate(string $key): bool
    {
        try {
            $payload = json_encode([
                'key'     => $key,
                'domain'  => App::instance()->request()->url()->domain(),
                'plugin'  => 'media-hub-pro',
                'version' => UpdateChecker::CURRENT_VERSION,
            ]);
            $ctx  = stream_context_create(['http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nAccept: application/json",
                'content'       => $payload,
                'timeout'       => 4,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents(self::VALIDATION_URL, false, $ctx);
            if ($body === false) return self::failOpen();

            $data  = json_decode($body, true);
            $valid = isset($data['valid']) && $data['valid'] === true;

            if ($valid) {
                self::writeCache(true, self::CACHE_TTL_VALID);
                self::writeGraceFile();
                return true;
            }

            self::writeCache(false, self::CACHE_TTL_FAIL);
            self::clearGraceFile();
            return false;
        } catch (\Throwable $e) {
            return self::failOpen();
        }
    }

    private static function failOpen(): bool
    {
        $until = self::readGraceFile();
        if ($until !== null && time() < $until) {
            self::writeGraceFile();
            return true;
        }
        return false;
    }

    private static function getCachedStatus(): ?bool
    {
        try {
            $data = App::instance()->cache(self::CACHE_DRIVER)->get(self::CACHE_KEY);
            if (is_array($data) && array_key_exists('isPro', $data)) {
                return (bool) $data['isPro'];
            }
        } catch (\Throwable $e) {}
        return null;
    }

    public static function getStatus(): array
    {
        $key    = App::instance()->option(self::LICENSE_OPT, '');
        $hasKey = !empty(trim($key));

        $cached = null;
        try {
            $cached = App::instance()->cache(self::CACHE_DRIVER)->get(self::CACHE_KEY);
        } catch (\Throwable $e) {}

        $graceUntil = self::readGraceFile();
        $isPro      = self::isPro();

        return [
            'isPro'      => $isPro,
            'hasKey'     => $hasKey,
            'maskedKey'  => $hasKey ? self::maskKey($key) : null,
            'cachedAt'   => is_array($cached) ? ($cached['at'] ?? null) : null,
            'cacheValid' => $hasKey && is_array($cached) && ($cached['isPro'] ?? false),
            'graceUntil' => $graceUntil,
        ];
    }

    private static function maskKey(string $key): string
    {
        $parts = explode('-', $key);
        if (count($parts) === 5) {
            return $parts[0] . '-****-****-****-' . $parts[4];
        }
        return substr($key, 0, 8) . '…';
    }

    private static function writeCache(bool $valid, int $ttlMinutes): void
    {
        try {
            App::instance()->cache(self::CACHE_DRIVER)->set(
                self::CACHE_KEY,
                ['isPro' => $valid, 'at' => time()],
                $ttlMinutes
            );
        } catch (\Throwable $e) {}
    }

    private static function graceFilePath(): string
    {
        return App::instance()->root('cache') . '/' . self::CACHE_DRIVER . '/.grace';
    }

    private static function readGraceFile(): ?int
    {
        $path = self::graceFilePath();
        if (!file_exists($path)) return null;
        $val = (int) trim((string) file_get_contents($path));
        return $val > 0 ? $val : null;
    }

    private static function writeGraceFile(): void
    {
        $path = self::graceFilePath();
        $dir  = dirname($path);
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        @file_put_contents($path, (string) (time() + self::GRACE_HOURS * 3600));
    }

    private static function clearGraceFile(): void
    {
        @unlink(self::graceFilePath());
    }
}
