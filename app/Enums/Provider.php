<?php

namespace App\Enums;

enum Provider: string
{
    case SePay = 'sepay';
    case Pay2S = 'pay2s';
    case PayOS = 'payos';

    /** Giả lập cho demo và test: ký HMAC như SePay, đi đúng đường nhận thật. */
    case Simulator = 'simulator';

    public function label(): string
    {
        return match ($this) {
            self::SePay => 'SePay',
            self::Pay2S => 'Pay2S',
            self::PayOS => 'payOS',
            self::Simulator => 'Giả lập',
        };
    }
}
