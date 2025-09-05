<?php

namespace Tests\Feature;

// uses Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_the_application_returns_a_successful_response(): void
    {
        // Tests a simple API endpoint that doesn't require authentication
        $response = $this->getJson('/tasks');

        // Should return 401 unauthorized since no X-User-Sub header is provided
        $response->assertStatus(401)
            ->assertJson(['code' => 'unauthorized']);
    }
}
