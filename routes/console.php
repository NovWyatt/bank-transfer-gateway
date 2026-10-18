<?php

use App\Actions\ExpireOrders;
use App\Actions\PruneOldRecords;
use App\Enums\Provider;
use App\Models\Order;
use App\Models\ProviderConnection;
use App\Payments\Providers\SePaySignature;
use App\Tenancy\ShopScope;
use App\Webhooks\DeliverDueWebhooks;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schedule;

Artisan::command('orders:expire', function (ExpireOrders $expire) {
    $this->info('Đã chuyển '.$expire().' đơn sang hết hạn.');
})->purpose('Chuyển đơn chờ thanh toán đã quá hạn sang hết hạn');

Artisan::command('pay:simulate {code} {amount?} {--content=}', function (string $code, ?string $amount = null) {
    $order = Order::withoutGlobalScope(ShopScope::class)->with('bankAccount')->where('code', strtoupper($code))->first();
    $connection = $order === null ? null : ProviderConnection::withoutGlobalScope(ShopScope::class)
        ->where('shop_id', $order->shop_id)
        ->where('provider', Provider::Simulator)
        ->first();

    if ($order === null || $connection === null) {
        $this->error('Không thấy đơn, hoặc shop của đơn chưa có kết nối giả lập.');

        return 1;
    }

    // Payload định dạng SePay, ký HMAC bằng secret của kết nối, gửi vào đúng đường nhận thật.
    $body = (string) json_encode([
        'id' => random_int(100_000_000, 999_999_999),
        'gateway' => $order->bankAccount->bank_code,
        'transactionDate' => now('Asia/Ho_Chi_Minh')->format('Y-m-d H:i:s'),
        'accountNumber' => $order->bankAccount->account_number,
        'subAccount' => '',
        'code' => null,
        'content' => $this->option('content') ?? 'MBVCB.'.random_int(1_000_000, 9_999_999).'.'.$order->code.'.CT tu NGUYEN VAN B',
        'transferType' => 'in',
        'description' => '',
        'transferAmount' => (int) ($amount ?? $order->remainingAmount()),
        'accumulated' => 0,
        'referenceCode' => 'FT'.random_int(10_000_000_000, 99_999_999_999),
    ], JSON_UNESCAPED_UNICODE);

    $response = Http::withHeaders(SePaySignature::headers($body, (string) $connection->credential('secret'), now()->getTimestamp()))
        ->withBody($body, 'application/json')
        ->post(route('hooks.receive', ['provider' => 'simulator', 'uuid' => $connection->uuid]));

    $this->info("HTTP {$response->status()}: {$response->body()}");

    return $response->successful() ? 0 : 1;
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
