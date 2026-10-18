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
     * @param  string|null  $migrateToChatId  Chat id mới khi nhóm đã nâng cấp thành supergroup (400)
     */
    public function __construct(
        string $message,
        public readonly int $status = 0,
        public readonly ?int $retryAfter = null,
        public readonly ?string $migrateToChatId = null,
    ) {
        parent::__construct($message, $status);
    }

    /**
     * Chat không còn nhận được tin từ bot, gửi lại cũng vô ích: shop nên được ngắt Telegram.
     *
     * Không gồm 401 (token bot sai: lỗi của cả app, tính vào đây thì mọi shop bị ngắt cùng lúc)
     * và các lỗi 400 khác như tin quá dài (lỗi phía app, không phải của chat).
     */
    public function chatUnreachable(): bool
    {
        // Bot bị chặn, bị đuổi khỏi nhóm, tài khoản người nhận đã xóa.
        if ($this->status === 403) {
            return true;
        }

        // Nhóm nâng cấp thành supergroup cũng trả 400 nhưng còn chat mới để chuyển sang: không ngắt.
        return $this->status === 400
            && $this->migrateToChatId === null
            && str_contains($this->getMessage(), 'chat not found');
    }
}
