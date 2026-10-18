<?php

namespace App\Payments;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Payments\Banks\BankDirectory;
use App\Payments\VietQr\VietQrPayload;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Những gì trang /pay và API trạng thái cần biết về một đơn, đã tính sẵn.
 * Blade chỉ trình bày; mọi quyết định (còn cho chuyển không, chuyển bao nhiêu) nằm ở đây.
 */
final readonly class PaymentView
{
    /** Trạng thái hiển thị: khác OrderStatus ở chỗ đơn "pending" đã quá hạn được coi là hết hạn ngay. */
    public const WAITING = 'waiting';

    public const PARTIAL = 'partial';

    public const PAID = 'paid';

    public const OVERPAID = 'overpaid';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    private function __construct(
        public string $state,
        public string $code,
        public int $amount,
        public int $paidAmount,
        /** Số còn phải chuyển; là số tiền nằm trong QR. */
        public int $remaining,
        public string $shopName,
        /** Múi giờ của shop, để hiện giờ hết hạn và giờ nhận tiền theo giờ địa phương. */
        public string $timezone,
        public ?string $description,
        public string $bankName,
        public string $bankShortName,
        public string $bankBin,
        public string $accountNumber,
        public string $accountName,
        public CarbonImmutable $expiresAt,
        public ?CarbonImmutable $paidAt,
    ) {}

    public static function for(Order $order, BankDirectory $banks, ?CarbonInterface $now = null): self
    {
        $account = $order->bankAccount;
        $bank = $banks->find($account->bank_bin);

        return new self(
            state: self::stateOf($order, $now ?? now()),
            code: $order->code,
            amount: $order->amount,
            paidAmount: $order->paid_amount,
            remaining: $order->remainingAmount(),
            shopName: $order->shop->name,
            timezone: $order->shop->timezone,
            description: $order->description,
            bankName: $bank->name ?? $account->bank_code,
            bankShortName: $bank->shortName ?? $account->bank_code,
            bankBin: $account->bank_bin,
            accountNumber: $account->account_number,
            accountName: $account->account_name,
            expiresAt: $order->expires_at,
            paidAt: $order->paid_at,
        );
    }

    /** Còn mời khách chuyển tiền không. Hết hạn hay đã hủy thì không hiện QR, để khách không chuyển thêm. */
    public function acceptsTransfer(): bool
    {
        return in_array($this->state, [self::WAITING, self::PARTIAL], true);
    }

    public function isSettled(): bool
    {
        return in_array($this->state, [self::PAID, self::OVERPAID], true);
    }

    /** Giờ theo múi giờ của shop, dạng "14:02, 18/10". */
    public function localTime(CarbonImmutable $time): string
    {
        return $time->setTimezone($this->timezone)->format('H:i, d/m');
    }

    /** Chuỗi VietQR: số tiền là phần còn thiếu, nội dung là mã đơn. */
    public function qrPayload(): string
    {
        return VietQrPayload::build($this->bankBin, $this->accountNumber, $this->remaining, $this->code);
    }

    /** Dấu vết để trang biết trạng thái đã đổi: đổi trạng thái hoặc nhận thêm tiền. */
    public function signature(): string
    {
        return $this->state.':'.$this->paidAmount;
    }

    /** @return array{state: string, signature: string, amount: int, paid_amount: int, remaining: int, expires_at: string, paid_at: string|null} */
    public function toStatusArray(): array
    {
        return [
            'state' => $this->state,
            'signature' => $this->signature(),
            'amount' => $this->amount,
            'paid_amount' => $this->paidAmount,
            'remaining' => $this->remaining,
            'expires_at' => $this->expiresAt->toIso8601ZuluString(),
            'paid_at' => $this->paidAt?->toIso8601ZuluString(),
        ];
    }

    private static function stateOf(Order $order, CarbonInterface $now): string
    {
        return match ($order->status) {
            OrderStatus::Paid => self::PAID,
            OrderStatus::Overpaid => self::OVERPAID,
            OrderStatus::Cancelled => self::CANCELLED,
            OrderStatus::Expired => self::EXPIRED,
            OrderStatus::Pending, OrderStatus::PartiallyPaid => match (true) {
                $order->isPastExpiry($now) => self::EXPIRED,
                $order->status === OrderStatus::PartiallyPaid => self::PARTIAL,
                default => self::WAITING,
            },
        };
    }
}
