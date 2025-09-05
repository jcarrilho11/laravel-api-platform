<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TasksApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_without_user_header_returns_401(): void
    {
        $res = $this->getJson('/tasks');
        $res->assertStatus(401)
            ->assertJson([ 'code' => 'unauthorized' ]);
    }

    public function test_create_task_list_and_idempotency(): void
    {
        $userId = '550e8400-e29b-41d4-a716-446655440000'; // Valid UUID format
        
        // Creates task
        $headers = [
            'X-User-Sub' => $userId,
            'Idempotency-Key' => 'key-123',
        ];

        $payload = [ 'title' => 'Write docs' ];

        $res1 = $this->withHeaders($headers)->postJson('/tasks', $payload);
        $res1->assertStatus(200)
            ->assertJsonStructure(['id', 'title', 'status', 'created_at'])
            ->assertJsonPath('title', 'Write docs')
            ->assertJsonPath('status', 'pending');

        $body1 = $res1->json();

        // Replays same key and payload -> same response
        $res2 = $this->withHeaders($headers)->postJson('/tasks', $payload);
        $res2->assertStatus(200)->assertExactJson($body1);

        // Same key with different payload -> 409 conflict
        $res3 = $this->withHeaders(['X-User-Sub' => $userId, 'Idempotency-Key' => 'key-123'])
            ->postJson('/tasks', ['title' => 'Different']);
        $res3->assertStatus(409)
            ->assertJson([ 'code' => 'idempotency_conflict' ]);

        // Lists tasks for the user
        $list = $this->withHeaders(['X-User-Sub' => $userId])->getJson('/tasks');
        $list->assertStatus(200)
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.title', 'Write docs');
    }
}
