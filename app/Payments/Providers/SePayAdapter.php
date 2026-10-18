<?php

namespace App\Payments\Providers;

use App\Enums\Provider;
use App\Enums\TransferDirection;
use App\Models\ProviderConnection;
use App\Payments\Providers\Exceptions\InvalidPayload;
use App\Payments\Providers\Exceptions\InvalidSignature;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Webhook của SePay: mỗi request một giao dịch.
 * Nguồn: https://developer.sepay.vn/en/sepay-webhooks/tich-hop-webhook
 *
 * Thông tin xác thực của kết nối: {"secret": "..."} cho HMAC-SHA256 (khuyên dùng),
 * hoặc {"api_key": "..."} cho kiểu "Authorization: Apikey ...". Không nhận kiểu "None".
 * Body nên chọn application/json: với multipart/form-data, PHP không giữ body gốc để kiểm HMAC.
 */
class SePayAdapter implements ProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::SePay;
    }

    public function verify(Request $request, ProviderConnection $connection): void
    {
        $secret = $connection->credential('secret');

        if ($secret !== null) {
            SePaySignature::verify(
                $request->getContent(),
                $secret,
                $request->header(SePaySignature::SIGNATURE_HEADER),
                $request->header(SePaySignature::TIMESTAMP_HEADER),
                now()->getTimestamp(),
            );

            return;
        }

        $apiKey = $connection->credential('api_key');

        if ($apiKey !== null) {
            $authorization = (string) $request->header('Authorization', '');

            if (! str_starts_with($authorization, 'Apikey ') || ! hash_equals($apiKey, substr($authorization, 7))) {
                throw new InvalidSignature('API key không khớp.');
            }

            return;
        }

        throw new InvalidSignature('Kết nối chưa có khóa xác thực.');
    }

    public function parse(Request $request): array
    {
        $data = PayloadReader::decodeBody($request->getContent(), $request->header('Content-Type'));
        $reader = new PayloadReader($data, $this->provider()->value);

        return [new NormalizedTransaction(
            provider: $this->provider(),
            // "Gửi thử" trên dashboard SePay dùng id giả, có thể là 0.
            externalId: (string) $reader->unsignedInt('id'),
            accountNumber: $reader->string('accountNumber'),
            gateway: $reader->optionalString('gateway'),
            direction: TransferDirection::tryFromProvider($reader->string('transferType'))
                ?? throw new InvalidPayload('Trường transferType phải là in hoặc out.'),
            amount: $reader->positiveInt('transferAmount'),
            content: $reader->string('content'),
            referenceCode: $reader->optionalString('referenceCode'),
            // code = null nghĩa là SePay không tách được mã theo cấu hình của shop.
            providerCode: $reader->optionalString('code'),
            transactedAt: $reader->vietnamTime('transactionDate'),
            payerName: null,
            payerAccount: null,
            raw: $data,
        )];
    }

    public function acknowledge(): JsonResponse
    {
        // SePay chỉ coi là thành công khi HTTP 200/201 và body có success = true.
        return new JsonResponse(['success' => true]);
    }
}
