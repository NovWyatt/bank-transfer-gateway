<?php

namespace App\Enums;

use Filament\Support\Contracts\HasLabel;

/** Ai khởi động một lần gửi webhook. */
enum DeliveryTrigger: string implements HasLabel
{
    /** Nhịp mỗi phút, theo lịch gửi lại. */
    case Automatic = 'auto';

    /** Owner bấm "Gửi lại" hoặc "Gửi thử", hay gọi API /test. Không tính vào lịch gửi lại. */
    case Manual = 'manual';

    public function getLabel(): string
    {
        return match ($this) {
            self::Automatic => 'Tự động',
            self::Manual => 'Gửi tay',
        };
    }
}
