<?php

namespace App\Webhooks;

use App\Enums\DeliveryTrigger;
use App\Enums\WebhookDeliveryStatus;
use App\Models\WebhookDelivery;
use App\Tenancy\ShopScope;
use Illuminate\Support\Facades\DB;

/**
 * Chạy mỗi phút: gửi các bản ghi webhook đến hạn, trên mọi shop.
 *
 * Nhận việc bằng SELECT … FOR UPDATE SKIP LOCKED rồi dời lịch (giữ chỗ) trước khi gửi,
 * nên hai nhịp chạy chồng nhau không gửi trùng, còn tiến trình chết giữa chừng thì nhịp sau gửi lại.
 */
final class DeliverDueWebhooks
{
    public function __construct(
        private readonly WebhookSender $sender,
    ) {}

    /** @return int số lần đã gửi */
    public function __invoke(): int
    {
        $deadline = hrtime(true) + (int) config('webhooks.tick_budget_seconds') * 1_000_000_000;
        $sent = 0;

        while (hrtime(true) < $deadline) {
            $ids = $this->claim((int) config('webhooks.batch_size'));

            if ($ids === []) {
                break;
            }

            foreach ($ids as $index => $id) {
                if (hrtime(true) >= $deadline) {
                    $this->release(array_slice($ids, $index));

                    break 2;
                }

                $delivery = WebhookDelivery::withoutGlobalScope(ShopScope::class)->find($id);

                if ($delivery?->status === WebhookDeliveryStatus::Pending) {
                    $this->sender->send($delivery, DeliveryTrigger::Automatic);
                    $sent++;
                }
            }
        }

        return $sent;
    }

    /** @return list<int> */
    private function claim(int $limit): array
    {
        return DB::transaction(function () use ($limit): array {
            /** @var list<int> $ids */
            $ids = WebhookDelivery::withoutGlobalScope(ShopScope::class)
                ->where('status', WebhookDeliveryStatus::Pending)
                ->where('next_attempt_at', '<=', now())
                ->orderBy('next_attempt_at')
                ->limit($limit)
                ->lock('for update skip locked')
                ->pluck('id')
                ->all();

            if ($ids !== []) {
                WebhookDelivery::withoutGlobalScope(ShopScope::class)
                    ->whereKey($ids)
                    ->update(['next_attempt_at' => now()->addSeconds((int) config('webhooks.lease_seconds'))]);
            }

            return $ids;
        });
    }

    /**
     * Trả lại các bản ghi đã nhận mà chưa kịp gửi trong nhịp này, để nhịp sau gửi ngay.
     *
     * @param  list<int>  $ids
     */
    private function release(array $ids): void
    {
        WebhookDelivery::withoutGlobalScope(ShopScope::class)
            ->whereKey($ids)
            ->where('status', WebhookDeliveryStatus::Pending)
            ->update(['next_attempt_at' => now()]);
    }
}
