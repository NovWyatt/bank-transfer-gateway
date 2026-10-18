<?php

namespace App\Telegram;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shop;
use App\Models\Transaction;
use App\Models\WebhookEndpoint;
use App\Support\Vnd;
use Illuminate\Support\Str;

/**
 * Soạn tin báo cho chủ shop rồi xếp hàng gửi (docs/KE-HOACH.md, mục 5.9).
 * Gọi bên trong DB transaction của việc gây ra tin: xem SendTelegramMessage.
 */
class TelegramNotifier
{
    /**
     * Tiền vào, đã khớp hay chưa. Ví dụ: "+150.000đ · DH7K3QXM · đã thanh toán".
     */
    public function transaction(Shop $shop, Transaction $transaction, ?Order $order): void
    {
        $parts = ['+'.Vnd::format($transaction->amount)];

        if ($order === null) {
            $parts[] = 'chưa khớp';
            $parts[] = '"'.Str::limit($transaction->content, 80).'"';
        } else {
            $parts[] = $order->code;
            $parts[] = $this->orderOutcome($order);
        }

        $this->queue($shop, implode(' · ', $parts));
    }

    public function endpointDisabled(WebhookEndpoint $endpoint): void
    {
        $shop = Shop::query()->find($endpoint->shop_id);

        if ($shop !== null) {
            $this->queue($shop, "Webhook đã tự tắt: {$endpoint->url}\n{$endpoint->disabled_reason}\nBật lại ở dashboard sau khi sửa phía nhận.");
        }
    }

    public function test(Shop $shop): void
    {
        $this->queue($shop, "Tin thử từ Bank Transfer Gateway cho shop {$shop->name}. Tiền về sẽ báo ở đây.");
    }

    private function orderOutcome(Order $order): string
    {
        return match ($order->status) {
            OrderStatus::Paid => 'đã thanh toán',
            OrderStatus::Overpaid => 'trả dư '.Vnd::format($order->paid_amount - $order->amount),
            OrderStatus::PartiallyPaid => 'còn thiếu '.Vnd::format($order->amount - $order->paid_amount),
            default => mb_strtolower($order->status->label()),
        };
    }

    private function queue(Shop $shop, string $text): void
    {
        if ($shop->telegram_chat_id !== null) {
            SendTelegramMessage::dispatch($shop->getKey(), $text);
        }
    }
}
