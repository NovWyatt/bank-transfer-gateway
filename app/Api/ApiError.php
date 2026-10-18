<?php

namespace App\Api;

use Illuminate\Http\JsonResponse;

/**
 * Lỗi của API v1: {"message": "...", "code": "..."}. "code" ổn định để máy đọc, "message" cho người đọc.
 * Lỗi kiểm tra dữ liệu (422) giữ dạng mặc định của Laravel: {"message", "errors"}.
 */
final class ApiError
{
    public static function json(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code], $status);
    }
}
