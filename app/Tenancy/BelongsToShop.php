<?php

namespace App\Tenancy;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Model thuộc về một shop: tự lọc theo shop hiện tại và tự gán shop_id khi tạo.
 *
 * @mixin Model
 */
trait BelongsToShop
{
    public static function bootBelongsToShop(): void
    {
        static::addGlobalScope(new ShopScope);

        static::creating(function (Model $model): void {
            $shopId = app(CurrentShop::class)->id();

            if ($model->getAttribute('shop_id') === null && $shopId !== null) {
                $model->setAttribute('shop_id', $shopId);
            }
        });
    }

    /** @return BelongsTo<Shop, $this> */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
