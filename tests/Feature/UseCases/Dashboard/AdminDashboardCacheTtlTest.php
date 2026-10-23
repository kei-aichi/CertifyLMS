<?php

declare(strict_types=1);

namespace Tests\Feature\UseCases\Dashboard;

use App\Models\Certification;
use App\Models\Enrollment;
use App\Services\AdminDashboardCacheService;
use App\Services\EnrollmentStatsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Facade;
use Tests\TestCase;

class AdminDashboardCacheTtlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('cache.default', 'array');
        Facade::clearResolvedInstances();
        config()->set('dashboard.admin_kpi_cache_ttl', 300);
        config()->set('dashboard.admin_completion_rate_cache_ttl', 300);
        Cache::flush();
    }

    public function test_kpi_hits_within_ttl_and_reaggregates_after_expiry(): void
    {
        Enrollment::factory()->learning()->count(2)->create();
        $stats = app(EnrollmentStatsService::class);
        $version = app(AdminDashboardCacheService::class)->version();

        $first = $stats->adminKpi();
        Enrollment::factory()->learning()->create();
        $second = $stats->adminKpi();

        $this->assertSame(2, $first['learning_count']);
        $this->assertSame(2, $second['learning_count']);
        $this->assertSame($version, app(AdminDashboardCacheService::class)->version());

        $this->travel(301)->seconds();
        $third = $stats->adminKpi();

        $this->assertSame(3, $third['learning_count']);
        $this->assertSame($version, app(AdminDashboardCacheService::class)->version());
    }

    public function test_completion_rate_has_independent_ttl(): void
    {
        $certification = Certification::factory()->published()->create();
        Enrollment::factory()->for($certification)->learning()->count(2)->create();
        config()->set('dashboard.admin_kpi_cache_ttl', 600);
        config()->set('dashboard.admin_completion_rate_cache_ttl', 300);
        $stats = app(EnrollmentStatsService::class);
        $version = app(AdminDashboardCacheService::class)->version();

        $stats->adminKpi();
        $first = $stats->completionRateByCertification();
        Enrollment::factory()->for($certification)->passed()->create();
        $withinTtl = $stats->completionRateByCertification();

        $this->assertSame(0.0, $first->first()['completion_rate']);
        $this->assertSame(0.0, $withinTtl->first()['completion_rate']);

        $this->travel(301)->seconds();
        $expired = $stats->completionRateByCertification();

        $this->assertSame(0.3333, $expired->first()['completion_rate']);
        $this->assertSame($version, app(AdminDashboardCacheService::class)->version());
    }

    public function test_ttl_hit_does_not_execute_aggregate_queries(): void
    {
        Enrollment::factory()->learning()->count(2)->create();
        $stats = app(EnrollmentStatsService::class);
        $stats->adminKpi();
        $stats->completionRateByCertification();

        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        $stats->adminKpi();
        $stats->completionRateByCertification();

        $this->assertSame(0, $queries);
    }
}
