<?php

namespace App\Http\Resources;

class ApiResponse
{
    public static function success($message = 'success', $statusCode = 200, $data = null)
    {
        $statusCode = self::normalizeStatusCode($statusCode, 200);

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $data,
            'status_code' => $statusCode,
            'timestamp' => now()->toIso8601String(),
        ], $statusCode);
    }

    public static function error($message = 'error', $statusCode = 400, $data = null)
    {
        $statusCode = self::normalizeStatusCode($statusCode, 500);

        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data,
            'status_code' => $statusCode,
            'timestamp' => now()->toIso8601String(),
        ], $statusCode);
    }

    public static function validation($message = 'Validation failed.', $errors = null, $statusCode = 422)
    {
        $statusCode = self::normalizeStatusCode($statusCode, 422);

        return response()->json([
            'success' => false,
            'message' => $message,
            'errors' => $errors,
            'status_code' => $statusCode,
            'timestamp' => now()->toIso8601String(),
        ], $statusCode);
    }

    private static function normalizeStatusCode($statusCode, int $fallback): int
    {
        $normalized = (int) $statusCode;

        if ($normalized < 100 || $normalized > 599) {
            return $fallback;
        }

        return $normalized;
    }
}
