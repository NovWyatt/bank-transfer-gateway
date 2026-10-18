<?php

use App\Http\Controllers\PaymentPageController;
use Illuminate\Support\Facades\Route;

/*
| Trang thanh toán cho khách. Nạp ngoài nhóm "web": không session, không cookie
| (xem bẫy Wasmer ở mục 14 của kế hoạch). Giới hạn tần suất theo IP.
*/
Route::controller(PaymentPageController::class)
    ->where(['code' => '[A-Za-z0-9]{8,10}'])
    ->group(function (): void {
        Route::get('/pay/{code}', 'show')->name('pay.show');
        Route::get('/pay/{code}/status', 'status')->name('pay.status');
        Route::get('/qr/{code}.svg', 'qr')->name('pay.qr');
    });
