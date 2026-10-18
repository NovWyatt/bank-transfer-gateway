<?php

use App\Enums\MatchedBy;
use App\Enums\OrderStatus;
use App\Enums\Provider;
use App\Enums\TransactionStatus;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\ProviderConnection;
use App\Models\Shop;
use App\Models\Transaction;

/*
| Quy tắc khớp theo mục 5.4 của docs/KE-HOACH.md, chạy hết đường nhận thật qua bộ giả lập.
*/

beforeEach(function () {
    $this->freezeSecond();

    $this->shop = Shop::factory()->create();
    $this->account = BankAccount::factory()->default()->for($this->shop)->create(['account_number' => '1017588888']);
    $this->connection = ProviderConnection::factory()->for($this->shop)
        ->provider(Provider::Simulator)
        ->create(['credentials' => ['secret' => 'secret-gia-lap']]);

    $this->order = Order::factory()->for($this->shop)->create([
        'bank_account_id' => $this->account->id,
        'code' => 'DH7K3QXM',
        'amount' => 150_000,
    ]);
});

/** Khách chuyển $amount đồng với nội dung $content. */
function pay(int $amount, string $content = 'DH7K3QXM'): void
{
    test()->postSigned(test()->connection, [
        'transferAmount' => $amount,
        'content' => $content,
        'code' => null,
    ])->assertOk()->assertExactJson(['success' => true]);
}

it('trả đủ thì đơn thành đã thanh toán', function () {
    pay(150_000);

    $order = $this->order->fresh();
    $transaction = Transaction::sole();

    expect($order->status)->toBe(OrderStatus::Paid)
        ->and($order->paid_amount)->toBe(150_000)
        ->and($order->paid_at->equalTo(now()))->toBeTrue()
        ->and($order->paid_after_expiry)->toBeFalse()
        ->and($transaction->status)->toBe(TransactionStatus::Matched)
        ->and($transaction->order_id)->toBe($order->id)
        ->and($transaction->matched_by)->toBe(MatchedBy::Rule);
});

it('tìm được mã khi ngân hàng chèn tiền tố và hậu tố vào nội dung', function () {
    pay(150_000, 'MBVCB.123456.DH7K3QXM.CT tu NGUYEN VAN A');

    expect($this->order->fresh()->status)->toBe(OrderStatus::Paid);
});

it('trả thiếu thì thành trả thiếu, trả nốt phần còn lại thì thành đã thanh toán', function () {
    pay(100_000);

    expect($this->order->fresh())
        ->status->toBe(OrderStatus::PartiallyPaid)
        ->paid_amount->toBe(100_000)
        ->paid_at->toBeNull();

    pay(50_000);

    expect($this->order->fresh())
        ->status->toBe(OrderStatus::Paid)
        ->paid_amount->toBe(150_000);

    expect(Transaction::where('status', TransactionStatus::Matched)->count())->toBe(2);
});

it('trả dư thì thành trả dư', function () {
    pay(200_000);

    expect($this->order->fresh())
        ->status->toBe(OrderStatus::Overpaid)
        ->paid_amount->toBe(200_000);
});

it('đơn hết hạn vẫn nhận tiền trong thời gian ân hạn và gắn cờ', function (OrderStatus $status) {
    // Pending mà đã quá hạn: lệnh đánh dấu hết hạn chưa kịp chạy.
    $this->order->update(['status' => $status, 'expires_at' => now()->subHours(23)]);

    pay(150_000);

    expect($this->order->fresh())
        ->status->toBe(OrderStatus::Paid)
        ->paid_after_expiry->toBeTrue();
})->with([OrderStatus::Expired, OrderStatus::Pending]);

it('đơn hết hạn quá thời gian ân hạn thì không khớp', function () {
    $this->order->update(['status' => OrderStatus::Expired, 'expires_at' => now()->subHours(25)]);

    pay(150_000);

    expect(Transaction::sole()->status)->toBe(TransactionStatus::Unmatched)
        ->and($this->order->fresh()->paid_amount)->toBe(0);
});

it('đơn đã trả đủ mà nhận thêm tiền thì để chưa khớp cho người duyệt', function () {
    pay(150_000);
    pay(150_000);

    expect($this->order->fresh())
        ->status->toBe(OrderStatus::Paid)
        ->paid_amount->toBe(150_000);

    expect(Transaction::orderBy('id')->pluck('status')->all())
        ->toBe([TransactionStatus::Matched, TransactionStatus::Unmatched]);
});

it('ân hạn tính theo giờ ngân hàng ghi nhận, không theo lúc webhook đến', function () {
    // Đơn hết hạn 30 giờ trước, nhưng khách đã chuyển lúc 20 giờ sau hạn; webhook đến muộn.
    $this->order->update(['status' => OrderStatus::Expired, 'expires_at' => now()->subHours(30)]);

    test()->postSigned($this->connection, [
        'transferAmount' => 150_000,
        'content' => 'DH7K3QXM',
        'code' => null,
        'transactionDate' => now('Asia/Ho_Chi_Minh')->subHours(10)->format('Y-m-d H:i:s'),
    ])->assertOk();

    expect($this->order->fresh())
        ->status->toBe(OrderStatus::Paid)
        ->paid_after_expiry->toBeTrue();
});

it('đơn đã hủy thì không khớp', function () {
    $this->order->update(['status' => OrderStatus::Cancelled]);

    pay(150_000);

    expect(Transaction::sole()->status)->toBe(TransactionStatus::Unmatched)
        ->and($this->order->fresh()->status)->toBe(OrderStatus::Cancelled);
});
