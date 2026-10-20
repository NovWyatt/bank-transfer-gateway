<?php

use App\Actions\ExpireOrders;
use App\Actions\PruneOldRecords;
use App\Actions\SimulateTransfer;
use App\Models\Order;
use App\Tenancy\ShopScope;
use App\Webhooks\DeliverDueWebhooks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('orders:expire', function (ExpireOrders $expire) {
    $this->info('Đã chuyển '.$expire().' đơn sang hết hạn.');
})->purpose('Chuyển đơn chờ thanh toán đã quá hạn sang hết hạn');

Artisan::command('pay:simulate {code} {amount?} {--content=}', function (SimulateTransfer $simulate, string $code, ?string $amount = null) {
    if (! config('payments.simulator_enabled')) {
        $this->error('Giả lập đang tắt (PAYMENTS_SIMULATOR=false).');

        return 1;
    }

    $order = Order::withoutGlobalScope(ShopScope::class)->where('code', strtoupper($code))->first();

    if ($order === null) {
        $this->error('Không thấy đơn.');

        return 1;
    }

    $status = $simulate->handle($order, $amount === null ? null : (int) $amount, $this->option('content'));
    $this->info("Đường nhận trả HTTP {$status}. Đơn {$order->code}: ".$order->fresh()?->status->label().'.');

    return $status === 200 ? 0 : 1;
})->purpose('Giả lập khách chuyển khoản cho một đơn, đi qua đường nhận webhook thật');

Artisan::command('webhooks:deliver', function (DeliverDueWebhooks $deliver) {
    $this->info('Đã gửi '.$deliver().' webhook.');
})->purpose('Gửi các webhook đến hạn ngay, không chờ nhịp mỗi phút');

// Dùng Schedule::call (chạy trong tiến trình hiện tại), không dùng Schedule::command:
// command() tìm đường dẫn php bằng exec(), mà WebAssembly trên Wasmer không fork được.
// Trên Wasmer, Cloudflare Worker gọi /internal/tick mỗi phút để chạy các lịch này (GĐ3).
// Truyền tên class: container tạo đối tượng và gọi __invoke, kèm phụ thuộc của constructor.
Schedule::call(ExpireOrders::class)
    ->name('orders-expire')
    ->everyMinute();

// Chạy sau orders-expire để webhook order.expired của nhịp này đi luôn.
Schedule::call(DeliverDueWebhooks::class)
    ->name('webhooks-deliver')
    ->everyMinute()
    ->withoutOverlapping(2);

Schedule::call(PruneOldRecords::class)
    ->name('prune-old-records')
    ->hourly();
