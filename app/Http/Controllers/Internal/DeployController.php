<?php

namespace App\Http\Controllers\Internal;

use App\Telegram\TelegramClient;
use App\Telegram\TelegramError;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;

/**
 * CI gọi ngay sau mỗi lần deploy: migrate, rồi đăng ký webhook Telegram.
 *
 * Không dùng job post-deployment của Wasmer: spike cho thấy job đó không nhận secret DB_* của app
 * nên migrate vào một database khác.
 */
class DeployController
{
    public function __invoke(TelegramClient $telegram): JsonResponse
    {
        $exitCode = Artisan::call('migrate', ['--force' => true]);
        $migrateOutput = trim(Artisan::output());

        $telegramStatus = 'Chưa đặt TELEGRAM_BOT_TOKEN hoặc TELEGRAM_WEBHOOK_SECRET, bỏ qua.';

        if ($telegram->configured() && filled(config('services.telegram.webhook_secret'))) {
            try {
                $telegram->setWebhook(route('telegram.webhook'), (string) config('services.telegram.webhook_secret'));
                $telegramStatus = 'Đã đăng ký '.route('telegram.webhook');
            } catch (TelegramError $e) {
                $telegramStatus = 'Lỗi: '.$e->getMessage();
            }
        }

        return response()->json([
            'migrate' => ['exit_code' => $exitCode, 'output' => $migrateOutput],
            'telegram_webhook' => $telegramStatus,
        ], $exitCode === 0 ? 200 : 500);
    }
}
