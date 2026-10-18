<?php

namespace App\Tenancy;

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Models\User;
use Filament\Facades\Filament;

/**
 * Quyền trong shop đang mở ở dashboard.
 * Owner quản lý tài khoản ngân hàng, kết nối, webhook, token và thành viên; staff xem và tạo đơn.
 */
final class ShopAccess
{
    public static function role(): ?ShopRole
    {
        $user = Filament::auth()->user();
        $shop = Filament::getTenant();

        if (! $user instanceof User || ! $shop instanceof Shop) {
            return null;
        }

        return $user->roleIn($shop);
    }

    public static function isOwner(): bool
    {
        return self::role() === ShopRole::Owner;
    }

    public static function isMember(): bool
    {
        return self::role() !== null;
    }
}
