<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Kết quả của một POST có header Idempotency-Key, giữ 24 giờ.
 * Không dùng global scope theo shop: middleware luôn lọc shop_id tường minh.
 */
#[Fillable(['shop_id', 'key', 'request_hash', 'response_status', 'response_body', 'expires_at'])]
class IdempotencyKey extends Model
{
    public const TTL_HOURS = 24;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'response_status' => 'integer',
            'expires_at' => 'immutable_datetime',
        ];
    }
}
