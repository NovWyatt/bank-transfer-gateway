<?php

namespace App\Payments\Matching;

use App\Enums\MatchedBy;
use App\Enums\OrderStatus;
use App\Enums\TransactionStatus;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Transaction;
use App\Payments\Memo\OrderCodeExtractor;
use Carbon\CarbonImmutable;

/**
 * Khớp giao dịch với đơn bằng quy tắc (mục 5.4 của kế hoạch). Không bao giờ khớp chỉ dựa vào số tiền.
 * Phải chạy trong DB transaction: đơn bị khóa bằng lockForUpdate cho tới khi commit.
 */
final class RuleMatcher
{
    /** Đơn đã hết hạn vẫn nhận tiền trong ngần này giờ sau hạn, kèm cờ paid_after_expiry. */
    public const GRACE_HOURS = 24;

    /**
     * Trả về đơn đã khớp, hoặc null nếu giao dịch vẫn chưa khớp.
     */
    public function match(Transaction $transaction, Shop $shop): ?Order
    {
        $codes = OrderCodeExtractor::candidates(
            $transaction->content,
            [$shop->order_prefix],
            $transaction->provider_code,
        );

        foreach ($codes as $code) {
            // Lọc shop_id tường minh, không chỉ dựa vào global scope.
            $order = Order::query()
                ->where('shop_id', $shop->getKey())
                ->where('code', $code)
                ->lockForUpdate()
                ->first();

            if ($order === null) {
                continue;
            }

            $status = $this->nextStatus($order, $transaction->amount, $transaction->transacted_at);

            if ($status !== null) {
                $this->apply($order, $transaction, $status);

                return $order;
            }
        }

        return null;
    }

    /**
     * Trạng thái mới của đơn khi nhận thêm $amount đồng, hoặc null nếu đơn không được nhận
     * khoản này (giao dịch khi đó ở lại "chưa khớp" để người duyệt xem).
     *
     * Có sẵn: $order->amount, $order->paid_amount, $order->remainingAmount(), $order->status,
     * $order->expires_at, $order->isPastExpiry($paidAt), self::GRACE_HOURS.
     * $paidAt là giờ ngân hàng ghi nhận giao dịch (UTC).
     */
    private function nextStatus(Order $order, int $amount, CarbonImmutable $paidAt): ?OrderStatus
    {
        // Đơn đã đủ tiền (paid, overpaid) hoặc đã hủy: khoản mới để người duyệt xem, không tự cộng.
        if (! $order->status->acceptsPayment()) {
            return null;
        }

        // Quá hạn thì chỉ nhận trong thời gian ân hạn, kể cả đơn còn "pending" vì lệnh hết hạn chưa chạy.
        if ($paidAt->greaterThan($order->expires_at->addHours(self::GRACE_HOURS))) {
            return null;
        }

        $total = $order->paid_amount + $amount;

        return match (true) {
            $total === $order->amount => OrderStatus::Paid,
            $total < $order->amount => OrderStatus::PartiallyPaid,
            default => OrderStatus::Overpaid,
        };
    }

    private function apply(Order $order, Transaction $transaction, OrderStatus $status): void
    {
        $paidAt = $transaction->transacted_at;

        $order->paid_amount += $transaction->amount;
        $order->status = $status;

        if ($order->isPastExpiry($paidAt)) {
            $order->paid_after_expiry = true;
        }

        if ($status->isSettled() && $order->paid_at === null) {
            $order->paid_at = $paidAt;
        }

        $order->save();

        $transaction->fill([
            'order_id' => $order->getKey(),
            'status' => TransactionStatus::Matched,
            'matched_by' => MatchedBy::Rule,
            'matched_at' => now(),
        ])->save();
    }
}
