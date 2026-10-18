<?php

use App\Actions\CancelOrder;
use App\Actions\ExpireOrders;
use App\Enums\OrderStatus;
use App\Enums\Provider;
use App\Enums\WebhookDeliveryStatus;
use App\Enums\WebhookEventType;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\ProviderConnection;
use App\Models\Shop;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Payments\Providers\SePaySignature;
use App\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\DB;

/*
| Sự kiện được ghi vào hàng chờ gửi (outbox) cùng lúc với việc đổi trạng thái đơn. Không gửi mạng ở đây.
*/

beforeEach(function (): void {
    $this->shop = Shop::factory()->create();
    $this->account = BankAccount::factory()->default()->for($this->shop)->create(['account_number' => '1017588888']);
    $this->connection = ProviderConnection::factory()->for($this->shop)
        ->provider(Provider::Simulator)
        ->create(['credentials' => ['secret' => 'secret-gia-lap']]);
    $this->endpoint = WebhookEndpoint::factory()->for($this->shop)->create();
    $this->order = Order::factory()->for($this->shop)->create([
        'bank_account_id' => $this->account->getKey(),
        'amount' => 150_000,
        'external_ref' => 'WOO-1234',
    ]);
});

/** @return array<string, mixed> */
function deliveredPayload(WebhookDelivery $delivery): array
{
    return json_decode($delivery->payload, true, flags: JSON_THROW_ON_ERROR);
}

it('khách trả đủ thì ghi order.paid kèm đơn và giao dịch, chờ gửi ngay', function (): void {
    $this->freezeSecond();

    $this->postSigned($this->connection, ['content' => "MBVCB.123.{$this->order->code}.CT", 'transferAmount' => 150_000, 'code' => null])
        ->assertOk();

    $delivery = WebhookDelivery::sole();
    $payload = deliveredPayload($delivery);

    expect($delivery->event_type)->toBe(WebhookEventType::OrderPaid)
        ->and($delivery->status)->toBe(WebhookDeliveryStatus::Pending)
        ->and($delivery->next_attempt_at->equalTo(now()))->toBeTrue()
        ->and($delivery->event_id)->toStartWith('evt_')
        ->and($payload['id'])->toBe($delivery->event_id)
        ->and($payload['type'])->toBe('order.paid')
        ->and($payload['data']['order'])->toMatchArray([
            'code' => $this->order->code,
            'status' => 'paid',
            'amount' => 150_000,
            'paid_amount' => 150_000,
            'external_ref' => 'WOO-1234',
            'qr_string' => null,
        ])
        ->and($payload['data']['transaction'])->toMatchArray([
            'amount' => 150_000,
            'order_code' => $this->order->code,
            'account_number' => '1017588888',
        ]);
});

it('trả thiếu, trả dư và tiền không khớp đơn đều có sự kiện riêng', function (): void {
    $this->postSigned($this->connection, ['content' => $this->order->code, 'transferAmount' => 50_000, 'code' => null]);
    $this->postSigned($this->connection, ['content' => $this->order->code, 'transferAmount' => 200_000, 'code' => null]);
    $this->postSigned($this->connection, ['content' => 'tien hang thang 10', 'transferAmount' => 99_000, 'code' => null]);

    expect(WebhookDelivery::query()->orderBy('id')->pluck('event_type')->all())->toBe([
        WebhookEventType::OrderPartiallyPaid,
        WebhookEventType::OrderOverpaid,
        WebhookEventType::TransactionUnmatched,
    ]);

    $unmatched = deliveredPayload(WebhookDelivery::query()->latest('id')->first());
    expect($unmatched['data']['order'])->toBeNull()
        ->and($unmatched['data']['transaction']['order_code'])->toBeNull();
});

it('nhà cung cấp gửi lại cùng giao dịch thì không có webhook thứ hai', function (): void {
    $body = $this->sepayPayload(['content' => $this->order->code, 'transferAmount' => 150_000, 'code' => null]);
    $headers = SePaySignature::headers($body, 'secret-gia-lap', now()->getTimestamp());

    $this->postWebhook($this->connection, $body, $headers)->assertOk();
    $this->postWebhook($this->connection, $body, $headers)->assertOk();

    expect(WebhookDelivery::count())->toBe(1);
});

it('tiền ra không sinh webhook', function (): void {
    $this->postSigned($this->connection, ['transferType' => 'out', 'content' => $this->order->code]);

    expect(WebhookDelivery::count())->toBe(0);
});

it('một sự kiện, một event_id chung cho mọi endpoint đăng ký; bỏ qua endpoint tắt hoặc không đăng ký', function (): void {
    $second = WebhookEndpoint::factory()->for($this->shop)->create(['url' => 'https://erp.shop.example/hook']);
    WebhookEndpoint::factory()->for($this->shop)->inactive()->create();
    WebhookEndpoint::factory()->for($this->shop)->only([WebhookEventType::OrderExpired])->create();
    WebhookEndpoint::factory()->create();

    $this->postSigned($this->connection, ['content' => $this->order->code, 'transferAmount' => 150_000, 'code' => null]);

    $deliveries = WebhookDelivery::query()->orderBy('webhook_endpoint_id')->get();

    expect($deliveries->pluck('webhook_endpoint_id')->all())->toBe([$this->endpoint->getKey(), $second->getKey()])
        ->and($deliveries->pluck('event_id')->unique())->toHaveCount(1)
        ->and($deliveries[0]->payload)->toBe($deliveries[1]->payload);
});

it('shop không có endpoint thì không ghi gì', function (): void {
    $this->endpoint->delete();

    $this->postSigned($this->connection, ['content' => $this->order->code, 'transferAmount' => 150_000, 'code' => null]);

    expect(WebhookDelivery::count())->toBe(0);
});

it('transaction rollback thì bản ghi gửi cũng mất', function (): void {
    try {
        DB::transaction(function (): void {
            app(WebhookDispatcher::class)->order(WebhookEventType::OrderPaid, $this->order);

            throw new RuntimeException('hỏng giữa chừng');
        });
    } catch (RuntimeException) {
    }

    expect(WebhookDelivery::count())->toBe(0);
});

it('hủy đơn ghi order.cancelled', function (): void {
    app(CancelOrder::class)->handle($this->order, User::factory()->create());

    $delivery = WebhookDelivery::sole();

    expect($delivery->event_type)->toBe(WebhookEventType::OrderCancelled)
        ->and(deliveredPayload($delivery)['data']['order']['status'])->toBe('cancelled')
        ->and(deliveredPayload($delivery)['data']['transaction'])->toBeNull();
});

it('đơn quá hạn ghi order.expired, đơn khác shop đi đúng endpoint của shop đó', function (): void {
    $this->order->update(['expires_at' => now()->subMinute()]);
    $otherOrder = Order::factory()->create(['expires_at' => now()->subMinute()]);
    $otherEndpoint = WebhookEndpoint::factory()->for($otherOrder->shop)->create();

    expect(app(ExpireOrders::class)())->toBe(2);

    $deliveries = WebhookDelivery::query()->get()->keyBy('webhook_endpoint_id');

    expect($deliveries)->toHaveCount(2)
        ->and($deliveries[$this->endpoint->getKey()]->event_type)->toBe(WebhookEventType::OrderExpired)
        ->and(deliveredPayload($deliveries[$otherEndpoint->getKey()])['data']['order']['code'])->toBe($otherOrder->code)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Expired);
});
