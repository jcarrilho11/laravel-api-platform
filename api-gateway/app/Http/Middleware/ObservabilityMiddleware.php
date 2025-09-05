<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class ObservabilityMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Ensures X-Request-Id exists
        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();
        $request->headers->set('X-Request-Id', $requestId);
        
        // Starts timing
        $startTime = microtime(true);
        
        // Logs incoming request
        Log::info('request.incoming', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'query' => $request->query(),
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'user_sub' => $request->header('X-User-Sub'),
        ]);
        
        // Processes request
        $response = $next($request);
        
        // Calculates metrics
        $duration = (int)((microtime(true) - $startTime) * 1000);
        $statusCode = $response->getStatusCode();
        $statusClass = (int)($statusCode / 100);
        
        // Logs response with metrics
        Log::info('request.completed', [
            'request_id' => $requestId,
            'method' => $request->method(),
            'path' => $request->path(),
            'status' => $statusCode,
            'duration_ms' => $duration,
            'user_sub' => $request->header('X-User-Sub'),
            'is_error' => $statusClass >= 4,
            'is_success' => $statusClass === 2,
        ]);
        
        // Adds performance headers
        $response->headers->set('X-Request-Id', $requestId);
        $response->headers->set('X-Response-Time', $duration . 'ms');
        
        // Tracks error rates
        if ($statusClass >= 5) {
            Log::error('request.server_error', [
                'request_id' => $requestId,
                'path' => $request->path(),
                'status' => $statusCode,
                'duration_ms' => $duration,
            ]);
        }
        
        return $response;
    }
}
