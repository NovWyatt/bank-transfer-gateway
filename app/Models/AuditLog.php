<?php

namespace App\Models;

use App\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

#[Fillable(['user_id', 'action', 'subject_type', 'subject_id', 'meta', 'ip'])]
class AuditLog extends Model
{
    use BelongsToShop;

    /** Nhật ký chỉ ghi thêm, không sửa. */
    public const UPDATED_AT = null;

    /**
     * Ghi một dòng nhật ký. shop_id không nằm trong fillable nên ghi tường minh,
     * không dựa vào CurrentShop (đường nhận lời mời chạy khi chưa có shop hiện tại).
     *
     * @param  array<string, mixed>|null  $meta
     */
    public static function record(int $shopId, ?User $user, string $action, ?Model $subject = null, ?array $meta = null, ?string $ip = null): self
    {
        return static::query()->forceCreate([
            'shop_id' => $shopId,
            'user_id' => $user?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'meta' => $meta,
            'ip' => $ip,
        ]);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
