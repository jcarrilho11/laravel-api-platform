<?php

declare(strict_types=1);

use App\Helpers\ApiResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

/**
 * Retrieves paginated tasks for authenticated user.
 */
Route::get('/tasks', function (Request $req) {
    $userId = $req->header('X-User-Sub');
    if (!$userId) {
        return ApiResponse::error('unauthorized', 'Missing user context', 401);
    }

    $page = max(1, (int) $req->query('page', 1));
    $limit = min(100, max(1, (int) $req->query('limit', 10)));
    $status = $req->query('status');

    $cacheKey = "tasks:user:{$userId}:page:{$page}:limit:{$limit}" . ($status ? ":status:{$status}" : '');
    
    $data = Cache::remember($cacheKey, 30, function () use ($userId, $status, $page, $limit) {
        $query = DB::table('tasks')->where('user_id', $userId);
        if ($status) {
            $query->where('status', $status);
        }
        $total = (clone $query)->count();
        $items = $query->orderBy('created_at', 'desc')
            ->forPage($page, $limit)
            ->get();

        return [
            'data' => $items,
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
        ];
    });

    return response()->json($data, 200);
});

/**
 * Creates a new task with idempotency support.
 */
Route::post('/tasks', function (Request $req) {
    $userId = $req->header('X-User-Sub');
    if (!$userId) {
        return ApiResponse::error('unauthorized', 'Missing user context', 401);
    }

    $idempotencyKey = $req->header('Idempotency-Key');
    if (!$idempotencyKey) {
        return ApiResponse::error('bad_request', 'Missing Idempotency-Key header', 400);
    }

    $payload = $req->validate([
        'title' => 'required|string|max:255',
        'status' => 'nullable|in:pending,done',
    ]);

    $status = $payload['status'] ?? 'pending';
    $requestHash = hash('sha256', json_encode([
        'title' => $payload['title'],
        'status' => $status,
    ]));

    // Checks for existing idempotency key
    $existing = DB::table('idempotency_keys')->where('key', $idempotencyKey)->first();
    if ($existing) {
        if ($existing->user_id != $userId || $existing->request_hash !== $requestHash) {
            return ApiResponse::error('idempotency_conflict', 'Same key used with different payload or user', 409);
        }
        return response()->json(json_decode($existing->response_body, true), (int) $existing->status_code);
    }

    try {
        DB::beginTransaction();

        $taskId = Str::uuid()->toString();
        DB::table('tasks')->insert([
            'id' => $taskId,
            'user_id' => $userId,
            'title' => $payload['title'],
            'status' => $status,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $task = DB::table('tasks')->where('id', $taskId)->first();
        $response = [
            'id' => $task->id,
            'title' => $task->title,
            'status' => $task->status,
            'created_at' => (string) $task->created_at,
        ];

        DB::table('idempotency_keys')->insert([
            'key' => $idempotencyKey,
            'user_id' => $userId,
            'request_hash' => $requestHash,
            'response_body' => json_encode($response),
            'status_code' => 200,
            'created_at' => now(),
        ]);

        DB::commit();
    } catch (\Illuminate\Database\QueryException $e) {
        DB::rollBack();
        
        // Race condition: checks if another request succeeded
        $existing = DB::table('idempotency_keys')->where('key', $idempotencyKey)->first();
        if ($existing && $existing->user_id == $userId && $existing->request_hash === $requestHash) {
            return response()->json(json_decode($existing->response_body, true), (int) $existing->status_code);
        }
        return ApiResponse::error('idempotency_conflict', 'Same key used with different payload', 409);
    }

    // Invalidates user cache
    $cachePattern = "tasks:user:{$userId}:*";
    $keys = Cache::getRedis()->keys($cachePattern);
    foreach ($keys as $key) {
        $key = str_replace(config('database.redis.options.prefix'), '', $key);
        Cache::forget($key);
    }

    Log::info('tasks.created', ['user_id' => $userId]);

    return response()->json($response, 200);
});
