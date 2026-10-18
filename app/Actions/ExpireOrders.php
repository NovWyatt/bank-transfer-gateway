<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Tenancy\ShopScope;

/**
 * Chuyển đơn "pending" đã quá hạn sang "expired", trên mọi shop.
 *
 * Đơn "partially_paid" giữ nguyên trạng thái để không mất thông tin đã trả một phần.
 * An toàn khi chạy cùng lúc với bộ khớp: UPDATE xét lại điều kiện status trên dòng đã khóa,
 * nên đơn vừa được khớp thành "paid" sẽ không bị đổi.
 */
final class ExpireOrders
{
    /** @return int số đơn vừa chuyển sang hết hạn */
    public function __invoke(): int
    {
        return Order::withoutGlobalScope(ShopScope::class)
            ->where('status', OrderStatus::Pending)
            ->where('expires_at', '<=', now())
            ->update(['status' => OrderStatus::Expired]);
    }
}
