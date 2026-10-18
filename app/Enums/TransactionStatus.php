<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum TransactionStatus: string implements HasColor, HasLabel
{
    case Matched = 'matched';
    case Unmatched = 'unmatched';
    case Suggested = 'suggested';

    /** Tiền ra, hoặc giao dịch người duyệt bỏ qua. */
    case Ignored = 'ignored';

    /** Cùng giao dịch đã đến qua nhà cung cấp khác. */
    case Duplicate = 'duplicate';

    public function label(): string
    {
        return match ($this) {
            self::Matched => 'Đã khớp',
            self::Unmatched => 'Chưa khớp',
            self::Suggested => 'Có gợi ý',
            self::Ignored => 'Bỏ qua',
            self::Duplicate => 'Trùng',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Matched => 'success',
            self::Unmatched => 'warning',
            self::Suggested => 'info',
            self::Ignored, self::Duplicate => 'gray',
        };
    }
}
