<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Cache\TaggableStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;

/**
 * Cache helper that gracefully falls back when the current cache driver
 * does not support tagging (e.g. the "array" driver used in tests).
 */
final class CacheHelper
{
    /**
     * Determine whether the default cache store supports tags.
     */
    public static function supportsTags(): bool
    {
        return Cache::getStore() instanceof TaggableStore;
    }

    /**
     * Get a cache repository/tag instance that supports tags when possible.
     */
    public static function tags(array $tags): Repository
    {
        if (self::supportsTags()) {
            return Cache::tags($tags);
        }

        return Cache::store();
    }

    /**
     * Remember a value, using tags when the cache driver supports them.
     */
    public static function rememberTagged(array $tags, string $key, int $ttl, Closure $callback): mixed
    {
        return self::tags($tags)->remember($key, $ttl, $callback);
    }

    /**
     * Flush items for the given tags. Falls back to a full store flush
     * only when the driver does not support tagging and a fallback flush
     * is explicitly requested.
     */
    public static function flushTagged(array $tags, bool $fallbackFlush = false): void
    {
        if (self::supportsTags()) {
            Cache::tags($tags)->flush();

            return;
        }

        if ($fallbackFlush) {
            Cache::flush();
        }
    }

    /**
     * Forget a single key. When tags are supported the key is expected to
     * have been stored via the same tag set, so invalidation should be done
     * via flushTagged() instead. This helper is intended for non-tagged keys.
     */
    public static function forget(string $key): bool
    {
        return Cache::forget($key);
    }

    /**
     * Expose the underlying store for advanced checks.
     */
    public static function store(): Store
    {
        return Cache::getStore();
    }
}
