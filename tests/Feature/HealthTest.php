<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_endpoint_reports_up(): void
    {
        $this->get('/health')->assertOk();
    }
}
