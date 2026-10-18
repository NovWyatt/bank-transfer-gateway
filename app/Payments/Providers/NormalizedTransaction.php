<?php

namespace App\Payments\Providers;

use App\Enums\Provider;
use App\Enums\TransferDirection;
use Carbon\CarbonImmutable;

/**
 * Một giao dịch đã chuẩn hóa, không còn phụ thuộc nhà cung cấp.
 */
final readonly class NormalizedTransaction
{
    /**
     * @param  array<mixed>  $raw  phần payload gốc của riêng giao dịch này
     */
    public function __construct(
        public Provider $provider,
        /** Khóa chống trùng của nhà cung cấp (id của SePay, id của Pay2S). */
        public string $externalId,
        public string $accountNumber,
        /** Tên ngân hàng như nhà cung cấp gửi, ví dụ "Vietcombank" hoặc "ACB". */
        public ?string $gateway,
        public TransferDirection $direction,
        /** VND, số nguyên dương. */
        public int $amount,
        public string $content,
        public ?string $referenceCode,
        /** Mã đơn nhà cung cấp đã tách sẵn (code của SePay, paymentCode của Pay2S). */
        public ?string $providerCode,
        /** Đã đổi sang UTC. */
        public CarbonImmutable $transactedAt,
        public ?string $payerName,
        public ?string $payerAccount,
        public array $raw,
    ) {}
}
