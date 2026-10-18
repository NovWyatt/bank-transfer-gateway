<?php

namespace App\Models;

use App\Enums\OrderSource;
use App\Enums\OrderStatus;
use App\Tenancy\BelongsToShop;
use Carbon\CarbonInterface;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'bank_account_id', 'code', 'external_ref', 'amount', 'paid_amount', 'status', 'description',
    'customer_name', 'expires_at', 'paid_at', 'paid_after_expiry', 'created_via', 'metadata',
])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use BelongsToShop, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'paid_amount' => 'integer',
            'status' => OrderStatus::class,
            'expires_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'paid_after_expiry' => 'boolean',
            'created_via' => OrderSource::class,
            'metadata' => 'array',
        ];
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return HasMany<Transaction, $this> */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    /** Số tiền còn phải trả, không âm. */
    public function remainingAmount(): int
    {
        return max(0, $this->amount - $this->paid_amount);
    }

    public function isPastExpiry(?CarbonInterface $now = null): bool
    {
        return $this->expires_at->lessThanOrEqualTo($now ?? now());
    }
}
