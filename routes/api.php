<?php

use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\TransactionController;
use App\Http\Controllers\Api\V1\WebhookEndpointController;
use App\Http\Middleware\SetCurrentShopFromToken;
use Illuminate\Support\Facades\Route;

/*
| API v1 cho lập trình viên của shop (mục 5.6 của kế hoạch). Nạp ở bootstrap/app.php với tiền tố /api/v1.
| Xác thực bằng token Sanctum gắn với shop. Mỗi route đòi đúng quyền của token.
| Tài liệu sinh tự động ở /docs/api (Scramble).
*/
Route::middleware(['auth:sanctum', SetCurrentShopFromToken::class, 'throttle:api'])->group(function (): void {
    Route::controller(OrderController::class)
        ->where(['code' => '[A-Za-z0-9]{8,10}'])
        ->group(function (): void {
            Route::get('/orders', 'index')->middleware('abilities:orders:read')->name('orders.index');
            Route::post('/orders', 'store')->middleware(['abilities:orders:write', 'idempotent'])->name('orders.store');
            Route::get('/orders/{code}', 'show')->middleware('abilities:orders:read')->name('orders.show');
            Route::post('/orders/{code}/cancel', 'cancel')->middleware(['abilities:orders:write', 'idempotent'])->name('orders.cancel');
        });

    Route::get('/transactions', [TransactionController::class, 'index'])
        ->middleware('abilities:transactions:read')
        ->name('transactions.index');

    Route::post('/webhook-endpoints/{id}/test', [WebhookEndpointController::class, 'test'])
        ->whereNumber('id')
        ->middleware(['abilities:webhooks:write', 'throttle:webhook-test'])
        ->name('webhook-endpoints.test');
});
