<?php

namespace App\Enums;

use Filament\Support\Contracts\HasColor;
use Filament\Support\Contracts\HasLabel;

enum ShopRole: string implements HasColor, HasLabel
{
    /** Quản lý kết nối, webhook, token API và thành viên. */
    case Owner = 'owner';

    /** Xem đơn, tạo đơn, duyệt gợi ý. */
    case Staff = 'staff';

    public function label(): string
    {
        return match ($this) {
            self::Owner => 'Chủ shop',
            self::Staff => 'Nhân viên',
        };
    }

    public function getLabel(): string
    {
        return $this->label();
    }

    public function getColor(): string
    {
        return match ($this) {
            self::Owner => 'info',
            self::Staff => 'gray',
        };
    }
}
