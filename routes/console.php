<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Spike Wasmer, câu 3: lịch chạy mỗi phút có thật sự chạy không.
Artisan::command('spike:heartbeat {source=schedule}', function (string $source) {
    DB::table('spike_heartbeats')->insert(['source' => $source, 'note' => gethostname() ?: null]);
})->purpose('Ghi một dòng heartbeat cho spike Wasmer');

// Dùng Schedule::call (chạy trong tiến trình hiện tại), không dùng Schedule::command:
// command() phải tìm đường dẫn php bằng exec(), mà WebAssembly trên Wasmer không fork được.
Schedule::call(fn () => Artisan::call('spike:heartbeat'))
    ->name('spike-heartbeat')
    ->everyMinute()
    ->when(fn () => (bool) config('spike.enabled'));
