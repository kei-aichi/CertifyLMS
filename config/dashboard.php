<?php

declare(strict_types=1);

return [
    'admin_kpi_cache_key' => 'admin_dashboard:kpi',
    'admin_completion_rate_cache_key' => 'admin_dashboard:completion_rates',
    'admin_stats_version_key' => 'dashboard.admin_enrollment_stats.version',
    'admin_stats_lock_key' => 'dashboard.admin_enrollment_stats.lock',
    'admin_kpi_cache_ttl' => (int) env('ADMIN_DASHBOARD_KPI_CACHE_TTL', 300),
    'admin_completion_rate_cache_ttl' => (int) env('ADMIN_DASHBOARD_COMPLETION_RATE_CACHE_TTL', 300),
    'admin_cache_retry_limit' => (int) env('DASHBOARD_ADMIN_CACHE_RETRY_LIMIT', 2),
    'admin_stats_lock_seconds' => (int) env('DASHBOARD_ADMIN_STATS_LOCK_SECONDS', 10),
    'admin_stats_lock_wait_seconds' => (int) env('DASHBOARD_ADMIN_STATS_LOCK_WAIT_SECONDS', 5),
];
