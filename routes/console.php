<?php

use App\Actions\ExpireOrders;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('orders:expire', function (ExpireOrders $expire) {
    $this->info('Đã chuyển '.$expire().' đơn sang hết hạn.');
})->purpose('Chuyển đơn chờ thanh toán đã quá hạn sang hết hạn');

// Dùng Schedule::call (chạy trong tiến trình hiện tại), không dùng Schedule::command:
// command() tìm đường dẫn php bằng exec(), mà WebAssembly trên Wasmer không fork được.
// Trên Wasmer, Cloudflare Worker gọi /internal/tick mỗi phút để chạy các lịch này (GĐ3).
Schedule::call(new ExpireOrders)
    ->name('orders-expire')
    ->everyMinute();
