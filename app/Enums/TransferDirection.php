<?php

namespace App\Enums;

enum TransferDirection: string
{
    case In = 'in';
    case Out = 'out';

    /** SePay gửi "in"/"out", Pay2S gửi "IN"/"OUT". */
    public static function fromProvider(string $value): self
    {
        return self::from(strtolower(trim($value)));
    }
}
