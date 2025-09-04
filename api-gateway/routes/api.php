<?php

declare(strict_types=1);

use App\Helpers\ApiResponse;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Proxies requests to Auth Service.
 */
Route::any('auth/{path?}', function (Request $req, string $path = '') {
    $limit = (int) config('gateway.rate_limit_per_minute', 60);
    $ip = $req->ip();
    $rlKey = 'gw:ip:' . $ip;
    
    if (RateLimiter::tooManyAttempts($rlKey, $limit)) {
        return ApiResponse::error('too_many_requests', 'Rate limit exceeded', 429);
    }
    
    RateLimiter::hit($rlKey, 60);
    
    $base = rtrim((string) config('gateway.auth_base_url', env('AUTH_BASE_URL', '')), '/');
    if ($base === '') {
        return ApiResponse::error('server_error', 'AUTH_BASE_URL not configured', 500);
    }

    $url = $base . '/auth/' . ltrim($path, '/');
    $rid = $req->header('X-Request-Id', (string) Str::uuid());

    $start = microtime(true);
    $resp = Http::withHeaders([
        'X-Request-Id' => $rid,
    ])->send($req->method(), $url, [
        'query' => $req->query(),
        'body' => $req->getContent(),
        'headers' => $req->headers->all(),
    ]);
    $latency = (int) ((microtime(true) - $start) * 1000);

    Log::info('gateway.auth', [
        'request_id' => $rid,
        'method' => $req->method(),
        'path' => '/auth/' . $path,
        'status' => $resp->status(),
        'latency_ms' => $latency,
    ]);

    return response($resp->body(), $resp->status())
        ->withHeaders(array_merge($resp->headers(), ['X-Request-Id' => $rid]));
})->where('path', '.*')->name('auth.proxy');


/**
 * Proxies requests to Tasks Service with JWT validation.
 */
Route::any('tasks/{path?}', function (Request $req, string $path = '') {
    $ip = $req->ip();
    $rlKey = 'gw:ip:' . $ip;
    
    if (RateLimiter::tooManyAttempts($rlKey, 60)) {
        return ApiResponse::error('too_many_requests', 'Rate limit exceeded', 429);
    }
    
    RateLimiter::hit($rlKey, 60);
    
    $token = $req->bearerToken();
    if (!$token) {
        return ApiResponse::error('unauthorized', 'Missing or invalid token', 401);
    }

    try {
        $secret = (string) config('jwt.secret', env('JWT_SECRET'));
        $aud = (string) config('jwt.aud', env('JWT_AUD'));
        $iss = config('jwt.iss', env('JWT_ISS'));
        
        if (!$secret) {
            Log::error('JWT secret not configured');
            return ApiResponse::error('server_error', 'JWT configuration missing', 500);
        }

        $payload = JWT::decode($token, new Key($secret, 'HS256'));
        
        if (!isset($payload->sub)) {
            return ApiResponse::error('unauthorized', 'Missing or invalid token', 401);
        }
        
        if (!isset($payload->aud) || $payload->aud !== $aud) {
            return ApiResponse::error('unauthorized', 'Missing or invalid token', 401);
        }
        
        if (!isset($payload->iss) || $payload->iss !== $iss) {
            return ApiResponse::error('unauthorized', 'Missing or invalid token', 401);
        }
        
        if (isset($payload->exp) && $payload->exp < time()) {
            return ApiResponse::error('unauthorized', 'Missing or invalid token', 401);
        }
        
        $sub = $payload->sub;
        $role = $payload->role ?? 'user';
    } catch (\Throwable $e) {
        return ApiResponse::error('unauthorized', 'Missing or invalid token', 401);
    }

    $base = rtrim((string) config('gateway.tasks_base_url', env('TASKS_BASE_URL', '')), '/');
    if ($base === '') {
        return ApiResponse::error('server_error', 'TASKS_BASE_URL not configured', 500);
    }

    $url = $base . '/tasks';
    if ($path !== '' && $path !== null) {
        $url .= '/' . ltrim($path, '/');
    }
    
    if ($req->getQueryString()) {
        $url .= '?' . $req->getQueryString();
    }
    
    $rid = $req->header('X-Request-Id', (string) Str::uuid());

    $headers = [
        'Accept' => 'application/json',
        'Content-Type' => $req->header('Content-Type', 'application/json'),
        'X-Request-Id' => $rid,
        'X-User-Sub' => $sub,
        'X-User-Role' => $role,
    ];

    if ($idk = $req->header('Idempotency-Key')) {
        $headers['Idempotency-Key'] = $idk;
    }

    if ($auth = $req->header('Authorization')) {
        $headers['Authorization'] = $auth;
    }

    $start = microtime(true);
    
    $request = Http::withHeaders($headers);
    
    $method = strtolower($req->method());
    $body = $req->getContent() ?: null;
    
    if (in_array($method, ['post', 'put', 'patch'])) {
        $resp = $request->withBody($body, 'application/json')->$method($url);
    } else {
        $resp = $request->$method($url);
    }
    
    $latency = (int) ((microtime(true) - $start) * 1000);

    Log::info('gateway.tasks', [
        'request_id' => $rid,
        'user_sub' => $sub,
        'method' => $req->method(),
        'path' => '/tasks/' . $path,
        'status' => $resp->status(),
        'latency_ms' => $latency,
    ]);

    return response($resp->body(), $resp->status())
        ->withHeaders(array_merge($resp->headers(), ['X-Request-Id' => $rid]));
})->where('path', '.*');
