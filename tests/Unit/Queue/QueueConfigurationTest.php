<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use Tests\TestCase;

final class QueueConfigurationTest extends TestCase
{
    public function test_database_queue_uses_commit_after_dispatch_and_retry_window(): void
    {
        $this->assertSame('database', config('queue.connections.database.driver'));
        $this->assertTrue(config('queue.connections.database.after_commit'));
        $this->assertSame(180, config('queue.connections.database.retry_after'));
    }

    public function test_database_cache_store_is_configured_for_shared_locks(): void
    {
        $this->assertSame('database', config('cache.stores.database.driver'));
        $this->assertSame('cache', config('cache.stores.database.table'));
        $this->assertSame('cache_locks', config('cache.stores.database.lock_table', 'cache_locks'));
    }
}
