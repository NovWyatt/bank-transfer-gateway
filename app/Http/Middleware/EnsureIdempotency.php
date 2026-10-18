<?php

namespace App\Http\Middleware;

use App\Models\IdempotencyKey;
use App\Models\Shop;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Header Idempotency-Key cho POST: gửi lại cùng khóa, cùng body thì nhận lại đúng phản hồi cũ,
 * không tạo thêm đơn. Dùng khi mạng chập chờn và phía shop không biết lần trước đã tới chưa.
 *
 * Chỉ lưu phản hồi 2xx. Lỗi kiểm tra dữ liệu hay lỗi máy chủ thì xóa khóa, để gửi lại với cùng khóa được.
 */
final class EnsureIdempotency
{
    public const HEADER = 'Idempotency-Key';

    public const REPLAYED_HEADER = 'Idempotent-Replayed';

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header(self::HEADER);

        if ($key === null || ! $request->isMethod('POST')) {
            return $next($request);
        }

        if (preg_match('/^[\x21-\x7E]{1,255}$/', $key) !== 1) {
            return self::error(400, 'idempotency_key_invalid', 'Idempotency-Key gồm 1 đến 255 ký tự ASCII in được, không có dấu cách.');
        }

        /** @var Shop $shop */
        $shop = $request->user('sanctum');
        $hash = hash('sha256', $request->method().' '.$request->path()."\n".$request->getContent());

        $stored = IdempotencyKey::query()->where('shop_id', $shop->getKey())->where('key', $key)->first();

        if ($stored !== null && $stored->expires_at->isPast()) {
            $stored->delete();
            $stored = null;
        }

        if ($stored !== null) {
            return $this->answerFromStored($stored, $hash);
        }

        try {
            // Ghi khóa trước khi chạy (tự commit): request trùng chạy song song sẽ vướng UNIQUE.
            $record = IdempotencyKey::query()->create([
                'shop_id' => $shop->getKey(),
                'key' => $key,
                'request_hash' => $hash,
                'expires_at' => now()->addHours(IdempotencyKey::TTL_HOURS),
            ]);
        } catch (UniqueConstraintViolationException) {
            return self::inProgress();
        }

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $record->delete();

            throw $e;
        }

        if ($response->isSuccessful()) {
            $record->update([
                'response_status' => $response->getStatusCode(),
                'response_body' => $response->getContent(),
            ]);
        } else {
            $record->delete();
        }

        return $response;
    }

    private function answerFromStored(IdempotencyKey $stored, string $hash): Response
    {
        if (! hash_equals($stored->request_hash, $hash)) {
            return self::error(422, 'idempotency_key_reused', 'Idempotency-Key này đã dùng cho một request khác. Mỗi request mới cần khóa mới.');
        }

        if ($stored->response_status === null) {
            return self::inProgress();
        }

        return new HttpResponse((string) $stored->response_body, $stored->response_status, [
            'Content-Type' => 'application/json',
            self::REPLAYED_HEADER => 'true',
        ]);
    }

    private static function inProgress(): JsonResponse
    {
        return self::error(409, 'idempotency_request_in_progress', 'Request đầu tiên với Idempotency-Key này chưa xong. Thử lại sau vài giây.');
    }

    private static function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['message' => $message, 'code' => $code], $status);
    }
}
