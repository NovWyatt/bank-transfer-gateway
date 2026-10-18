<?php

use App\Actions\CancelOrder;
use App\Enums\OrderStatus;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;

it('hủy đơn trả thiếu và ghi số tiền đã nhận vào nhật ký', function (): void {
    $order = Order::factory()->create([
        'amount' => 300_000,
        'paid_amount' => 100_000,
        'status' => OrderStatus::PartiallyPaid,
    ]);
    $user = User::factory()->create();

    app(CancelOrder::class)->handle($order, $user, '203.0.113.7');

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);

    $log = AuditLog::withoutGlobalScopes()->sole();
    expect($log->shop_id)->toBe($order->shop_id)
        ->and($log->ip)->toBe('203.0.113.7')
        ->and($log->meta)->toEqual(['from' => 'partially_paid', 'paid_amount' => 100_000, 'via' => 'dashboard']);
});

it('hủy được đơn hết hạn để ngừng nhận tiền trong thời gian ân hạn', function (): void {
    $order = Order::factory()->expired()->create(['status' => OrderStatus::Expired]);

    app(CancelOrder::class)->handle($order, User::factory()->create());

    expect($order->refresh()->status)->toBe(OrderStatus::Cancelled);
});

it('không hủy đơn đã đủ tiền hoặc đã hủy', function (OrderStatus $status): void {
    $order = Order::factory()->create(['status' => $status]);

    expect(fn () => app(CancelOrder::class)->handle($order, User::factory()->create()))
        ->toThrow(DomainException::class);

    expect($order->refresh()->status)->toBe($status)
        ->and(AuditLog::withoutGlobalScopes()->count())->toBe(0);
})->with([OrderStatus::Paid, OrderStatus::Overpaid, OrderStatus::Cancelled]);
