<?php

namespace App\Telegram;

use RuntimeException;

/**
 * Lỗi khi gọi Bot API. Thông điệp không bao giờ chứa token: token nằm trong URL của Bot API.
 */
final class TelegramError extends RuntimeException
{
    /**
     * @param  int  $status  Mã HTTP của Telegram, 0 khi không kết nối được
     * @param  int|null  $retryAfter  Số giây Telegram bảo chờ khi bị giới hạn tần suất (429)
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?int $retryAfter = null,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Chat không còn nhận được tin từ bot, gửi lại cũng vô ích: shop nên được ngắt Telegram.
     */
    public function chatUnreachable(): bool
    {
        // TODO(human)
        return false;
    }
}
