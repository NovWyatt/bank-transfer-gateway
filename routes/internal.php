<?php

use App\Http\Controllers\Internal\BootstrapController;
use App\Http\Controllers\Internal\DeployController;
use App\Http\Controllers\Internal\TickController;
use Illuminate\Support\Facades\Route;

/*
| Đường máy gọi máy, ký HMAC (app/Internal/InternalSignature.php). Nạp ngoài nhóm "web":
| không session, không cookie, không CSRF. Không có trên bản đồ API công khai.
*/
Route::post('/internal/tick', TickController::class)
    ->middleware('internal:tick')
    ->name('internal.tick');

Route::middleware('internal:deploy')->group(function (): void {
    Route::post('/internal/deploy', DeployController::class)->name('internal.deploy');
    Route::post('/internal/bootstrap', BootstrapController::class)->name('internal.bootstrap');
});
