<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Bốn câu hỏi của spike Wasmer (docs/KE-HOACH.md, mục 10).
 */
class SpikeController extends Controller
{
    /** Câu 1: đọc và ghi DB, xem kết nối có đi qua TLS không. */
    public function db(): JsonResponse
    {
        $id = DB::table('spike_heartbeats')->insertGetId(['source' => 'db', 'note' => 'ghi từ /spike/db']);
        $cipher = DB::selectOne("SHOW SESSION STATUS LIKE 'Ssl_cipher'");

        return response()->json([
            'version' => DB::selectOne('select version() as v')->v,
            'ssl_cipher' => $cipher->Value ?? null,
            'inserted_id' => $id,
            'rows' => DB::table('spike_heartbeats')->count(),
        ]);
    }

    /** Câu 2: gửi HTTPS ra ngoài. */
    public function http(): JsonResponse
    {
        $started = microtime(true);

        try {
            $response = Http::timeout(10)->get('https://api.telegram.org/bot/getMe');
            $result = ['ok' => true, 'status' => $response->status()];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'error' => $e::class.': '.$e->getMessage()];
        }

        return response()->json($result + ['ms' => (int) round((microtime(true) - $started) * 1000)]);
    }

    /** Câu 4: việc chạy sau khi đã trả phản hồi. Kết quả xem ở /spike/status. */
    public function afterResponse(): JsonResponse
    {
        $requestedAt = now()->format('H:i:s.v');

        dispatch(function () use ($requestedAt) {
            sleep(2);
            DB::table('spike_heartbeats')->insert(['source' => 'after_response', 'note' => "request lúc {$requestedAt}"]);
        })->afterResponse();

        return response()->json(['queued_at' => $requestedAt, 'xem_ket_qua' => '/spike/status']);
    }

    /** Câu 3, đường dự phòng: cron bên ngoài gọi URL này thay cho `php artisan schedule:run`. */
    public function tick(): JsonResponse
    {
        Artisan::call('spike:heartbeat', ['source' => 'tick']);

        return response()->json(['ok' => true, 'at' => now()->toIso8601String()]);
    }

    /** Câu 3: lịch chạy mỗi phút ghi dòng nguồn "schedule". Trang này gom kết quả cả bốn câu. */
    public function status(): JsonResponse
    {
        $latest = DB::table('spike_heartbeats')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['source', 'note', 'created_at']);

        return response()->json([
            'now' => now()->toIso8601String(),
            'counts' => DB::table('spike_heartbeats')->selectRaw('source, count(*) as n, max(created_at) as last')->groupBy('source')->get(),
            'latest' => $latest,
        ]);
    }
}
