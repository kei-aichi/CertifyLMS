<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;

/**
 * Shared cache-version and key management for the admin dashboard aggregates.
 *
 * The version key is intentionally kept without a TTL. Version initialization
 * and updates are serialized by the cache lock so concurrent invalidations do
 * not perform an unlocked read-modify-write sequence.
 */
final class AdminDashboardCacheService
{
    public function version(): int
    {
        return $this->withLock(function (): int {
            $key = $this->versionKey();
            $version = Cache::get($key);

            if ($version !== null) {
                return (int) $version;
            }

            // The random value makes accidental reuse of an old data key highly
            // unlikely, but cannot provide an absolute collision guarantee.
            $initial = random_int(1, PHP_INT_MAX);
            Cache::forever($key, $initial);

            return $initial;
        });
    }

    public function versionedKey(string $logicalKey, ?int $version = null): string
    {
        return $logicalKey.'.v'.($version ?? $this->version());
    }

    public function kpiTtl(): int
    {
        return (int) config('dashboard.admin_kpi_cache_ttl', 300);
    }

    public function completionRateTtl(): int
    {
        return (int) config('dashboard.admin_completion_rate_cache_ttl', 300);
    }

    public function retryLimit(): int
    {
        return max(1, (int) config('dashboard.admin_cache_retry_limit', 2));
    }

    public function invalidate(): int
    {
        return $this->withLock(function (): int {
            $key = $this->versionKey();
            $current = Cache::get($key);
            $next = $current === null ? random_int(1, PHP_INT_MAX) : ((int) $current + 1);

            Cache::forever($key, $next);

            return $next;
        });
    }

    private function versionKey(): string
    {
        return (string) config('dashboard.admin_stats_version_key', 'dashboard.admin_enrollment_stats.version');
    }

    private function withLock(callable $callback): mixed
    {
        $lock = Cache::lock(
            (string) config('dashboard.admin_stats_lock_key', 'dashboard.admin_enrollment_stats.lock'),
            (int) config('dashboard.admin_stats_lock_seconds', 10),
        );

        return $lock->block(
            (int) config('dashboard.admin_stats_lock_wait_seconds', 5),
            $callback,
        );
    }
}
