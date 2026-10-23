<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\AdminDashboardCacheService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use Mockery;
use Tests\TestCase;

class AdminDashboardCacheServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Facade::clearResolvedInstances();
        config()->set('dashboard.admin_stats_version_key', 'test.dashboard.version');
        config()->set('dashboard.admin_stats_lock_key', 'test.dashboard.lock');
        config()->set('dashboard.admin_kpi_cache_key', 'test.dashboard.kpi');
        config()->set('dashboard.admin_completion_rate_cache_key', 'test.dashboard.rate');
        config()->set('dashboard.admin_kpi_cache_ttl', 300);
        config()->set('dashboard.admin_completion_rate_cache_ttl', 300);
        config()->set('dashboard.admin_cache_retry_limit', 2);
        Cache::forget('test.dashboard.version');
    }

    public function test_initializes_and_reuses_one_version(): void
    {
        $service = app(AdminDashboardCacheService::class);

        $first = $service->version();
        $second = $service->version();

        $this->assertIsInt($first);
        $this->assertGreaterThan(0, $first);
        $this->assertSame($first, $second);
    }

    public function test_generates_kpi_and_completion_keys_from_the_same_version(): void
    {
        $service = app(AdminDashboardCacheService::class);
        $version = $service->version();

        $this->assertSame('test.dashboard.kpi.v'.$version, $service->versionedKey((string) config('dashboard.admin_kpi_cache_key'), $version));
        $this->assertSame('test.dashboard.rate.v'.$version, $service->versionedKey((string) config('dashboard.admin_completion_rate_cache_key'), $version));
    }

    public function test_invalidation_advances_the_version(): void
    {
        $service = app(AdminDashboardCacheService::class);
        $before = $service->version();
        $after = $service->invalidate();

        $this->assertSame($before + 1, $after);
        $this->assertSame($after, $service->version());
    }

    public function test_multiple_service_instances_advance_the_shared_version(): void
    {
        $firstService = app(AdminDashboardCacheService::class);
        $secondService = app(AdminDashboardCacheService::class);

        $before = $firstService->version();
        $firstService->invalidate();
        $secondService->invalidate();

        $this->assertSame($before + 2, $secondService->version());
    }

    public function test_lock_is_released_after_an_update(): void
    {
        $service = app(AdminDashboardCacheService::class);

        $service->invalidate();
        $next = $service->invalidate();

        $this->assertSame($service->version(), $next);
    }

    public function test_reads_ttl_and_retry_configuration(): void
    {
        $service = app(AdminDashboardCacheService::class);

        $this->assertSame(300, $service->kpiTtl());
        $this->assertSame(300, $service->completionRateTtl());
        $this->assertSame(2, $service->retryLimit());
    }

    public function test_missing_version_is_reinitialized_without_reusing_a_fixed_old_version(): void
    {
        $service = app(AdminDashboardCacheService::class);
        $first = $service->version();
        Cache::forget('test.dashboard.version');
        $second = $service->version();

        $this->assertNotSame($first, $second);
        $this->assertSame($second, $service->version());
    }

    public function test_reinitialized_version_generates_a_new_data_key(): void
    {
        $service = app(AdminDashboardCacheService::class);
        $firstKey = $service->versionedKey('test.dashboard.kpi');

        Cache::forget('test.dashboard.version');

        $secondKey = $service->versionedKey('test.dashboard.kpi');

        $this->assertNotSame($firstKey, $secondKey);
    }

    public function test_lock_failure_is_propagated(): void
    {
        $lock = Mockery::mock();
        $lock->shouldReceive('block')->once()->andThrow(new \Illuminate\Contracts\Cache\LockTimeoutException);
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        $this->expectException(\Illuminate\Contracts\Cache\LockTimeoutException::class);
        app(AdminDashboardCacheService::class)->invalidate();
    }

    public function test_cache_exception_is_propagated(): void
    {
        Cache::shouldReceive('lock')->once()->andThrow(new \RuntimeException('cache unavailable'));

        $this->expectException(\RuntimeException::class);
        app(AdminDashboardCacheService::class)->version();
    }

    public function test_forever_write_exception_is_propagated(): void
    {
        $lock = Mockery::mock();
        $lock->shouldReceive('block')->once()->andReturnUsing(static function (int $seconds, callable $callback): mixed {
            return $callback();
        });
        Cache::shouldReceive('lock')->once()->andReturn($lock);
        Cache::shouldReceive('get')->once()->andReturn(null);
        Cache::shouldReceive('forever')->once()->andThrow(new \RuntimeException('cache write failed'));

        $this->expectException(\RuntimeException::class);
        app(AdminDashboardCacheService::class)->version();
    }

    public function test_file_store_initializes_and_updates_a_version(): void
    {
        config()->set('cache.default', 'file');
        Facade::clearResolvedInstances();
        config()->set('dashboard.admin_stats_version_key', 'test.dashboard.file.'.uniqid());
        config()->set('dashboard.admin_stats_lock_key', 'test.dashboard.file.lock.'.uniqid());

        $service = app(AdminDashboardCacheService::class);
        $before = $service->version();
        $after = $service->invalidate();

        $this->assertSame($before + 1, $after);
        $this->assertSame($after, $service->version());
    }
}
