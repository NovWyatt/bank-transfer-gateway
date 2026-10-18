<?php

namespace App\Telegram;

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
            // Nhóm đã thành supergroup: chuyển shop sang chat mới rồi gửi lại ngay. Hỏng nữa thì hàng đợi thử lại với chat mới.
            if ($e->migrateToChatId !== null) {
                TelegramChats::migrate($chatId, $e->migrateToChatId);
                $telegram->sendMessage($e->migrateToChatId, $this->text);

                return;
            }

            if ($e->chatUnreachable()) {
                if (TelegramChats::disconnect($chatId, $e->getMessage()) > 0) {
                    Log::info('Ngắt Telegram vì chat không nhận tin được.', ['shop_id' => $this->shopId, 'error' => $e->getMessage()]);
                }

                return;
            }

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        }
    }
}
