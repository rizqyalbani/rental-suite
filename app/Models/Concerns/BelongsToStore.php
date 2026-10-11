<?php

namespace App\Models\Concerns;

use App\Models\Store;
use App\Support\StoreContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

trait BelongsToStore
{
    public static function bootBelongsToStore(): void
    {
        static::creating(function ($model): void {
            if (! $model->store_id) {
                $storeId = StoreContext::storeId();
                if ($storeId === null) {
                    $storeId = (int) (Store::query()->orderBy('id')->value('id') ?? 0);
                }
                if ($storeId > 0) {
                    $model->store_id = $storeId;
                }
            }
        });

        static::addGlobalScope('store_scope', function (Builder $query): void {
            $storeId = StoreContext::storeId();
            if ($storeId === null || StoreContext::allStores()) {
                return;
            }
            $query->where($query->qualifyColumn('store_id'), $storeId);
        });
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(Store::class);
    }
}
