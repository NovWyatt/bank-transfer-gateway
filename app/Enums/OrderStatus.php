<?php

namespace App\Enums;

enum OrderStatus: string
{
    case Pending = 'pending';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Overpaid = 'overpaid';
    case Expired = 'expired';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Chờ thanh toán',
            self::PartiallyPaid => 'Trả thiếu',
            self::Paid => 'Đã thanh toán',
            self::Overpaid => 'Trả dư',
            self::Expired => 'Hết hạn',
            self::Cancelled => 'Đã hủy',
        };
    }

    /** Còn nhận tiền vào được (đơn hết hạn còn xét thêm thời gian ân hạn). */
    public function acceptsPayment(): bool
    {
        return in_array($this, [self::Pending, self::PartiallyPaid, self::Expired], true);
    }

    /** Đã nhận đủ tiền (có thể dư). */
    public function isSettled(): bool
    {
        return in_array($this, [self::Paid, self::Overpaid], true);
    }
}
