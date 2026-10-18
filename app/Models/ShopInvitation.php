<?php

namespace App\Models;

use App\Enums\ShopRole;
use App\Tenancy\BelongsToShop;
use Carbon\CarbonInterface;
use Database\Factories\ShopInvitationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Lời mời vào shop. Owner gửi link cho người được mời; ai có link thì nhận được,
 * nhưng chỉ dưới đúng email ghi trong lời mời.
 */
#[Fillable(['email', 'role', 'token_hash', 'invited_by', 'expires_at', 'accepted_at', 'accepted_by'])]
class ShopInvitation extends Model
{
    /** @use HasFactory<ShopInvitationFactory> */
    use BelongsToShop, HasFactory;

    public const TTL_DAYS = 7;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => ShopRole::class,
            'expires_at' => 'immutable_datetime',
            'accepted_at' => 'immutable_datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /** @return BelongsTo<User, $this> */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    /**
     * Lời mời chưa ai nhận (kể cả đã hết hạn: owner tạo link mới từ chính lời mời đó).
     *
     * @param  Builder<ShopInvitation>  $query
     */
    #[Scope]
    protected function open(Builder $query): void
    {
        $query->whereNull('accepted_at');
    }

    public function isUsable(?CarbonInterface $now = null): bool
    {
        return $this->accepted_at === null && $this->expires_at->greaterThan($now ?? now());
    }
}
