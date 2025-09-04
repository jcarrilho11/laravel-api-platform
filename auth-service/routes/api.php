<?php

declare(strict_types=1);

use App\Helpers\ApiResponse;
use Firebase\JWT\JWT;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

/**
 * Authenticates user and issues JWT token.
 */
Route::post('/auth/login', function (Request $req) {
    $payload = $req->validate([
        'email' => 'required|email',
        'password' => 'required|string|min:3',
    ]);

    $email = strtolower($payload['email']);
    $ip = $req->ip();
    $throttleKey = sprintf('login:%s:%s', $email, $ip);

    if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
        return ApiResponse::error('too_many_requests', 'Too many login attempts', 429);
    }

    RateLimiter::hit($throttleKey, 60);

    $user = DB::table('users')->where('email', $email)->first();

    $authenticated = false;
    if ($user) {
        try {
            $authenticated = Hash::check($payload['password'], $user->password);
        } catch (\Throwable $e) {
            Log::error('auth.login.hash_error', [
                'error' => $e->getMessage(),
            ]);
            $authenticated = false;
        }
    }

    if (!$authenticated) {
        return ApiResponse::error('invalid_credentials', 'Invalid email or password', 401);
    }

    $now = time();
    $ttl = 15 * 60;
    $token = JWT::encode([
        'iss' => env('JWT_ISS', 'http://auth-service'),
        'aud' => env('JWT_AUD', 'task-api'),
        'iat' => $now,
        'nbf' => $now,
        'exp' => $now + $ttl,
        'sub' => $user->id,
        'role' => $user->role ?? 'user',
    ], env('JWT_SECRET', 'dev-shared-secret'), 'HS256');

    Log::info('auth.login', [
        'request_id' => $req->header('X-Request-Id'),
        'email' => $email,
        'user_id' => $user->id,
    ]);

    return response()->json([
        'token' => $token,
        'token_type' => 'Bearer',
        'expires_in' => $ttl,
    ], 200);
});
