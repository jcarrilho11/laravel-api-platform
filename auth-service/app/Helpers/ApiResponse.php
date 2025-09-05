<?php

declare(strict_types=1);

namespace App\Helpers;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    /**
     * Creates standardized error response.
     */
    public static function error(string $code, string $message, int $status, $details = null): JsonResponse
    {
        $body = [
            'code' => $code,
            'message' => $message,
        ];
        
        if (!is_null($details)) {
            $body['details'] = $details;
        }
        
        return response()->json($body, $status);
    }
    
    /**
     * Creates standardized success response.
     */
    public static function success($data = null, int $status = 200): JsonResponse
    {
        return response()->json($data, $status);
    }
}
