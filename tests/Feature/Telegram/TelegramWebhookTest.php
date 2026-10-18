<?php

use App\Models\AuditLog;
use App\Models\Shop;
use App\Telegram\TelegramLinkCodes;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

/*
| Update từ bot: nối chat bằng "/start <mã>", ngắt bằng /stop hoặc khi người dùng chặn bot.
| Bot trả lời ngay trong phản hồi HTTP nên không có request nào ra api.telegram.org.
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.telegram.webhook_secret' => 'tg-secret-test']);

    $this->shop = Shop::factory()->create(['name' => 'Tiệm Cô Ba']);
});

/**
 * @param  array<string, mixed>  $update
 */
function telegramUpdate(array $update, ?string $secret = 'tg-secret-test'): TestResponse
{
    $headers = $secret === null ? [] : ['X-Telegram-Bot-Api-Secret-Token' => $secret];

    return test()->postJson('/telegram/webhook', $update, $headers);
}

/** @return array<string, mixed> */
function textMessage(string $text, int $chatId = 5550001): array
{
    return [
        'update_id' => random_int(1, 1_000_000),
        'message' => [
            'message_id' => 1,
            'chat' => ['id' => $chatId, 'type' => $chatId < 0 ? 'group' : 'private'],
            'date' => now()->getTimestamp(),
            'text' => $text,
        ],
    ];
}

it('nối chat với shop bằng mã một lần và trả lời trong phản hồi', function (): void {
    $code = app(TelegramLinkCodes::class)->issue($this->shop);

    telegramUpdate(textMessage("/start {$code}"))
        ->assertOk()
        ->assertJson([
            'method' => 'sendMessage',
            'chat_id' => '5550001',
            'text' => 'Đã nối với shop Tiệm Cô Ba. Tiền về sẽ báo ở đây. Gõ /stop để ngừng.',
        ]);

    expect($this->shop->fresh()->telegram_chat_id)->toBe('5550001')
        ->and(AuditLog::query()->where('action', 'telegram.connected')->exists())->toBeTrue();

    // Mã đã dùng thì không dùng lại được, kể cả từ chat khác.
    telegramUpdate(textMessage("/start {$code}", 777))->assertJsonPath('text', fn (string $text): bool => str_contains($text, 'hết hạn hoặc đã dùng'));
    expect($this->shop->fresh()->telegram_chat_id)->toBe('5550001');
});

it('nối được vào nhóm bằng lệnh có tên bot', function (): void {
    $code = app(TelegramLinkCodes::class)->issue($this->shop);

    telegramUpdate(textMessage("/start@btg_test_bot {$code}", -100123))->assertOk();

    expect($this->shop->fresh()->telegram_chat_id)->toBe('-100123');
});

it('mã hết hạn sau 15 phút', function (): void {
    $code = app(TelegramLinkCodes::class)->issue($this->shop);
    $this->travel(TelegramLinkCodes::TTL_MINUTES + 1)->minutes();

    telegramUpdate(textMessage("/start {$code}"))->assertJsonPath('text', fn (string $text): bool => str_contains($text, 'hết hạn'));

    expect($this->shop->fresh()->telegram_chat_id)->toBeNull();
});

it('/start không kèm mã thì chỉ đường, tin khác thì bỏ qua', function (): void {
    telegramUpdate(textMessage('/start'))->assertJsonPath('text', fn (string $text): bool => str_contains($text, 'Nối Telegram'));
    telegramUpdate(textMessage('xin chào'))->assertNoContent();
    telegramUpdate(['update_id' => 1, 'edited_message' => ['text' => 'x']])->assertNoContent();
});

it('/stop ngắt mọi shop đang gửi vào chat đó', function (): void {
    $other = Shop::factory()->create(['telegram_chat_id' => '5550001']);
    $this->shop->forceFill(['telegram_chat_id' => '5550001'])->save();
    $unrelated = Shop::factory()->create(['telegram_chat_id' => '999']);

    telegramUpdate(textMessage('/stop'))->assertJsonPath('text', 'Đã ngừng báo tiền về vào chat này.');

    expect($this->shop->fresh()->telegram_chat_id)->toBeNull()
        ->and($other->fresh()->telegram_chat_id)->toBeNull()
        ->and($unrelated->fresh()->telegram_chat_id)->toBe('999');
});

it('nhóm nâng cấp thành supergroup thì chuyển shop sang chat id mới', function (): void {
    $this->shop->forceFill(['telegram_chat_id' => '-4001'])->save();

    telegramUpdate([
        'update_id' => 3,
        'message' => [
            'message_id' => 9,
            'chat' => ['id' => -4001, 'type' => 'group'],
            'date' => now()->getTimestamp(),
            'migrate_to_chat_id' => -1009876543210,
        ],
    ])->assertNoContent();

    expect($this->shop->fresh()->telegram_chat_id)->toBe('-1009876543210');
});

it('người dùng chặn bot thì ngắt chat đó', function (): void {
    $this->shop->forceFill(['telegram_chat_id' => '5550001'])->save();

    telegramUpdate([
        'update_id' => 2,
        'my_chat_member' => [
            'chat' => ['id' => 5550001, 'type' => 'private'],
            'new_chat_member' => ['status' => 'kicked', 'user' => ['id' => 1, 'is_bot' => true]],
        ],
    ])->assertNoContent();

    expect($this->shop->fresh()->telegram_chat_id)->toBeNull();
});

it('từ chối update thiếu hoặc sai secret, và tắt hẳn khi chưa đặt secret', function (): void {
    $code = app(TelegramLinkCodes::class)->issue($this->shop);

    telegramUpdate(textMessage("/start {$code}"), secret: null)->assertUnauthorized();
    telegramUpdate(textMessage("/start {$code}"), secret: 'sai')->assertUnauthorized();

    config(['services.telegram.webhook_secret' => null]);
    telegramUpdate(textMessage("/start {$code}"))->assertNotFound();

    expect($this->shop->fresh()->telegram_chat_id)->toBeNull();
});
