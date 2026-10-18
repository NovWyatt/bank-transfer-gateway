<?php

namespace App\Actions\Members;

use App\Models\AuditLog;
use App\Models\Membership;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Xóa user khỏi shop. Tài khoản user vẫn còn (có thể là thành viên shop khác).
 */
final class RemoveMember
{
    use GuardsLastOwner;

    /**
     * @throws DomainException khi xóa owner cuối cùng
     */
    public function handle(Membership $membership, User $by, ?string $ip = null): void
    {
        DB::transaction(function () use ($membership, $by, $ip): void {
            $locked = Membership::query()->whereKey($membership->getKey())->lockForUpdate()->firstOrFail();

            $this->ensureAnotherOwnerRemains($locked);

            $locked->delete();

            AuditLog::record($locked->shop_id, $by, 'member.removed', $locked->user, [
                'role' => $locked->role->value,
            ], $ip);
        }, 3);
    }
}
