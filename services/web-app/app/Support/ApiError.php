<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class ApiError
{
    public static function response(
        Request $request,
        string $code,
        string $message,
        int $status = 400,
        array $headers = [],
    ): JsonResponse {
        $requestId = $request->attributes->get('request_id') ?? (string) Str::uuid();

        return response()->json([
            'code' => $code,
            'message' => $message,
            'request_id' => $requestId,
        ], $status, $headers);
    }
}
