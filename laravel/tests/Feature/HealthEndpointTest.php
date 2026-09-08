<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthEndpointTest extends TestCase
{
    public function test_health_endpoint_reports_that_the_application_is_available(): void
    {
        $response = $this->get('/up');

        $response->assertOk();
    }
}
