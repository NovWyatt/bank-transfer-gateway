<?php

namespace App\Actions\Members;

use App\Enums\ShopRole;
use App\Models\Membership;
use DomainException;

/**
 * Shop luôn còn ít nhất một owner. Gọi trong DB transaction.
 */
trait GuardsLastOwner
{
    /**
     * Khóa mọi dòng owner của shop rồi mới đếm: hai owner hạ quyền nhau cùng lúc thì lệnh sau
     * phải chờ lệnh trước commit, và thấy đúng số owner còn lại.
     *
     * @throws DomainException khi $membership là owner cuối cùng
     */
    private function ensureAnotherOwnerRemains(Membership $membership): void
    {
        if ($membership->role !== ShopRole::Owner) {
            return;
        }

        $otherOwners = Membership::query()
            ->where('shop_id', $membership->shop_id)
            ->where('role', ShopRole::Owner)
            ->lockForUpdate()
            ->pluck('id')
            ->reject(fn (int $id): bool => $id === $membership->getKey());

        if ($otherOwners->isEmpty()) {
            throw new DomainException('Shop phải còn ít nhất một chủ shop.');
        }
    }
}
