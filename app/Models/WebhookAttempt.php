<?php

namespace App\Models;

use App\Enums\DeliveryTrigger;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một lần gửi webhook. Không có shop_id riêng: luôn đọc qua WebhookDelivery đã lọc theo shop.
 */
#[Fillable(['trigger', 'response_code', 'response_body', 'error', 'duration_ms'])]
class WebhookAttempt extends Model
{
    public const UPDATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => DeliveryTrigger::class,
            'response_code' => 'integer',
            'duration_ms' => 'integer',
        ];
    }

    /** @return BelongsTo<WebhookDelivery, $this> */
    public function delivery(): BelongsTo
    {
        return $this->belongsTo(WebhookDelivery::class, 'webhook_delivery_id');
    }

    public function succeeded(): bool
    {
        return $this->response_code !== null && $this->response_code >= 200 && $this->response_code < 300;
    }
}
