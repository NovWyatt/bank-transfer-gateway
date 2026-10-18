<?php

namespace App\Actions;

use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Hủy đơn: từ lúc này tiền chuyển vào đơn để "chưa khớp" cho người duyệt xem.
 * Tiền đã nhận trước đó (đơn trả thiếu) shop tự hoàn cho khách.
 */
final class CancelOrder
{
    /**
     * @throws DomainException khi đơn không còn nhận tiền (đã đủ tiền hoặc đã hủy)
     */
    public function handle(Order $order, User $by, ?string $ip = null): Order
    {
        return DB::transaction(function () use ($order, $by, $ip): Order {
            // Khóa như RuleMatcher, để khoản tiền đến cùng lúc không cộng vào đơn vừa hủy.
            $locked = Order::query()
                ->where('shop_id', $order->shop_id)
                ->whereKey($order->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->acceptsPayment()) {
                throw new DomainException("Đơn {$locked->code} đang ở trạng thái \"{$locked->status->label()}\", không hủy được.");
            }

            $from = $locked->status;
            $locked->update(['status' => OrderStatus::Cancelled]);

            AuditLog::record($locked->shop_id, $by, 'order.cancelled', $locked, [
                'from' => $from->value,
                'paid_amount' => $locked->paid_amount,
            ], $ip);

            return $locked;
        }, 3);
    }
}
