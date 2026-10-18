<?php

namespace App\Enums;

enum ConnectionStatus: string
{
    /** Nhận webhook bình thường. */
    case Active = 'active';

    /** Chủ shop tạm dừng: vẫn trả 200 cho nhà cung cấp nhưng không lưu, không khớp. */
    case Paused = 'paused';

    public function label(): string
    {
        return match ($this) {
            self::Active => 'Đang nhận',
            self::Paused => 'Tạm dừng',
        };
    }
}
