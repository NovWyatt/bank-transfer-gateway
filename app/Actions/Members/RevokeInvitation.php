<?php

namespace App\Actions\Members;

use App\Models\AuditLog;
use App\Models\ShopInvitation;
use App\Models\User;
use DomainException;

final class RevokeInvitation
{
    /**
     * @throws DomainException khi lời mời đã được nhận (lúc đó xóa thành viên thay vì thu hồi)
     */
    public function handle(ShopInvitation $invitation, User $by, ?string $ip = null): void
    {
        if ($invitation->accepted_at !== null) {
            throw new DomainException('Lời mời đã được nhận. Muốn bỏ quyền thì xóa thành viên đó.');
        }

        $invitation->delete();

        AuditLog::record($invitation->shop_id, $by, 'member.invitation_revoked', null, [
            'email' => $invitation->email,
            'role' => $invitation->role->value,
        ], $ip);
    }
}
