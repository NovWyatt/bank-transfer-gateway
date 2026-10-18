<?php

use App\Models\BankAccount;
use App\Models\Order;
use App\Models\ProviderConnection;
use App\Models\Shop;
use App\Models\Transaction;
use App\Tenancy\CurrentShop;

beforeEach(function () {
    $this->shopA = Shop::factory()->create();
    $this->shopB = Shop::factory()->create();

    foreach ([$this->shopA, $this->shopB] as $shop) {
        Order::factory()->for($shop)->create();
        ProviderConnection::factory()->for($shop)->create();
        Transaction::factory()->for($shop)->create();
    }

    $this->current = app(CurrentShop::class);
});

it('chưa đặt shop thì thấy dữ liệu mọi shop', function () {
    expect(Order::count())->toBe(2)
        ->and(BankAccount::count())->toBe(2)
        ->and(ProviderConnection::count())->toBe(2)
        ->and(Transaction::count())->toBe(2);
});

it('đặt shop thì chỉ thấy dữ liệu của shop đó', function () {
    $this->current->set($this->shopA);

    foreach ([Order::class, BankAccount::class, ProviderConnection::class, Transaction::class] as $model) {
        expect($model::pluck('shop_id')->unique()->all())->toBe([$this->shopA->id]);
    }
});

it('không lấy được bản ghi của shop khác kể cả khi biết id', function () {
    $orderOfB = Order::withoutGlobalScopes()->where('shop_id', $this->shopB->id)->sole();

    $this->current->set($this->shopA);

    expect(Order::find($orderOfB->id))->toBeNull()
        ->and(Order::where('code', $orderOfB->code)->exists())->toBeFalse();
});

it('tự gán shop hiện tại khi tạo bản ghi', function () {
    $this->current->set($this->shopB);

    $account = BankAccount::create([
        'bank_bin' => '970422',
        'bank_code' => 'MB',
        'account_number' => '0123456789',
        'account_name' => 'TRAN THI B',
    ]);

    expect($account->shop_id)->toBe($this->shopB->id);
});

it('runAs trả lại shop cũ kể cả khi có lỗi', function () {
    $this->current->set($this->shopA);

    expect(fn () => $this->current->runAs($this->shopB, function () {
        expect($this->current->id())->toBe($this->shopB->id);

        throw new RuntimeException('lỗi giữa chừng');
    }))->toThrow(RuntimeException::class);

    expect($this->current->id())->toBe($this->shopA->id);
});
