<?php

namespace App\Telegram;

use App\Models\Shop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Mã nối Telegram dùng một lần: dashboard tạo link t.me/<bot>?start=<mã>, bot nhận "/start <mã>".
 * Cache chỉ giữ sha256 của mã, như token lời mời.
 */
class TelegramLinkCodes
{
    public const TTL_MINUTES = 15;

    /** Telegram cho tham số start tối đa 64 ký tự A-Z a-z 0-9 _ -. */
    public const LENGTH = 32;

    public function issue(Shop $shop): string
    {
        $code = Str::random(self::LENGTH);

        Cache::put(self::key($code), $shop->getKey(), now()->addMinutes(self::TTL_MINUTES));

        return $code;
    }

    /** Trả id shop và xóa mã; null khi mã sai, hết hạn hoặc đã dùng. */
    public function consume(string $code): ?int
    {
        $shopId = Cache::pull(self::key($code));

        return is_int($shopId) ? $shopId : null;
    }

    private static function key(string $code): string
    {
        return 'telegram-link:'.hash('sha256', $code);
    }
}
