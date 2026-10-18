<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum MatchedBy: string implements HasLabel
{
    case Rule = 'rule';
    case Manual = 'manual';
    case SuggestionApproved = 'suggestion_approved';

    public function getLabel(): string
    {
        return match ($this) {
            self::Rule => 'Tự động theo mã đơn',
            self::Manual => 'Khớp tay',
            self::SuggestionApproved => 'Duyệt gợi ý',
        };
    }
}
