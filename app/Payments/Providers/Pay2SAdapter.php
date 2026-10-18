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
 * Webhook của Pay2S: body {"transactions": [...]}, xác thực bằng Bearer token.
 * Nguồn: https://developer.pay2s.vn/docs/webhook/tai-lieu-ky-thuat/
 *
 * Thông tin xác thực của kết nối: {"token": "..."} (Pay2S chỉ hiện token một lần khi tạo Hook).
 * Pay2S không ký body và không gửi timestamp, nên không chống được gửi lại request cũ;
 * khóa chống trùng theo id vẫn chặn ghi hai lần.
 */
final class Pay2SAdapter implements ProviderAdapter
{
    public function provider(): Provider
    {
        return Provider::Pay2S;
    }

    public function verify(Request $request, ProviderConnection $connection): void
    {
        $token = $connection->credential('token')
            ?? throw new InvalidSignature('Kết nối chưa có token.');

        $authorization = (string) $request->header('Authorization', '');

        // Tên kiểu xác thực trong HTTP không phân biệt hoa thường.
        if (! preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches) || ! hash_equals($token, $matches[1])) {
            throw new InvalidSignature('Bearer token không khớp.');
        }
    }

    public function parse(Request $request): array
    {
        $data = PayloadReader::decodeBody($request->getContent(), $request->header('Content-Type'));
        $items = $data['transactions'] ?? null;

        // Tài liệu nói mỗi request một giao dịch, nhưng body là mảng nên vẫn duyệt hết.
        if (! is_array($items) || $items === [] || ! array_is_list($items)) {
            throw new InvalidPayload('Thiếu mảng transactions.');
        }

        $transactions = [];

        foreach ($items as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidPayload("Phần tử transactions[{$index}] phải là object.");
            }

            $reader = new PayloadReader($item, "pay2s.transactions[{$index}]");
            // extraData chỉ có khi shop bật; thiếu thông tin thì Pay2S gửi "N/A".
            $extra = $reader->nested('extraData');

            $transactions[] = new NormalizedTransaction(
                provider: Provider::Pay2S,
                externalId: (string) $reader->unsignedInt('id'),
                // Với tài khoản ảo, Pay2S gửi "963869" + số VA thay cho tài khoản gốc.
                accountNumber: $reader->string('accountNumber'),
                gateway: $reader->optionalString('gateway'),
                direction: TransferDirection::tryFromProvider($reader->string('transferType'))
                    ?? throw new InvalidPayload("Trường transactions[{$index}].transferType phải là IN hoặc OUT."),
                amount: $reader->positiveInt('transferAmount'),
                content: $reader->string('content'),
                referenceCode: $reader->optionalString('transactionNumber'),
                providerCode: $reader->optionalString('paymentCode'),
                transactedAt: $reader->vietnamTime('transactionDate'),
                payerName: $extra->optionalString('remitterName'),
                payerAccount: $extra->optionalString('remitterAccountNumber'),
                raw: $item,
            );
        }

        return $transactions;
    }

    public function acknowledge(): JsonResponse
    {
        // Pay2S cần HTTP 2xx và body có success = true.
        return new JsonResponse(['success' => true]);
    }
}
