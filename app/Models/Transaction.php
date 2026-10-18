<?php

namespace App\Models;

use App\Enums\MatchedBy;
use App\Enums\Provider;
use App\Enums\TransactionStatus;
use App\Enums\TransferDirection;
use App\Tenancy\BelongsToShop;
use Database\Factories\TransactionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Một giao dịch ngân hàng đã chuẩn hóa, nhận từ nhà cung cấp.
 */
#[Fillable([
    'provider_connection_id', 'inbound_event_id', 'provider', 'external_id', 'bank_account_id',
    'direction', 'amount', 'content', 'reference_code', 'provider_code', 'transacted_at',
    'payer_name', 'payer_account', 'raw', 'fingerprint', 'status', 'order_id', 'matched_by',
    'matched_by_user_id', 'matched_at', 'duplicate_of',
])]
class Transaction extends Model
{
    /** @use HasFactory<TransactionFactory> */
    use BelongsToShop, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'provider' => Provider::class,
            'direction' => TransferDirection::class,
            'amount' => 'integer',
            'transacted_at' => 'immutable_datetime',
            'raw' => 'array',
            'status' => TransactionStatus::class,
            'matched_by' => MatchedBy::class,
            'matched_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProviderConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }

    /** @return BelongsTo<InboundEvent, $this> */
    public function inboundEvent(): BelongsTo
    {
        return $this->belongsTo(InboundEvent::class);
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function matchedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'matched_by_user_id');
    }

    /**
     * Giao dịch gốc khi giao dịch này là bản trùng đến qua nhà cung cấp khác.
     *
     * @return BelongsTo<Transaction, $this>
     */
    public function duplicateSource(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of');
    }
}
