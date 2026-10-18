<?php

namespace App\Models;

use App\Enums\ConnectionStatus;
use App\Enums\Provider;
use App\Tenancy\BelongsToShop;
use Database\Factories\ProviderConnectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasVersion4Uuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một điểm nhận webhook của một nhà cung cấp cho một shop.
 *
 * credentials tùy nhà cung cấp, ví dụ SePay: {"secret": "..."}; Pay2S: {"token": "..."}.
 */
#[Fillable(['provider', 'bank_account_id', 'label', 'credentials', 'status'])]
#[Hidden(['credentials'])]
class ProviderConnection extends Model
{
    /** @use HasFactory<ProviderConnectionFactory> */
    use BelongsToShop, HasFactory, HasVersion4Uuids;

    /**
     * Chỉ cột uuid là UUID, khóa chính vẫn là id tự tăng.
     *
     * @return list<string>
     */
    public function uniqueIds(): array
    {
        return ['uuid'];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'credentials' => 'encrypted:array',
            'status' => ConnectionStatus::class,
            'last_received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function credential(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
