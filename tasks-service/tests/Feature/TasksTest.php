<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Tests\TestCase;
use Illuminate\Support\Facades\DB;

class TasksTest extends TestCase
{
    use RefreshDatabase;

    public function test_idempotency_replay_returns_same_response()
    {
        $userId = '550e8400-e29b-41d4-a716-446655440000'; // Valid UUID format
        
        // Creates initial task
        $idempotencyKey = 'test-idempotency-key-123';
        $taskData = ['title' => 'Idempotency Test Task'];
        
        $response1 = $this->postJson('/tasks', $taskData, [
            'X-User-Sub' => $userId,
            'Idempotency-Key' => $idempotencyKey
        ]);

        $response1->assertStatus(200)
            ->assertJsonStructure(['id', 'title', 'status', 'created_at']);

        $firstResponse = $response1->json();

        // Replays with same key and same body
        $response2 = $this->postJson('/tasks', $taskData, [
            'X-User-Sub' => $userId,
            'Idempotency-Key' => $idempotencyKey
        ]);

        $response2->assertStatus(200)
            ->assertExactJson($firstResponse);

        // Verifies only one task was created in database
        $this->assertDatabaseCount('tasks', 1);
    }

    public function test_idempotency_conflict_different_body()
    {
        $userId = '550e8400-e29b-41d4-a716-446655440000'; // Valid UUID format
        $idempotencyKey = 'test-conflict-key-456';
        
        // Creates initial task
        $response1 = $this->postJson('/tasks', ['title' => 'Original Task'], [
            'X-User-Sub' => $userId,
            'Idempotency-Key' => $idempotencyKey
        ]);

        $response1->assertStatus(200);

        // Tries to use same key with different body
        $response2 = $this->postJson('/tasks', ['title' => 'Different Task'], [
            'X-User-Sub' => $userId,
            'Idempotency-Key' => $idempotencyKey
        ]);

        $response2->assertStatus(409)
            ->assertJson([
                'code' => 'idempotency_conflict',
                'message' => 'Same key used with different payload or user'
            ]);
    }

    public function test_missing_idempotency_key()
    {
        $userId = '550e8400-e29b-41d4-a716-446655440000'; // Valid UUID format
        
        $response = $this->postJson('/tasks', ['title' => 'Test Task'], [
            'X-User-Sub' => $userId
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'code' => 'bad_request',
                'message' => 'Missing Idempotency-Key header'
            ]);
    }

    public function test_task_listing_pagination()
    {
        $userId = '550e8400-e29b-41d4-a716-446655440000'; // Valid UUID format
        
        // Creates test tasks
        for ($i = 1; $i <= 5; $i++) {
            DB::table('tasks')->insert([
                'id' => \Illuminate\Support\Str::uuid()->toString(),
                'user_id' => $userId,
                'title' => "Task $i",
                'status' => 'pending',
                'created_at' => now(),
                'updated_at' => now()
            ]);
        }

        // Tests pagination
        $response = $this->getJson('/tasks?page=1&limit=2', [
            'X-User-Sub' => $userId
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'page', 'limit', 'total'])
            ->assertJson([
                'page' => 1,
                'limit' => 2,
                'total' => 5
            ]);

        $this->assertCount(2, $response->json('data'));
    }
}
