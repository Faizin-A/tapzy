<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    /**
     * Test the API v1 health check endpoint.
     */
    public function test_api_v1_health_returns_successful_response(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertExactJson([
                'success' => true,
                'message' => 'TAPZY API is running',
                'data' => [
                    'application' => 'TAPZY',
                    'status' => 'healthy',
                ],
            ]);
    }
}

