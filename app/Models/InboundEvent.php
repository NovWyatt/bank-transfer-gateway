<?php

namespace App\Models;

use App\Tenancy\BelongsToShop;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['provider_connection_id', 'headers', 'body', 'signature_valid', 'ip', 'error', 'received_at'])]
#[WithoutTimestamps]
class InboundEvent extends Model
{
    use BelongsToShop;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'headers' => 'array',
            'signature_valid' => 'boolean',
            'received_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ProviderConnection, $this> */
    public function connection(): BelongsTo
    {
        return $this->belongsTo(ProviderConnection::class, 'provider_connection_id');
    }
}
