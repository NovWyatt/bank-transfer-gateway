<?php

namespace App\Models;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Một sự kiện cần gửi tới một endpoint. payload là chuỗi JSON nguyên văn (không cast),
 * vì chữ ký tính trên đúng các byte đã lưu.
 */
#[Fillable([
    'shop_id', 'webhook_endpoint_id', 'event_id', 'event_type', 'payload', 'status',
    'attempt_count', 'next_attempt_at', 'last_attempt_at', 'last_response_code', 'delivered_at',
])]
class WebhookDelivery extends Model
{
    use BelongsToShop;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => WebhookEventType::class,
            'status' => WebhookDeliveryStatus::class,
            'attempt_count' => 'integer',
            'next_attempt_at' => 'immutable_datetime',
            'last_attempt_at' => 'immutable_datetime',
            'last_response_code' => 'integer',
            'delivered_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /** @return HasMany<WebhookAttempt, $this> */
    public function attempts(): HasMany
    {
        return $this->hasMany(WebhookAttempt::class);
    }
}
