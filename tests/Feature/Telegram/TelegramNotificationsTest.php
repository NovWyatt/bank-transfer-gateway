<?php

use App\Enums\Provider;
use App\Models\AuditLog;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\ProviderConnection;
use App\Models\Shop;
use App\Models\WebhookEndpoint;
use App\Payments\Providers\SePaySignature;
use App\Telegram\SendTelegramMessage;
use App\Telegram\TelegramClient;
use App\Telegram\TelegramError;
use App\Telegram\TelegramNotifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

/*
| Tin báo tiền về: soạn đúng mẫu ở mục 5.9, xếp hàng cùng transaction, gửi bằng Bot API.
*/

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['services.telegram.bot_token' => '123:token-test']);

    $this->shop = Shop::factory()->create(['telegram_chat_id' => '5550001']);
    $this->account = BankAccount::factory()->default()->for($this->shop)->create(['account_number' => '1017588888']);
    $this->connection = ProviderConnection::factory()->for($this->shop)
        ->provider(Provider::Simulator)
        ->create(['credentials' => ['secret' => 'secret-gia-lap']]);
    $this->order = Order::factory()->for($this->shop)->create([
        'bank_account_id' => $this->account->getKey(),
        'amount' => 150_000,
    ]);
});

/** @return list<string> */
function queuedTelegramTexts(): array
{
    return Queue::pushed(SendTelegramMessage::class)->map(fn (SendTelegramMessage $job): string => $job->text)->values()->all();
}

it('soạn tin theo mẫu cho từng kết quả khớp', function (): void {
    Queue::fake();
    $code = $this->order->code;
    $second = Order::factory()->for($this->shop)->create(['bank_account_id' => $this->account->getKey(), 'amount' => 100_000]);
    $third = Order::factory()->for($this->shop)->create(['bank_account_id' => $this->account->getKey(), 'amount' => 80_000]);

    $this->postSigned($this->connection, ['content' => "MBVCB.123.{$code}.CT", 'transferAmount' => 150_000, 'code' => null]);
    $this->postSigned($this->connection, ['content' => $second->code, 'transferAmount' => 40_000, 'code' => null]);
    $this->postSigned($this->connection, ['content' => $third->code, 'transferAmount' => 90_000, 'code' => null]);
    $this->postSigned($this->connection, ['content' => 'chuyen tien ao', 'transferAmount' => 200_000, 'code' => null]);

    expect(queuedTelegramTexts())->toBe([
        "+150.000đ · {$code} · đã thanh toán",
        "+40.000đ · {$second->code} · còn thiếu 60.000đ",
        "+90.000đ · {$third->code} · trả dư 10.000đ",
        '+200.000đ · chưa khớp · "chuyen tien ao"',
    ]);
});

it('không xếp tin khi shop chưa nối Telegram, và không báo lần hai khi nhà cung cấp gửi lại', function (): void {
    Queue::fake();

    $body = $this->sepayPayload(['content' => $this->order->code, 'transferAmount' => 150_000, 'code' => null]);
    $headers = SePaySignature::headers($body, 'secret-gia-lap', now()->getTimestamp());
    $this->postWebhook($this->connection, $body, $headers);
    $this->postWebhook($this->connection, $body, $headers);

    expect(queuedTelegramTexts())->toHaveCount(1);

    $this->shop->forceFill(['telegram_chat_id' => null])->save();
    $this->postSigned($this->connection, ['content' => 'khong co ma', 'transferAmount' => 1_000, 'code' => null]);

    expect(queuedTelegramTexts())->toHaveCount(1);
});

it('job gửi tin tới chat đang nối của shop', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true, 'result' => ['message_id' => 1]])]);

    SendTelegramMessage::dispatchSync($this->shop->getKey(), '+150.000đ · DH7K3QXM · đã thanh toán');

    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://api.telegram.org/bot123:token-test/sendMessage'
        && $request['chat_id'] === '5550001'
        && $request['text'] === '+150.000đ · DH7K3QXM · đã thanh toán');
});

it('job bỏ qua khi shop đã ngắt Telegram sau lúc xếp hàng', function (): void {
    Http::fake();
    $this->shop->forceFill(['telegram_chat_id' => null])->save();

    SendTelegramMessage::dispatchSync($this->shop->getKey(), 'tin cũ');

    Http::assertNothingSent();
});

it('Telegram bảo chờ thì job xếp lại đúng số giây', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response([
        'ok' => false, 'error_code' => 429, 'description' => 'Too Many Requests: retry after 17', 'parameters' => ['retry_after' => 17],
    ], 429)]);

    $job = (new SendTelegramMessage($this->shop->getKey(), 'tin'))->withFakeQueueInteractions();
    $job->handle(app(TelegramClient::class));

    $job->assertReleased(delay: 17);
});

it('lỗi tạm thời thì job ném lỗi để thử lại, và thông điệp không chứa token', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => false, 'error_code' => 502, 'description' => 'Bad Gateway'], 502)]);

    $job = new SendTelegramMessage($this->shop->getKey(), 'tin');

    expect(fn () => $job->handle(app(TelegramClient::class)))
        ->toThrow(fn (TelegramError $e) => expect($e->getMessage())->toContain('Bad Gateway')->not->toContain('token-test'));

    expect($this->shop->fresh()->telegram_chat_id)->toBe('5550001');
});

it('chat không còn nhận tin thì ngắt Telegram của shop và ghi nhật ký', function (): void {
    Http::fake(['api.telegram.org/*' => Http::response([
        'ok' => false, 'error_code' => 403, 'description' => 'Forbidden: bot was blocked by the user',
    ], 403)]);

    $job = (new SendTelegramMessage($this->shop->getKey(), 'tin'))->withFakeQueueInteractions();
    $job->handle(app(TelegramClient::class));

    $job->assertNotFailed();
    expect($this->shop->fresh()->telegram_chat_id)->toBeNull()
        ->and(AuditLog::query()->where('action', 'telegram.disconnected')->exists())->toBeTrue();
})->skip('Chờ TelegramError::chatUnreachable()');

it('tin báo endpoint tự tắt có URL và lý do', function (): void {
    Queue::fake();
    $endpoint = WebhookEndpoint::factory()->for($this->shop)->create(['url' => 'https://shop.example/hooks']);
    $endpoint->forceFill(['active' => false, 'disabled_reason' => 'Tự tắt sau 20 lần gửi hỏng liên tiếp.'])->save();

    app(TelegramNotifier::class)->endpointDisabled($endpoint);

    expect(queuedTelegramTexts()[0])->toContain('https://shop.example/hooks')->toContain('20 lần');
});
