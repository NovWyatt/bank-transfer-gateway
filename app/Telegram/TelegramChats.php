<?php

namespace App\Telegram;

use App\Models\AuditLog;
use App\Models\Shop;

/**
 * Đổi chat đã nối của mọi shop đang gửi vào một chat. Một chat có thể nối với nhiều shop
 * (một người làm chủ vài shop), nên luôn cập nhật theo chat_id, không theo shop.
 */
final class TelegramChats
{
    /** Ngắt Telegram của các shop đang gửi vào chat này. Trả số shop đã ngắt. */
    public static function disconnect(string $chatId, string $reason): int
    {
        return self::reassign($chatId, null, 'telegram.disconnected', ['reason' => $reason]);
    }

    /** Nhóm nâng cấp thành supergroup thì Telegram đổi chat id: chuyển các shop sang id mới. */
    public static function migrate(string $fromChatId, string $toChatId): int
    {
        return self::reassign($fromChatId, $toChatId, 'telegram.migrated', ['from' => $fromChatId, 'to' => $toChatId]);
    }

    /**
     * @param  array<string, string>  $meta
     */
    private static function reassign(string $chatId, ?string $newChatId, string $action, array $meta): int
    {
        if ($chatId === '') {
            return 0;
        }

        $shops = Shop::query()->where('telegram_chat_id', $chatId)->get();

        foreach ($shops as $shop) {
            $shop->forceFill(['telegram_chat_id' => $newChatId])->save();
            AuditLog::record($shop->getKey(), null, $action, $shop, $meta);
        }

        return $shops->count();
    }
}
