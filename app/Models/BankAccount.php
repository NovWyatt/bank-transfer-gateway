<?php

namespace App\Models;

use App\Payments\Banks\Bank;
use App\Payments\Banks\BankDirectory;
use App\Tenancy\BelongsToShop;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['bank_bin', 'bank_code', 'account_number', 'account_name', 'label', 'is_default'])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use BelongsToShop, HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
        ];
    }

    public function bank(): ?Bank
    {
        return app(BankDirectory::class)->find($this->bank_bin);
    }
}
