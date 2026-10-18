<?php

namespace App\Telegram;

use App\Models\AuditLog;
use App\Models\Shop;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Gửi một tin cho chat Telegram đã nối của shop. Đọc chat_id lúc gửi, không lúc xếp hàng:
 * shop vừa ngắt Telegram thì tin còn trong hàng không đi nữa.
 *
 * Xếp hàng bằng driver database, không bật after_commit: dòng của bảng jobs ghi trong cùng
 * DB transaction với giao dịch, nên transaction chạy lại sau deadlock không để lại tin thừa.
 */
class SendTelegramMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [30, 120];

    public function __construct(
        public readonly int $shopId,
        public readonly string $text,
    ) {}

    public function handle(TelegramClient $telegram): void
    {
        $chatId = Shop::query()->whereKey($this->shopId)->value('telegram_chat_id');

        if (! is_string($chatId) || ! $telegram->configured()) {
            return;
        }

        try {
            $telegram->sendMessage($chatId, $this->text);
        } catch (TelegramError $e) {
            if ($e->chatUnreachable()) {
                $this->disconnect($chatId, $e);

                return;
            }

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        }
    }

    private function disconnect(string $chatId, TelegramError $e): void
    {
        // Chỉ ngắt nếu shop vẫn nối đúng chat đó: có thể shop đã nối lại sang chat khác.
        $changed = Shop::query()
            ->whereKey($this->shopId)
            ->where('telegram_chat_id', $chatId)
            ->update(['telegram_chat_id' => null]);

        if ($changed > 0) {
            AuditLog::record($this->shopId, null, 'telegram.disconnected', meta: ['reason' => $e->getMessage()]);
            Log::info('Ngắt Telegram của shop vì chat không nhận tin được.', ['shop_id' => $this->shopId, 'error' => $e->getMessage()]);
        }
    }
}
