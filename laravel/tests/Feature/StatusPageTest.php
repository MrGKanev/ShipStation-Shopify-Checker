<?php

namespace Tests\Feature;

use Tests\TestCase;

class StatusPageTest extends TestCase
{
    public function test_status_page_is_public_and_reports_operational_dependencies(): void
    {
        $this->travelTo('2026-09-07 12:30:00');
        $this->get('/status')->assertOk()->assertSeeText('System status')->assertSeeText('Operational')->assertSeeText('database')->assertSeeText('queue')->assertSeeText('2026-09-07T12:30:00');
        $this->get('/ready')->assertOk()->assertJsonPath('status', 'ready');
    }
}
