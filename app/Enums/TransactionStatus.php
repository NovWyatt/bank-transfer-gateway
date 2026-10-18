<?php

namespace App\Enums;

enum TransactionStatus: string
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
}
