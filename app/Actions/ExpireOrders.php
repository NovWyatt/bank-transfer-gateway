<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Enums\WebhookEventType;
use App\Models\Order;
use App\Tenancy\ShopScope;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;

/**
 * Chuyển đơn "pending" đã quá hạn sang "expired", trên mọi shop, và báo webhook order.expired.
 *
 * Đơn "partially_paid" giữ nguyên trạng thái để không mất thông tin đã trả một phần.
 * Khóa các đơn trước khi đổi, như RuleMatcher: đơn vừa được khớp thành "paid" không bị đổi lại.
 */
final class ExpireOrders
{
    /** Mỗi lần chạy xử lý tối đa ngần này đơn, để nhịp mỗi phút luôn ngắn; còn thì nhịp sau làm tiếp. */
    private const BATCH = 200;

    public function __construct(
        private readonly WebhookDispatcher $webhooks,
    ) {}

    /** @return int số đơn vừa chuyển sang hết hạn */
    public function __invoke(): int
    {
        return DB::transaction(function (): int {
            $orders = Order::withoutGlobalScope(ShopScope::class)
                ->where('status', OrderStatus::Pending)
                ->where('expires_at', '<=', now())
                ->orderBy('id')
                ->limit(self::BATCH)
                ->lockForUpdate()
                ->get();

            foreach ($orders as $order) {
                $order->update(['status' => OrderStatus::Expired]);
                $this->webhooks->order(WebhookEventType::OrderExpired, $order);
            }

            return $orders->count();
        }, 3);
    }
}
