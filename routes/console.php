<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

Schedule::command('booking:release-expired')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('outbox:publish')
    ->everyMinute()
    ->withoutOverlapping();

Schedule::command('catalog:consume')
    ->everyMinute()
    ->withoutOverlapping();

// Rollback-window drift gate: non-strict so a mismatch alerts through the
// structured log instead of failing the scheduler run. The zero-drift window
// evidenced here is the precondition for turning CATALOG_STATUS_DUAL_WRITE off.
Schedule::command('booking:verify-inventory')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
