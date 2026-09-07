<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class ReadinessEndpointTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_readiness_reports_database_and_queue_configuration(): void
    {
        $this->getJson('/ready')->assertOk()->assertExactJson([
            'status' => 'ready',
            'checks' => ['database' => true, 'queue' => true],
        ]);
    }

    public function test_readiness_returns_service_unavailable_for_missing_queue_configuration(): void
    {
        config()->set('queue.default', '');

        $this->getJson('/ready')->assertStatus(503)->assertJsonPath('status', 'not_ready')->assertJsonPath('checks.queue', false);
    }
}
