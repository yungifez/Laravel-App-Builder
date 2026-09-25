<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthCheckTest extends TestCase
{
    public function test_health_endpoint_responds_without_revealing_configuration()
    {
        $appKey = (string) config('app.key');
        $databasePassword = (string) config('database.connections.'.config('database.default').'.password');

        $this->assertNotSame('', $appKey);
        $this->assertNotSame('', $databasePassword);

        $response = $this->get('/up');

        $response->assertOk();
        $this->assertStringNotContainsString($appKey, (string) $response->getContent());
        $this->assertStringNotContainsString($databasePassword, (string) $response->getContent());
    }
}
