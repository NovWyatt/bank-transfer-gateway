<?php

namespace App\Payments\Ingest;

use App\Enums\ConnectionStatus;
use App\Models\InboundEvent;
use App\Models\ProviderConnection;
use App\Payments\Providers\AdapterRegistry;
use App\Payments\Providers\Exceptions\InvalidPayload;
use App\Payments\Providers\Exceptions\InvalidSignature;
use App\Tenancy\CurrentShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Nhận một webhook: xác thực, lưu request gốc, chuẩn hóa, ghi giao dịch và khớp đơn.
 * Mọi việc chạy ngay trong request, chỉ là vài câu SQL (xem mục 5.1 của kế hoạch).
 */
final class IngestWebhook
{
    public const MAX_BODY_BYTES = 65_536;

    /** Header chứa khóa hoặc chữ ký: che trước khi lưu vào inbound_events. */
    private const REDACTED_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'x-sepay-signature'];

    public function __construct(
        private readonly AdapterRegistry $adapters,
        private readonly RecordTransaction $recorder,
        private readonly CurrentShop $currentShop,
    ) {}

    public function handle(Request $request, ProviderConnection $connection): JsonResponse
    {
        $adapter = $this->adapters->for($connection->provider);

        // Tạm dừng: vẫn báo nhận để nhà cung cấp không gửi lại mãi, nhưng bỏ qua dữ liệu.
        if ($connection->status === ConnectionStatus::Paused) {
            return $adapter->acknowledge();
        }

        if (strlen($request->getContent()) > self::MAX_BODY_BYTES) {
            return $this->reject(413, 'Body quá lớn.');
        }

        // Từ đây mọi truy vấn model của shop tự lọc theo shop sở hữu kết nối.
        return $this->currentShop->runAs($connection->shop, function () use ($request, $connection, $adapter) {
            try {
                $adapter->verify($request, $connection);
            } catch (InvalidSignature $e) {
                $this->storeEvent($request, $connection, signatureValid: false, error: $e->getMessage());

                return $this->reject(401, 'Chữ ký không hợp lệ.');
            }

            $event = $this->storeEvent($request, $connection, signatureValid: true);

            try {
                $transactions = $adapter->parse($request);
            } catch (InvalidPayload $e) {
                $event->update(['error' => Str::limit($e->getMessage(), 250)]);

                return $this->reject(400, 'Payload không hợp lệ.');
            }

            foreach ($transactions as $transaction) {
                $this->recorder->record($transaction, $connection, $event);
            }

            $connection->forceFill(['last_received_at' => now()])->save();

            return $adapter->acknowledge();
        });
    }

    private function storeEvent(Request $request, ProviderConnection $connection, bool $signatureValid, ?string $error = null): InboundEvent
    {
        return InboundEvent::create([
            'provider_connection_id' => $connection->getKey(),
            'headers' => $this->redactHeaders($request),
            'body' => $request->getContent(),
            'signature_valid' => $signatureValid,
            'ip' => $request->ip(),
            'error' => $error === null ? null : Str::limit($error, 250),
            'received_at' => now(),
        ]);
    }

    /** @return array<string, string> */
    private function redactHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->headers->all() as $name => $values) {
            $headers[$name] = in_array($name, self::REDACTED_HEADERS, true)
                ? '[đã che]'
                : implode(', ', array_filter($values, is_string(...)));
        }

        return $headers;
    }

    private function reject(int $status, string $message): JsonResponse
    {
        // Không trả chi tiết lỗi cho bên gọi; chi tiết nằm trong inbound_events.
        return new JsonResponse(['success' => false, 'message' => $message], $status);
    }
}
