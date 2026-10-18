<?php

namespace App\Actions\Members;

use App\Enums\ShopRole;
use App\Models\AuditLog;
use App\Models\Shop;
use App\Models\ShopInvitation;
use App\Models\User;
use App\Tenancy\ShopScope;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tạo lời mời, hoặc làm mới lời mời chưa ai nhận của cùng email (token mới, hạn mới, vai trò mới).
 * Link cũ hết dùng được ngay khi làm mới.
 */
final class InviteMember
{
    public const TOKEN_LENGTH = 48;

    /**
     * $by là null khi lời mời do hệ thống tạo: lời mời owner đầu tiên từ /internal/bootstrap.
     *
     * @throws DomainException khi email đã là thành viên
     */
    public function handle(Shop $shop, string $email, ShopRole $role, ?User $by, ?string $ip = null): IssuedInvitation
    {
        $email = ShopInvitation::normalizeEmail($email);

        // Cột email dùng collation không phân biệt hoa thường, nên so thẳng là đủ.
        if ($shop->members()->where('users.email', $email)->exists()) {
            throw new DomainException("{$email} đã là thành viên của shop.");
        }

        $token = Str::random(self::TOKEN_LENGTH);

        $invitation = DB::transaction(function () use ($shop, $email, $role, $by, $ip, $token): ShopInvitation {
            $invitation = ShopInvitation::withoutGlobalScope(ShopScope::class)
                ->where('shop_id', $shop->getKey())
                ->where('email', $email)
                ->open()
                ->lockForUpdate()
                ->first() ?? new ShopInvitation(['email' => $email]);

            $invitation->shop_id = $shop->getKey();
            $invitation->fill([
                'role' => $role,
                'token_hash' => ShopInvitation::hashToken($token),
                'invited_by' => $by?->getKey(),
                'expires_at' => now()->addDays(ShopInvitation::TTL_DAYS),
            ])->save();

            AuditLog::record($shop->getKey(), $by, $invitation->wasRecentlyCreated ? 'member.invited' : 'member.invitation_renewed', $invitation, [
                'email' => $email,
                'role' => $role->value,
            ], $ip);

            return $invitation;
        }, 3);

        return new IssuedInvitation($invitation, $token);
    }
}
