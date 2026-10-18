<?php

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Shop;
use App\Tenancy\CurrentShop;

it('chỉ chuyển đơn chờ thanh toán đã quá hạn, trên mọi shop', function () {
    $this->freezeSecond();

    $overdue = Order::factory()->count(2)->create(['expires_at' => now()->subMinute()]);
    $dueNow = Order::factory()->create(['expires_at' => now()]);
    $future = Order::factory()->create(['expires_at' => now()->addMinute()]);
    $partial = Order::factory()->create(['status' => OrderStatus::PartiallyPaid, 'expires_at' => now()->subHour()]);
    $paid = Order::factory()->create(['status' => OrderStatus::Paid, 'expires_at' => now()->subHour()]);

    // Đang đứng trong một shop khác cũng không làm lệnh bỏ sót shop nào.
    app(CurrentShop::class)->set(Shop::factory()->create());

    $this->artisan('orders:expire')
        ->expectsOutput('Đã chuyển 3 đơn sang hết hạn.')
        ->assertSuccessful();

    expect($overdue->map->fresh()->pluck('status')->unique()->all())->toBe([OrderStatus::Expired])
        ->and($dueNow->fresh()->status)->toBe(OrderStatus::Expired)
        ->and($future->fresh()->status)->toBe(OrderStatus::Pending)
        ->and($partial->fresh()->status)->toBe(OrderStatus::PartiallyPaid)
        ->and($paid->fresh()->status)->toBe(OrderStatus::Paid);
});

it('được lên lịch chạy mỗi phút', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('orders-expire')
        ->assertSuccessful();
});
