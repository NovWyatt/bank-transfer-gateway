<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

enum TransferDirection: string implements HasLabel
{
    case In = 'in';
    case Out = 'out';

    /** SePay gửi "in"/"out", Pay2S gửi "IN"/"OUT". */
    public static function tryFromProvider(string $value): ?self
    {
        return self::tryFrom(strtolower(trim($value)));
    }

    public function getLabel(): string
    {
        return match ($this) {
            self::In => 'Tiền vào',
            self::Out => 'Tiền ra',
        };
    }
}
