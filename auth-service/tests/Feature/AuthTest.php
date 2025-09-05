<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_login()
    {
        // Deletes existing test user if exists
        DB::table('users')->where('email', 'test@example.com')->delete();
        
        // Creates test user
        DB::table('users')->insert([
            'id' => Str::uuid()->toString(),
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make('password'),
            'role' => 'user',
            'created_at' => now(),
            'updated_at' => now()
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password'
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'expires_in', 'token_type'])
            ->assertJson(['token_type' => 'Bearer']);
    }

    public function test_invalid_credentials()
    {
        $response = $this->postJson('/auth/login', [
            'email' => 'nonexistent@example.com',
            'password' => 'wrongpassword'
        ]);

        $response->assertStatus(401)
            ->assertJson([
                'code' => 'invalid_credentials',
                'message' => 'Invalid email or password'
            ]);
    }

    public function test_validation_error()
    {
        $response = $this->postJson('/auth/login', [
            'email' => 'invalid-email',
            'password' => ''
        ]);

        $response->assertStatus(422)
            ->assertJsonStructure(['code', 'message', 'details']);
    }
}
