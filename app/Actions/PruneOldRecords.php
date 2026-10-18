<?php

namespace App\Actions;

use App\Enums\WebhookDeliveryStatus;
use App\Models\IdempotencyKey;
use App\Models\WebhookDelivery;
use App\Tenancy\ShopScope;

/**
 * Dọn bản ghi hết hạn, trên mọi shop: database của Wasmer chỉ có 100 MB.
 * Không dùng model:prune vì đó là lệnh Artisan, chạy qua Schedule::command sẽ fork tiến trình.
 */
final class PruneOldRecords
{
    /** @return array{idempotency_keys: int, webhook_deliveries: int} */
    public function __invoke(): array
    {
        return [
            'idempotency_keys' => IdempotencyKey::query()->where('expires_at', '<=', now())->delete(),
            // Nhật ký từng lần gửi (webhook_attempts) xóa theo khóa ngoại cascade.
            'webhook_deliveries' => WebhookDelivery::withoutGlobalScope(ShopScope::class)
                ->where('status', '!=', WebhookDeliveryStatus::Pending)
                ->where('created_at', '<', now()->subDays((int) config('webhooks.retention_days')))
                ->delete(),
        ];
    }
}
