<?php

namespace App\Telegram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Gọi Telegram Bot API. Token nằm trong đường dẫn URL (api.telegram.org/bot<token>/...),
 * nên mọi thông điệp lỗi đi ra ngoài lớp này đều đã xóa token.
 */
class TelegramClient
{
    private const BASE_URL = 'https://api.telegram.org';

    public function __construct(
        private readonly ?string $token,
        private readonly ?string $username,
    ) {}

    public function configured(): bool
    {
        return filled($this->token);
    }

    /** Tên bot không có @, hoặc null khi chưa đặt TELEGRAM_BOT_USERNAME. */
    public function username(): ?string
    {
        return filled($this->username) ? ltrim((string) $this->username, '@') : null;
    }

    public function sendMessage(string $chatId, string $text): void
    {
        $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
            'link_preview_options' => ['is_disabled' => true],
        ]);
    }

    /**
     * Đăng ký URL nhận update. Chỉ nhận tin nhắn và thay đổi quyền của bot trong chat
     * (để biết khi người dùng chặn bot).
     */
    public function setWebhook(string $url, string $secret): void
    {
        $this->call('setWebhook', [
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message', 'my_chat_member'],
            'drop_pending_updates' => true,
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function call(string $method, array $params): mixed
    {
        if (! $this->configured()) {
            throw new TelegramError('Chưa đặt TELEGRAM_BOT_TOKEN.');
        }

        try {
            $response = Http::timeout(10)
                ->connectTimeout(5)
                ->asJson()
                ->post(self::BASE_URL.'/bot'.$this->token.'/'.$method, $params);
        } catch (ConnectionException $e) {
            throw new TelegramError('Không kết nối được Telegram: '.$this->redact($e->getMessage()));
        }

        $body = $response->json();

        if ($response->successful() && is_array($body) && ($body['ok'] ?? false) === true) {
            return $body['result'] ?? null;
        }

        $description = is_array($body) && is_string($body['description'] ?? null)
            ? $body['description']
            : 'HTTP '.$response->status();
        $retryAfter = is_array($body) ? ($body['parameters']['retry_after'] ?? null) : null;
        $migrateTo = is_array($body) ? ($body['parameters']['migrate_to_chat_id'] ?? null) : null;

        throw new TelegramError(
            "Telegram từ chối {$method}: ".$this->redact($description),
            $response->status(),
            is_int($retryAfter) ? $retryAfter : null,
            is_int($migrateTo) || is_string($migrateTo) ? (string) $migrateTo : null,
        );
    }

    private function redact(string $message): string
    {
        return str_replace((string) $this->token, '***', $message);
    }
}
