<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum OrderStatus: string implements HasColor, HasLabel
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

    public function getLabel(): string
    {
        return $this->label();
    }

    /** Màu badge trên dashboard: vàng là việc shop phải xử lý (đòi thêm hoặc trả lại tiền). */
    public function getColor(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::PartiallyPaid, self::Overpaid => 'warning',
            self::Paid => 'success',
            self::Expired, self::Cancelled => 'gray',
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
