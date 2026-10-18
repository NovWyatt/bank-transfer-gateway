<?php

namespace App\Models;

use App\Enums\WebhookEventType;
use App\Tenancy\BelongsToShop;
use Database\Factories\WebhookEndpointFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Địa chỉ trên hệ thống của shop nhận webhook có chữ ký.
 *
 * secret không nằm trong fillable: chỉ đặt qua rotateSecret() hoặc tự sinh lúc tạo.
 */
#[Fillable(['url', 'description', 'events', 'active'])]
#[Hidden(['secret'])]
class WebhookEndpoint extends Model
{
    /** @use HasFactory<WebhookEndpointFactory> */
    use BelongsToShop, HasFactory;

    public const SECRET_PREFIX = 'whsec_';

    protected static function booted(): void
    {
        static::creating(function (self $endpoint): void {
            $endpoint->secret ??= self::generateSecret();
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'active' => 'boolean',
            'consecutive_failures' => 'integer',
            'failing_since' => 'immutable_datetime',
            'disabled_at' => 'immutable_datetime',
            'last_success_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function subscribesTo(WebhookEventType $type): bool
    {
        return in_array($type->value, $this->events ?? [], true);
    }

    public static function generateSecret(): string
    {
        return self::SECRET_PREFIX.Str::random(40);
    }

    /** Đặt secret mới và trả về để hiện cho owner đúng một lần. Secret cũ hết hiệu lực ngay. */
    public function rotateSecret(): string
    {
        $secret = self::generateSecret();
        $this->secret = $secret;
        $this->save();

        return $secret;
    }

    /** Bật lại sau khi tự tắt: xóa chuỗi hỏng để không bị tắt lại ngay ở lần hỏng kế tiếp. */
    public function enable(): void
    {
        $this->forceFill([
            'active' => true,
            'consecutive_failures' => 0,
            'failing_since' => null,
            'disabled_at' => null,
            'disabled_reason' => null,
        ])->save();
    }
}
