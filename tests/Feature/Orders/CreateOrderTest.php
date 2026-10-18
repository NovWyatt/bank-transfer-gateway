<?php

use App\Actions\CreateOrder;
use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Models\BankAccount;
use App\Models\Order;
use App\Models\Shop;
use App\Payments\OrderCode;
use Illuminate\Database\UniqueConstraintViolationException;

beforeEach(function () {
    $this->shop = Shop::factory()->withBankAccount()->create(['order_prefix' => 'ABC']);
});

it('tạo đơn chờ thanh toán với tiền tố của shop và tài khoản mặc định', function () {
    $this->freezeSecond();

    $order = app(CreateOrder::class)->handle($this->shop, 150_000, OrderSource::Dashboard);

    expect($order->code)->toStartWith('ABC')
        ->and(OrderCode::isWellFormed($order->code, 'ABC'))->toBeTrue()
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($order->amount)->toBe(150_000)
        ->and($order->paid_amount)->toBe(0)
        ->and($order->bank_account_id)->toBe($this->shop->bankAccounts()->sole()->id)
        ->and($order->expires_at->equalTo(now()->addMinutes(CreateOrder::DEFAULT_TTL_MINUTES)))->toBeTrue();
});

it('dùng thời hạn riêng khi được truyền vào', function () {
    $this->freezeSecond();

    $order = app(CreateOrder::class)->handle($this->shop, 50_000, OrderSource::Api, expiresInMinutes: 120);

    expect($order->expires_at->equalTo(now()->addMinutes(120)))->toBeTrue();
});

it('sinh lại mã khi trùng với đơn đã có, kể cả đơn của shop khác', function () {
    // Đơn này thuộc một shop khác: mã đơn duy nhất toàn hệ thống.
    Order::factory()->create(['code' => 'ABC222222']);

    $codes = ['ABC222222', 'ABC333333'];
    $action = new CreateOrder(function () use (&$codes) {
        return array_shift($codes);
    });

    $order = $action->handle($this->shop, 10_000, OrderSource::Dashboard);

    expect($order->code)->toBe('ABC333333')
        ->and($codes)->toBeEmpty();
});

it('không sinh lại mà báo lỗi khi trùng external_ref trong cùng shop', function () {
    $action = app(CreateOrder::class);
    $action->handle($this->shop, 10_000, OrderSource::Api, externalRef: 'WOO-1');

    $action->handle($this->shop, 20_000, OrderSource::Api, externalRef: 'WOO-1');
})->throws(UniqueConstraintViolationException::class);

it('cho phép external_ref giống nhau ở hai shop khác nhau', function () {
    $other = Shop::factory()->withBankAccount()->create();
    $action = app(CreateOrder::class);

    $action->handle($this->shop, 10_000, OrderSource::Api, externalRef: 'WOO-1');
    $action->handle($other, 10_000, OrderSource::Api, externalRef: 'WOO-1');

    expect(Order::where('external_ref', 'WOO-1')->count())->toBe(2);
});

it('báo lỗi khi shop chưa có tài khoản mặc định', function () {
    app(CreateOrder::class)->handle(Shop::factory()->create(), 10_000, OrderSource::Dashboard);
})->throws(DomainException::class, 'chưa có tài khoản');

it('không cho dùng tài khoản của shop khác', function () {
    $foreign = BankAccount::factory()->create();

    app(CreateOrder::class)->handle($this->shop, 10_000, OrderSource::Dashboard, bankAccount: $foreign);
})->throws(DomainException::class, 'không thuộc shop');

it('từ chối số tiền không dương', function (int $amount) {
    app(CreateOrder::class)->handle($this->shop, $amount, OrderSource::Dashboard);
})->with([0, -1000])->throws(InvalidArgumentException::class);
