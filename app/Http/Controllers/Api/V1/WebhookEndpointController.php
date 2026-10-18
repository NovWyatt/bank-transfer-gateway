<?php

namespace App\Http\Controllers\Api\V1;

use App\Api\ApiError;
use App\Enums\DeliveryTrigger;
use App\Models\Shop;
use App\Models\WebhookEndpoint;
use App\Webhooks\WebhookDispatcher;
use App\Webhooks\WebhookSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Endpoint nhận webhook của shop. Tạo, sửa, đổi secret làm trên dashboard.
 */
class WebhookEndpointController
{
    /**
     * Gửi ngay một sự kiện webhook.ping có chữ ký tới endpoint, kể cả khi endpoint đang tắt,
     * rồi trả kết quả. Lần gửi được ghi vào nhật ký như mọi lần gửi khác.
     */
    public function test(Request $request, int $id, WebhookDispatcher $dispatcher, WebhookSender $sender): JsonResponse
    {
        /** @var Shop $shop */
        $shop = $request->user('sanctum');

        $endpoint = WebhookEndpoint::query()->where('shop_id', $shop->getKey())->find($id);

        if ($endpoint === null) {
            return ApiError::json(404, 'webhook_endpoint_not_found', 'Không có endpoint này trong shop.');
        }

        $delivery = $dispatcher->ping($endpoint);
        $attempt = $sender->send($delivery, DeliveryTrigger::Manual);

        return response()->json([
            'data' => [
                /** true khi endpoint trả 2xx trong vòng 10 giây. */
                'delivered' => $attempt->succeeded(),
                'event_id' => $delivery->event_id,
                'response_code' => $attempt->response_code,
                /** 2 KB đầu của phản hồi. */
                'response_body' => $attempt->response_body,
                /** Lỗi kết nối, quá giờ hoặc URL bị chặn; null khi đã nhận được phản hồi HTTP. */
                'error' => $attempt->error,
                'duration_ms' => $attempt->duration_ms,
            ],
        ]);
    }
}
