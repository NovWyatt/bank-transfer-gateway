<?php

namespace App\Enums;

enum ShopRole: string
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
}
