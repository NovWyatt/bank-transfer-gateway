<?php

use App\Actions\PruneOldRecords;
use App\Enums\DeliveryTrigger;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Models\IdempotencyKey;
use App\Models\Order;
use App\Models\Shop;
use App\Models\WebhookAttempt;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Telegram\SendTelegramMessage;
use App\Webhooks\DeliverDueWebhooks;
use App\Webhooks\Security\HostResolver;
use App\Webhooks\WebhookDispatcher;
use App\Webhooks\WebhookSender;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Support\StaticHostResolver;

/*
| Gửi webhook: chữ ký, lịch gửi lại, nhật ký, tự tắt endpoint, chặn SSRF. Không gọi mạng hay DNS thật.
*/

beforeEach(function (): void {
    Http::preventStrayRequests();

    $this->dns = new StaticHostResolver(['shop.example' => ['93.184.216.34']]);
    app()->instance(HostResolver::class, $this->dns);

    $this->shop = Shop::factory()->create();
    $this->endpoint = WebhookEndpoint::factory()->for($this->shop)->create();
    $this->order = Order::factory()->for($this->shop)->create(['amount' => 150_000]);

    app(WebhookDispatcher::class)->order(WebhookEventType::OrderPaid, $this->order);
    $this->delivery = WebhookDelivery::sole();
});

function deliverDue(): int
{
    return app(DeliverDueWebhooks::class)();
}

it('gửi body nguyên văn kèm chữ ký mà script mẫu của shop kiểm được', function (): void {
    require_once base_path('examples/webhooks/php/verify-signature.php');
    Http::fake(['https://shop.example/*' => Http::response('ok')]);

    expect(deliverDue())->toBe(1);

    Http::assertSent(function (Request $request): bool {
        $secret = (string) $this->endpoint->secret;

        return $request->url() === 'https://shop.example/webhooks/gateway'
            && $request->body() === $this->delivery->payload
            && $request->header('Content-Type')[0] === 'application/json'
            && $request->header('X-Gateway-Event-Id')[0] === $this->delivery->event_id
            && $request->header('X-Gateway-Event-Type')[0] === 'order.paid'
            && gateway_verify_signature($request->body(), $request->header('X-Gateway-Signature')[0], $secret)
            && ! gateway_verify_signature($request->body(), $request->header('X-Gateway-Signature')[0], 'whsec_sai');
    });

    $delivery = $this->delivery->fresh();
    $attempt = WebhookAttempt::sole();

    expect($delivery->status)->toBe(WebhookDeliveryStatus::Succeeded)
        ->and($delivery->attempt_count)->toBe(1)
        ->and($delivery->next_attempt_at)->toBeNull()
        ->and($delivery->delivered_at)->not->toBeNull()
        ->and($attempt->trigger)->toBe(DeliveryTrigger::Automatic)
        ->and($attempt->response_code)->toBe(200)
        ->and($attempt->response_body)->toBe('ok')
        ->and($this->endpoint->fresh()->last_success_at)->not->toBeNull();
});

it('gửi lại sau 1, 5, 15, 60, 180, 360, 720 phút rồi dừng ở "thất bại"', function (): void {
    $this->freezeSecond();
    Http::fake(['*' => Http::response('Lỗi máy chủ', 500)]);

    $delays = [];

    for ($attempt = 1; $attempt <= 8; $attempt++) {
        expect(deliverDue())->toBe(1);

        $delivery = $this->delivery->fresh();
        expect($delivery->attempt_count)->toBe($attempt);

        if ($delivery->next_attempt_at === null) {
            break;
        }

        $delays[] = (int) now()->diffInMinutes($delivery->next_attempt_at);

        // Chưa tới hạn thì nhịp mỗi phút không gửi.
        $this->travel($delays[array_key_last($delays)] - 1)->minutes();
        if (end($delays) > 1) {
            expect(deliverDue())->toBe(0);
        }

        $this->travel(1)->minutes();
    }

    $delivery = $this->delivery->fresh();

    expect($delays)->toBe([1, 5, 15, 60, 180, 360, 720])
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Failed)
        ->and($delivery->attempt_count)->toBe(8)
        ->and($delivery->last_response_code)->toBe(500)
        ->and(WebhookAttempt::count())->toBe(8)
        ->and($this->endpoint->fresh()->consecutive_failures)->toBe(8);

    Http::assertSentCount(8);
});

it('3xx tính là hỏng và không đi theo chuyển hướng', function (): void {
    Http::fake([
        'https://shop.example/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/latest/meta-data']),
    ]);

    deliverDue();

    Http::assertSentCount(1);
    expect($this->delivery->fresh())
        ->status->toBe(WebhookDeliveryStatus::Pending)
        ->last_response_code->toBe(302);
});

it('lỗi kết nối được ghi vào nhật ký và vẫn gửi lại theo lịch', function (): void {
    Http::fake(['*' => Http::failedConnection('cURL error 28: Operation timed out after 10000 milliseconds')]);

    deliverDue();

    expect(WebhookAttempt::sole())
        ->response_code->toBeNull()
        ->error->toContain('timed out')
        ->and($this->delivery->fresh()->status)->toBe(WebhookDeliveryStatus::Pending);
});

it('tên miền đổi sang IP nội bộ thì không gửi, ghi lý do và vẫn gửi lại sau', function (): void {
    Http::fake();
    $this->dns->set('shop.example', ['10.0.0.8']);

    deliverDue();

    Http::assertNothingSent();
    expect(WebhookAttempt::sole()->error)->toContain('URL bị chặn')->toContain('10.0.0.8')
        ->and($this->delivery->fresh()->status)->toBe(WebhookDeliveryStatus::Pending);
});

it('nhật ký giữ 2 KB đầu của phản hồi, cắt đúng ranh giới ký tự UTF-8', function (): void {
    Http::fake(['*' => Http::response(str_repeat('ă', 3000), 422)]);

    deliverDue();

    $body = (string) WebhookAttempt::sole()->response_body;

    expect(strlen($body))->toBeLessThanOrEqual(2048)->toBeGreaterThan(2040)
        ->and(mb_check_encoding($body, 'UTF-8'))->toBeTrue();
});

it('tự tắt endpoint khi hỏng liên tiếp đủ số lần và đủ lâu, rồi báo Telegram một lần', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response('', 503)]);
    $this->shop->forceFill(['telegram_chat_id' => '5550001'])->save();
    $this->endpoint->forceFill(['consecutive_failures' => 19, 'failing_since' => now()->subHours(25)])->save();

    deliverDue();

    $endpoint = $this->endpoint->fresh();

    expect($endpoint->active)->toBeFalse()
        ->and($endpoint->consecutive_failures)->toBe(20)
        ->and($endpoint->disabled_at)->not->toBeNull()
        ->and($endpoint->disabled_reason)->toContain('20 lần');
    Queue::assertPushed(SendTelegramMessage::class, fn (SendTelegramMessage $job): bool => str_contains($job->text, 'Webhook đã tự tắt'));

    // Gửi tay lúc đã tắt vẫn hỏng: không báo lại.
    app(WebhookSender::class)->send($this->delivery->fresh(), DeliveryTrigger::Manual);
    Queue::assertPushed(SendTelegramMessage::class, 1);
});

it('một đợt sập ngắn không làm tắt endpoint dù hỏng nhiều lần', function (): void {
    Queue::fake();
    Http::fake(['*' => Http::response('', 503)]);
    $this->shop->forceFill(['telegram_chat_id' => '5550001'])->save();
    $this->endpoint->forceFill(['consecutive_failures' => 50, 'failing_since' => now()->subHours(2)])->save();

    deliverDue();

    expect($this->endpoint->fresh()->active)->toBeTrue();
    Queue::assertNothingPushed();
});

it('gửi thành công thì xóa chuỗi hỏng', function (): void {
    Http::fake(['*' => Http::response('', 204)]);
    $this->endpoint->forceFill(['consecutive_failures' => 7, 'failing_since' => now()->subHour()])->save();

    deliverDue();

    expect($this->endpoint->fresh())
        ->consecutive_failures->toBe(0)
        ->failing_since->toBeNull();
});

it('endpoint đã tắt thì nhịp tự động bỏ qua, không gửi', function (): void {
    Http::fake();
    $this->endpoint->update(['active' => false]);

    deliverDue();

    Http::assertNothingSent();
    expect($this->delivery->fresh())
        ->status->toBe(WebhookDeliveryStatus::Skipped)
        ->next_attempt_at->toBeNull();
});

it('gửi tay bản ghi đã thất bại: thành công thì đổi trạng thái, không tính vào lịch gửi lại', function (): void {
    Http::fake(['*' => Http::sequence()->push('', 500)->push('đã nhận', 200)]);
    $this->delivery->update(['status' => WebhookDeliveryStatus::Failed, 'next_attempt_at' => null, 'attempt_count' => 8]);

    $sender = app(WebhookSender::class);

    expect($sender->send($this->delivery, DeliveryTrigger::Manual)->succeeded())->toBeFalse()
        ->and($this->delivery->fresh()->status)->toBe(WebhookDeliveryStatus::Failed);

    expect($sender->send($this->delivery->fresh(), DeliveryTrigger::Manual)->succeeded())->toBeTrue();

    expect($this->delivery->fresh())
        ->status->toBe(WebhookDeliveryStatus::Succeeded)
        ->attempt_count->toBe(8);
});

it('bản ghi đang được nhịp khác giữ chỗ thì không gửi trùng', function (): void {
    Http::fake();
    $this->delivery->update(['next_attempt_at' => now()->addSeconds(120)]);

    expect(deliverDue())->toBe(0);
    Http::assertNothingSent();
});

it('hết ngân sách thời gian của nhịp thì để dành cho nhịp sau', function (): void {
    Http::fake();
    config(['webhooks.tick_budget_seconds' => 0]);

    expect(deliverDue())->toBe(0)
        ->and($this->delivery->fresh()->status)->toBe(WebhookDeliveryStatus::Pending);
});

it('dọn bản ghi gửi đã xong quá 30 ngày và khóa idempotency hết hạn', function (): void {
    $this->delivery->update(['status' => WebhookDeliveryStatus::Succeeded]);
    app(WebhookDispatcher::class)->order(WebhookEventType::OrderExpired, $this->order);
    IdempotencyKey::query()->create([
        'shop_id' => $this->shop->getKey(),
        'key' => 'cu',
        'request_hash' => str_repeat('a', 64),
        'expires_at' => now()->addDay(),
    ]);

    $this->travel(31)->days();

    expect(app(PruneOldRecords::class)())->toBe(['idempotency_keys' => 1, 'webhook_deliveries' => 1])
        ->and(WebhookDelivery::sole()->event_type)->toBe(WebhookEventType::OrderExpired);
});

it('được lên lịch chạy mỗi phút, dọn dẹp mỗi giờ', function (): void {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('webhooks-deliver')
        ->expectsOutputToContain('prune-old-records')
        ->assertSuccessful();
});
