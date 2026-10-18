<?php

use App\Http\Controllers\SpikeController;
use App\Http\Middleware\RequireSpikeToken;
use Illuminate\Support\Facades\Route;

// Không nằm trong nhóm "web": không session, không cookie, không CSRF.
Route::prefix('spike')->middleware(RequireSpikeToken::class)->group(function () {
    Route::get('db', [SpikeController::class, 'db']);
    Route::get('http', [SpikeController::class, 'http']);
    Route::get('after-response', [SpikeController::class, 'afterResponse']);
    Route::get('status', [SpikeController::class, 'status']);

    // Dự phòng cho câu 3: nếu job cron của Wasmer chỉ gọi được URL, nó gọi đường này.
    Route::get('tick', [SpikeController::class, 'tick']);
});
