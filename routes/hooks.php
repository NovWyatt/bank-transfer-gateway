<?php

use App\Http\Controllers\ReceiveWebhookController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

/*
| Đường nhận webhook của nhà cung cấp. Nạp ngoài nhóm "web": không session,
| không cookie, không CSRF. Chỉ có giới hạn tần suất theo kết nối.
*/
Route::post('/hooks/{provider}/{uuid}', ReceiveWebhookController::class)
    ->whereIn('provider', ['sepay', 'pay2s', 'payos', 'simulator'])
    ->whereUuid('uuid')
    ->name('hooks.receive');

// Update của bot Telegram, xác thực bằng header X-Telegram-Bot-Api-Secret-Token.
Route::post('/telegram/webhook', TelegramWebhookController::class)->name('telegram.webhook');
