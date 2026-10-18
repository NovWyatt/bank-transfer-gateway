<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ConnectionStatus: string implements HasColor, HasLabel
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

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Active => 'success',
            self::Paused => 'gray',
        };
    }
}
