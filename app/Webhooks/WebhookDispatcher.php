<?php

namespace App\Webhooks;

use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Http\Resources\V1\OrderResource;
use App\Http\Resources\V1\TransactionResource;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Ghi sự kiện vào hàng chờ gửi (outbox). Không gọi mạng.
 *
 * Gọi trong cùng DB transaction với việc đổi trạng thái: rollback thì bản ghi gửi cũng mất,
 * nên không có webhook báo một thay đổi chưa từng được lưu.
 */
final class WebhookDispatcher
{
    /** @return int số bản ghi gửi đã tạo (một cho mỗi endpoint đăng ký) */
    public function order(WebhookEventType $type, Order $order, ?Transaction $transaction = null): int
    {
        $endpoints = $this->endpointsFor($order->shop_id, $type);

        if ($endpoints->isEmpty()) {
            return 0;
        }

        $order->loadMissing(['bankAccount', 'shop']);
        $transaction?->setRelation('order', $order)->loadMissing('bankAccount');

        return $this->record($order->shop_id, $endpoints, $type, [
            'order' => (new OrderResource($order))->resolve(),
            'transaction' => $transaction === null ? null : (new TransactionResource($transaction))->resolve(),
        ]);
    }

    public function transaction(WebhookEventType $type, Transaction $transaction): int
    {
        $endpoints = $this->endpointsFor($transaction->shop_id, $type);

        if ($endpoints->isEmpty()) {
            return 0;
        }

        $transaction->loadMissing(['order', 'bankAccount']);

        return $this->record($transaction->shop_id, $endpoints, $type, [
            'order' => null,
            'transaction' => (new TransactionResource($transaction))->resolve(),
        ]);
    }

    /**
     * Sự kiện thử cho đúng một endpoint, kể cả đang tắt. Không vào lịch gửi tự động:
     * người gọi gửi ngay bằng WebhookSender.
     */
    public function ping(WebhookEndpoint $endpoint): WebhookDelivery
    {
        [$eventId, $payload] = $this->encode(WebhookEventType::Ping, [
            'message' => 'Webhook thử từ cổng nhận chuyển khoản. Kiểm chữ ký rồi trả 2xx là xong.',
            'endpoint' => ['id' => $endpoint->getKey(), 'url' => $endpoint->url],
        ]);

        return WebhookDelivery::query()->create([
            'shop_id' => $endpoint->shop_id,
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event_id' => $eventId,
            'event_type' => WebhookEventType::Ping,
            'payload' => $payload,
            'status' => WebhookDeliveryStatus::Pending,
        ]);
    }

    /** @return Collection<int, WebhookEndpoint> */
    private function endpointsFor(int $shopId, WebhookEventType $type): Collection
    {
        // Lọc shop tường minh: lúc này có thể chưa đặt shop hiện tại (đường nhận webhook, lệnh theo lịch).
        return WebhookEndpoint::query()
            ->where('shop_id', $shopId)
            ->where('active', true)
            ->whereJsonContains('events', $type->value)
            ->get();
    }

    /**
     * @param  Collection<int, WebhookEndpoint>  $endpoints
     * @param  array<string, mixed>  $data
     */
    private function record(int $shopId, Collection $endpoints, WebhookEventType $type, array $data): int
    {
        // Một sự kiện, một id và một payload cho mọi endpoint: phía shop bỏ trùng theo id.
        [$eventId, $payload] = $this->encode($type, $data);

        foreach ($endpoints as $endpoint) {
            WebhookDelivery::query()->create([
                'shop_id' => $shopId,
                'webhook_endpoint_id' => $endpoint->getKey(),
                'event_id' => $eventId,
                'event_type' => $type,
                'payload' => $payload,
                'status' => WebhookDeliveryStatus::Pending,
                'next_attempt_at' => now(),
            ]);
        }

        return $endpoints->count();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{string, string} id sự kiện và JSON đã mã hóa
     */
    private function encode(WebhookEventType $type, array $data): array
    {
        $eventId = 'evt_'.Str::ulid();

        $payload = json_encode([
            'id' => $eventId,
            'type' => $type->value,
            'created_at' => now()->toIso8601ZuluString(),
            'data' => $data,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return [$eventId, $payload];
    }
}
