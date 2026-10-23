<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Services\AdminDashboardCacheService;
use App\Services\EnrollmentStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use RuntimeException;
use Tests\TestCase;

class AdminDashboardCacheGenerationRaceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Facade::clearResolvedInstances();
        config()->set('dashboard.admin_cache_retry_limit', 2);
        Cache::flush();
    }

    public function test_generation_change_during_aggregation_retries_and_returns_new_generation(): void
    {
        \App\Models\Enrollment::factory()->learning()->create();
        $cache = app(AdminDashboardCacheService::class);
        $stats = app(EnrollmentStatsService::class);
        $invalidated = false;
        $queries = 0;

        DB::listen(function () use (&$invalidated, &$queries, $cache): void {
            $queries++;
            if (! $invalidated) {
                $invalidated = true;
                $cache->invalidate();
            }
        });

        $result = $stats->adminKpi();

        $this->assertSame(1, $result['learning_count']);
        $this->assertTrue($invalidated);
        $this->assertGreaterThanOrEqual(3, $queries);

        $version = $cache->version();
        $newKey = $cache->versionedKey((string) config('dashboard.admin_kpi_cache_key'), $version);
        $oldKey = (string) config('dashboard.admin_kpi_cache_key').'.v'.($version - 1);
        $this->assertTrue(Cache::has($newKey));
        $this->assertTrue(Cache::has($oldKey));
        $this->assertNotSame($oldKey, $newKey);
    }

    public function test_retry_limit_throws_when_generation_changes_on_every_aggregation_query(): void
    {
        \App\Models\Enrollment::factory()->learning()->create();
        $cache = app(AdminDashboardCacheService::class);

        DB::listen(function () use ($cache): void {
            $cache->invalidate();
        });

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('generation changed during aggregation');
        app(EnrollmentStatsService::class)->adminKpi();
    }
}
