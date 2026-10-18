<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Shop;
use App\Telegram\TelegramChats;
use App\Telegram\TelegramLinkCodes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Nhận update từ bot Telegram. Trả lời ngay trong phản hồi HTTP ({"method": "sendMessage", ...}),
 * Telegram tự gửi tin đó: không cần gọi Bot API, không cần token trong request này.
 */
class TelegramWebhookController
{
    public function __invoke(Request $request, TelegramLinkCodes $codes): JsonResponse|Response
    {
        $secret = (string) config('services.telegram.webhook_secret');

        if ($secret === '') {
            abort(404);
        }

        if (! hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token', ''))) {
            abort(401);
        }

        // Người dùng chặn bot hoặc đuổi bot khỏi nhóm: ngắt các shop đang gửi vào chat đó.
        $member = $request->input('my_chat_member');

        if (is_array($member) && in_array($member['new_chat_member']['status'] ?? null, ['kicked', 'left'], true)) {
            TelegramChats::disconnect((string) ($member['chat']['id'] ?? ''), 'bot bị chặn hoặc bị đuổi khỏi chat');

            return response()->noContent();
        }

        $chatId = (string) $request->input('message.chat.id', '');
        $text = trim((string) $request->input('message.text', ''));

        // Nhóm vừa nâng cấp thành supergroup: Telegram báo chat id mới, chuyển shop sang trước khi có tin bị từ chối.
        $migrateTo = (string) $request->input('message.migrate_to_chat_id', '');

        if ($chatId !== '' && $migrateTo !== '') {
            TelegramChats::migrate($chatId, $migrateTo);

            return response()->noContent();
        }

        if ($chatId === '' || $text === '') {
            return response()->noContent();
        }

        if (preg_match('/^\/start(?:@\w+)?\s+([A-Za-z0-9_-]{1,64})$/', $text, $match) === 1) {
            return $this->reply($chatId, $this->link($codes, $match[1], $chatId));
        }

        if (preg_match('/^\/start(?:@\w+)?$/', $text) === 1) {
            return $this->reply($chatId, 'Để nhận báo tiền về, mở dashboard của shop, vào "Thông báo Telegram" rồi bấm "Nối Telegram".');
        }

        if (preg_match('/^\/stop(?:@\w+)?$/', $text) === 1) {
            $count = TelegramChats::disconnect($chatId, 'người dùng gõ /stop');

            return $this->reply($chatId, $count > 0 ? 'Đã ngừng báo tiền về vào chat này.' : 'Chat này chưa nối với shop nào.');
        }

        return response()->noContent();
    }

    private function link(TelegramLinkCodes $codes, string $code, string $chatId): string
    {
        $shopId = $codes->consume($code);
        $shop = $shopId === null ? null : Shop::query()->find($shopId);

        if ($shop === null) {
            return 'Mã nối đã hết hạn hoặc đã dùng. Lấy link mới ở dashboard, mục "Thông báo Telegram".';
        }

        $shop->forceFill(['telegram_chat_id' => $chatId])->save();

        AuditLog::record($shop->getKey(), null, 'telegram.connected', $shop, ['chat_id' => $chatId]);

        return "Đã nối với shop {$shop->name}. Tiền về sẽ báo ở đây. Gõ /stop để ngừng.";
    }

    private function reply(string $chatId, string $text): JsonResponse
    {
        return response()->json([
            'method' => 'sendMessage',
            'chat_id' => $chatId,
            'text' => $text,
        ]);
    }
}
