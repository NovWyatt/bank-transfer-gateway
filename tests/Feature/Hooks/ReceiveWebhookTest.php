<?php

use App\Enums\Provider;
use App\Enums\TransactionStatus;
use App\Enums\TransferDirection;
use App\Models\BankAccount;
use App\Models\InboundEvent;
use App\Models\Order;
use App\Models\ProviderConnection;
use App\Models\Shop;
use App\Models\Transaction;
use App\Payments\Providers\SePaySignature;

/*
| Đường nhận: xác thực, lưu request gốc, chống trùng, tiền ra, trùng chéo nhà cung cấp.
| Các ca ở đây không chạm tới quy tắc so số tiền (xem tests/Feature/Payments/RuleMatcherTest.php).
*/

beforeEach(function () {
    $this->shop = Shop::factory()->create();
    $this->account = BankAccount::factory()->default()->for($this->shop)->create(['account_number' => '1017588888']);
    $this->connection = ProviderConnection::factory()->for($this->shop)
        ->provider(Provider::Simulator)
        ->create(['credentials' => ['secret' => 'secret-gia-lap']]);
});

it('ghi giao dịch và trả đúng phản hồi nhà cung cấp cần', function () {
    $this->postSigned($this->connection, ['content' => 'chuyen tien an trua', 'code' => null])
        ->assertOk()
        ->assertExactJson(['success' => true]);

    $transaction = Transaction::sole();

    expect($transaction->shop_id)->toBe($this->shop->id)
        ->and($transaction->provider)->toBe(Provider::Simulator)
        ->and($transaction->bank_account_id)->toBe($this->account->id)
        ->and($transaction->status)->toBe(TransactionStatus::Unmatched)
        ->and($transaction->fingerprint)->toHaveLength(64)
        ->and($transaction->inbound_event_id)->toBe(InboundEvent::sole()->id)
        ->and($this->connection->fresh()->last_received_at)->not->toBeNull();
});

it('nhà cung cấp gửi lại thì không ghi hai lần', function () {
    $body = $this->sepayPayload(['content' => 'chuyen tien', 'code' => null]);
    $headers = SePaySignature::headers($body, 'secret-gia-lap', now()->getTimestamp());

    $this->postWebhook($this->connection, $body, $headers)->assertOk();
    $this->postWebhook($this->connection, $body, $headers)->assertOk()->assertExactJson(['success' => true]);

    expect(Transaction::count())->toBe(1)
        ->and(InboundEvent::count())->toBe(2);
});

it('cùng external_id ở hai shop khác nhau là hai giao dịch', function () {
    $otherShop = Shop::factory()->create();
    $otherConnection = ProviderConnection::factory()->for($otherShop)
        ->provider(Provider::Simulator)
        ->create(['credentials' => ['secret' => 'secret-shop-khac']]);

    $this->postSigned($this->connection, ['id' => 777, 'code' => null])->assertOk();
    $this->postSigned($otherConnection, ['id' => 777, 'code' => null])->assertOk();

    expect(Transaction::where('external_id', '777')->pluck('shop_id')->sort()->values()->all())
        ->toBe([$this->shop->id, $otherShop->id]);
});

it('giao dịch tiền ra được lưu nhưng bỏ qua', function () {
    $this->postSigned($this->connection, ['transferType' => 'out'])->assertOk();

    expect(Transaction::sole())
        ->direction->toBe(TransferDirection::Out)
        ->status->toBe(TransactionStatus::Ignored);
});

it('đánh dấu trùng khi cùng giao dịch đến qua nhà cung cấp khác', function () {
    // Khóa trùng tính theo phút: giữ đồng hồ đứng yên để hai lần gửi cùng phút.
    $this->freezeSecond();

    $pay2s = ProviderConnection::factory()->for($this->shop)
        ->provider(Provider::Pay2S)
        ->create(['credentials' => ['token' => 'token-pay2s']]);

    $this->postSigned($this->connection, ['transferAmount' => 2000, 'content' => 'THANHTOAN TIEN NHA', 'code' => null])->assertOk();

    $pay2sBody = json_decode(fixtureContent('providers/pay2s/webhook.json'), true);
    $pay2sBody['transactions'][0] = array_merge($pay2sBody['transactions'][0], [
        'accountNumber' => '1017588888',
        'content' => 'THANHTOAN-TIEN-NHA',
        'transactionDate' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s'),
        'paymentCode' => null,
    ]);

    $this->postWebhook($pay2s, json_encode($pay2sBody), ['Authorization' => 'Bearer token-pay2s'])->assertOk();

    $original = Transaction::where('provider', Provider::Simulator)->sole();
    $copy = Transaction::where('provider', Provider::Pay2S)->sole();

    expect($copy->status)->toBe(TransactionStatus::Duplicate)
        ->and($copy->duplicate_of)->toBe($original->id)
        ->and($original->status)->toBe(TransactionStatus::Unmatched);
});

it('không khớp với đơn của shop khác dù nội dung có mã của đơn đó', function () {
    $foreignOrder = Order::factory()->create(['code' => 'DH7K3QXM']);

    $this->postSigned($this->connection, ['content' => 'DH7K3QXM', 'code' => 'DH7K3QXM'])->assertOk();

    expect(Transaction::sole()->status)->toBe(TransactionStatus::Unmatched)
        ->and($foreignOrder->fresh()->paid_amount)->toBe(0);
});

describe('từ chối', function () {
    it('trả 401 và lưu request khi chữ ký sai, đã che header bí mật', function () {
        $body = $this->sepayPayload();

        $this->postWebhook($this->connection, $body, SePaySignature::headers($body, 'secret-sai', now()->getTimestamp()))
            ->assertUnauthorized()
            ->assertJson(['success' => false]);

        $event = InboundEvent::sole();

        expect(Transaction::count())->toBe(0)
            ->and($event->signature_valid)->toBeFalse()
            ->and($event->error)->toContain('không khớp')
            ->and($event->headers['x-sepay-signature'])->toBe('[đã che]')
            ->and($event->shop_id)->toBe($this->shop->id);
    });

    it('trả 400 và ghi lỗi khi payload sai', function () {
        $body = '{"id": 1}';

        $this->postWebhook($this->connection, $body, SePaySignature::headers($body, 'secret-gia-lap', now()->getTimestamp()))
            ->assertStatus(400);

        expect(InboundEvent::sole()->error)->toContain('accountNumber');
    });

    it('trả 413 khi body quá lớn', function () {
        $body = str_repeat('x', 70_000);

        $this->postWebhook($this->connection, $body)->assertStatus(413);

        expect(InboundEvent::count())->toBe(0);
    });

    it('trả 404 khi sai uuid hoặc sai nhà cung cấp trong URL', function () {
        $this->postJson('/hooks/simulator/'.fake()->uuid(), [])->assertNotFound();
        $this->postJson("/hooks/sepay/{$this->connection->uuid}", [])->assertNotFound();
        $this->postJson('/hooks/simulator/khong-phai-uuid', [])->assertNotFound();
    });

    it('kết nối tạm dừng thì báo nhận nhưng không lưu gì', function () {
        $this->connection->update(['status' => 'paused']);

        $this->postSigned($this->connection)->assertOk()->assertExactJson(['success' => true]);

        expect(InboundEvent::count())->toBe(0)
            ->and(Transaction::count())->toBe(0);
    });
});

it('không tạo session hay cookie', function () {
    $this->postSigned($this->connection, ['code' => null])
        ->assertOk()
        ->assertCookieMissing(config('session.cookie'));
});
