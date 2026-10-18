<?php

namespace App\Models;

use App\Payments\Banks\Bank;
use App\Payments\Banks\BankDirectory;
use App\Tenancy\BelongsToShop;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['bank_bin', 'bank_code', 'account_number', 'account_name', 'label', 'is_default'])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use BelongsToShop, HasFactory;

    protected static function booted(): void
    {
        // Mã viết tắt luôn theo BIN trong danh bạ, để không lệch khi đổi ngân hàng.
        static::saving(function (BankAccount $account): void {
            if ($account->isDirty('bank_bin')) {
                $account->bank_code = $account->bank()->code ?? $account->bank_code;
            }
        });

        // Mỗi shop chỉ một tài khoản mặc định: bật ở tài khoản này thì tắt ở các tài khoản khác.
        static::saved(function (BankAccount $account): void {
            if ($account->is_default) {
                static::query()
                    ->where('shop_id', $account->shop_id)
                    ->whereKeyNot($account->getKey())
                    ->where('is_default', true)
                    ->update(['is_default' => false]);
            }
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function bank(): ?Bank
    {
        return app(BankDirectory::class)->find($this->bank_bin);
    }
}
