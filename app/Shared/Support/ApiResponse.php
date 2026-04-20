<?php

namespace App\Shared\Support;

use Illuminate\Http\JsonResponse;

class ApiResponse
{
    public static function success(
        mixed $data = null,
        string $message = 'OK',
        string $code = 'OK',
        array $detail = [],
        int $status = 200
    ): JsonResponse {
        return response()->json([
            'ok' => true,
            'code' => $code,
            'message' => $message,
            'detail' => $detail,
            'data' => $data,
        ], $status);
    }

    public static function error(
        string $message = 'Request failed',
        string $code = 'REQUEST_FAILED',
        array $detail = [],
        int $status = 400,
        mixed $data = null
    ): JsonResponse {
        return response()->json([
            'ok' => false,
            'code' => $code,
            'message' => $message,
            'detail' => $detail,
            'data' => $data,
        ], $status);
    }
}
