<?php

namespace App\Actions\Members;

use App\Enums\ShopRole;
use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ChangeMemberRole
{
    use GuardsLastOwner;

    /**
     * @throws DomainException khi hạ quyền owner cuối cùng
     */
    public function handle(Membership $membership, ShopRole $role, User $by, ?string $ip = null): Membership
    {
        return DB::transaction(function () use ($membership, $role, $by, $ip): Membership {
            $locked = Membership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();
            $from = $locked->role;

            if ($from === $role) {
                return $locked;
            }

            $this->ensureAnotherOwnerRemains($locked);

            $locked->update(['role' => $role]);

            AuditLog::record($locked->shop_id, $by, 'member.role_changed', $locked->user, [
                'from' => $from->value,
                'to' => $role->value,
            ], $ip);

            return $locked;
        }, 3);
    }
}
