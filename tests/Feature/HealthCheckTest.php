<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_liveness_endpoint_returns_minimal_ok_payload(): void
    {
        $this->getJson('/healthz')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_endpoint_checks_the_database(): void
    {
        $this->getJson('/readyz')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_readiness_endpoint_hides_database_errors(): void
    {
        config(['database.connections.mariadb.host' => '203.0.113.1', 'database.connections.mariadb.port' => 1]);
        DB::purge('mariadb');

        $response = $this->getJson('/readyz');

        $response->assertStatus(503)->assertExactJson(['status' => 'unavailable']);
    }
}
