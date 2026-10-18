<?php

namespace App\Actions\Members;

use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use App\Tenancy\ShopScope;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Nhận lời mời: thêm user vào shop với vai trò trong lời mời. Mỗi lời mời dùng một lần.
 */
final class AcceptInvitation
{
    /**
     * @throws DomainException khi lời mời đã dùng, đã hết hạn, hoặc gửi tới email khác
     */
    public function handle(ShopInvitation $invitation, User $user, ?string $ip = null): Shop
    {
        return DB::transaction(function () use ($invitation, $user, $ip): Shop {
            // Khóa để hai lần bấm (hai tab) không cùng nhận một lời mời.
            $locked = ShopInvitation::withoutGlobalScope(ShopScope::class)
                ->whereKey($invitation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->isUsable()) {
                throw new DomainException('Lời mời đã được dùng hoặc đã hết hạn.');
            }

            if (ShopInvitation::normalizeEmail($user->email) !== $locked->email) {
                throw new DomainException("Lời mời này gửi tới {$locked->email}, không phải tài khoản đang đăng nhập.");
            }

            $shop = Shop::query()->findOrFail($locked->shop_id);

            // Đã là thành viên (ví dụ được thêm cách khác trong lúc chờ) thì giữ vai trò đang có.
            if ($shop->members()->whereKey($user->getKey())->doesntExist()) {
                $shop->members()->attach($user, ['role' => $locked->role->value]);
            }

            $locked->update(['accepted_at' => now(), 'accepted_by' => $user->getKey()]);

            AuditLog::record($shop->getKey(), $user, 'member.joined', $locked, [
                'email' => $locked->email,
                'role' => $locked->role->value,
            ], $ip);

            return $shop;
        }, 3);
    }
}
