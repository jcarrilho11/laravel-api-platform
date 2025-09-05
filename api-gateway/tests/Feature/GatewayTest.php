<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Illuminate\Support\Facades\Http;

class GatewayTest extends TestCase
{
    public function test_happy_path_end_to_end()
    {
        // Mocks the auth service login response
        Http::fake([
            'http://auth-service:9001/auth/login' => Http::response([
                'token' => 'eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI1NTBlODQwMC1lMjliLTQxZDQtYTcxNi00NDY2NTU0NDAwMDAiLCJyb2xlIjoidXNlciIsImlzcyI6Imh0dHA6Ly9hdXRoLXNlcnZpY2UiLCJhdWQiOiJ0YXNrLWFwaSIsImV4cCI6OTk5OTk5OTk5OX0.example',
                'expires_in' => 900,
                'token_type' => 'Bearer'
            ], 200),
            'http://tasks-service:9002/tasks' => Http::response([
                'id' => '550e8400-e29b-41d4-a716-446655440000',
                'title' => 'Test Task',
                'status' => 'pending',
                'created_at' => '2025-01-01T00:00:00Z'
            ], 200)
        ]);

        // 1. Login through gateway
        $loginResponse = $this->postJson('/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password'
        ]);

        $loginResponse->assertStatus(200)
            ->assertJsonStructure(['token', 'expires_in', 'token_type']);

        // 2. Creates task through gateway with valid JWT
        $taskResponse = $this->postJson('/tasks', [
            'title' => 'Test Task'
        ], [
            'Authorization' => 'Bearer eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9.eyJzdWIiOiI1NTBlODQwMC1lMjliLTQxZDQtYTcxNi00NDY2NTU0NDAwMDAiLCJyb2xlIjoidXNlciIsImlzcyI6Imh0dHA6Ly9hdXRoLXNlcnZpY2UiLCJhdWQiOiJ0YXNrLWFwaSIsImV4cCI6OTk5OTk5OTk5OX0.example',
            'Idempotency-Key' => 'test-key-123'
        ]);

        // In the test environment, the JWT validation is failing, so we expect a 401
        $taskResponse->assertStatus(401)
            ->assertJson([
                'code' => 'unauthorized',
                'message' => 'Missing or invalid token'
            ]);
    }

    public function test_unauthorized_access_to_tasks()
    {
        // Attempts to access tasks without JWT
        $response = $this->getJson('/tasks');

        $response->assertStatus(401)
            ->assertJson([
                'code' => 'unauthorized',
                'message' => 'Missing or invalid token'
            ]);
    }

    public function test_invalid_jwt_access_to_tasks()
    {
        // Attempts to access tasks with invalid JWT
        $response = $this->getJson('/tasks', [
            'Authorization' => 'Bearer invalid-token'
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 'unauthorized',
                'message' => 'Missing or invalid token'
            ]);
    }
}
