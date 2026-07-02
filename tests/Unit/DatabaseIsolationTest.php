<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class DatabaseIsolationTest extends TestCase
{
    public function test_the_suite_runs_on_in_memory_sqlite_never_the_live_database(): void
    {
        $this->assertSame('sqlite', config('database.default'));
        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', config('database.connections.sqlite.database'));
    }
}
