<?php

namespace App\Http\Controllers\Internal;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * Nhịp mỗi phút trên Wasmer, do Cloudflare Worker gọi (docs/KE-HOACH.md, mục 10).
 *
 * Không gọi `schedule:run`: lệnh đó tìm đường dẫn php bằng exec(), mà WebAssembly không fork được.
 * Ở đây tự chạy các việc đến hạn, chỉ gồm Schedule::call (chạy trong tiến trình hiện tại).
 */
class TickController
{
    public function __invoke(Application $app, Kernel $console): JsonResponse
    {
        $started = hrtime(true);

        // Request HTTP không nạp routes/console.php, nơi khai báo lịch. Bootstrap kernel console để nạp.
        $console->bootstrap();
        $schedule = $app->make(Schedule::class);

        $ran = [];
        $failed = [];

        foreach ($schedule->dueEvents($app) as $event) {
            if (! $event->filtersPass($app)) {
                continue;
            }

            $name = (string) $event->description;

            // Như schedule:run: một việc hỏng thì ghi log rồi chạy tiếp các việc khác.
            try {
                $event->run($app);
                $event->exitCode === 0 ? $ran[] = $name : $failed[] = $name;
            } catch (Throwable $e) {
                report($e);
                $failed[] = $name;
            }
        }

        // Hàng đợi (tin Telegram) xử lý trong tiến trình, giới hạn thời gian để cả nhịp ngắn.
        Artisan::call('queue:work', [
            '--stop-when-empty' => true,
            '--max-time' => (int) config('internal.queue_seconds'),
            '--tries' => 3,
            '--sleep' => 0,
        ]);

        return response()->json([
            'ran' => $ran,
            'failed' => $failed,
            'duration_ms' => intdiv(hrtime(true) - $started, 1_000_000),
        ], $failed === [] ? 200 : 500);
    }
}
