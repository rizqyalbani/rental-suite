<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\ExcludesTutorialData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class Outfit extends Model
{
    use BelongsToStore;
    use ExcludesTutorialData;

    protected $fillable = ['store_id', 'code', 'new_code', 'description', 'price', 'stock', 'qr_redirect_url', 'is_tutorial'];

    protected function casts(): array
    {
        return [
            'is_tutorial' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Outfit $outfit) {
            if (empty($outfit->qr_token)) {
                $outfit->qr_token = Str::random(48);
            }
        });
    }

    protected $attributes = [
        'stock' => 1,
    ];

    public function rentals(): BelongsToMany
    {
        return $this->belongsToMany(Rental::class, 'rental_outfit');
    }

    /**
     * Item codes for a list of outfit ids, in the same order — for activity-log
     * display, where a raw id ("206") means nothing to a non-technical user
     * but a code ("BR 21") does. Falls back to "#<id>" for a since-deleted outfit.
     *
     * @param  array<int>  $outfitIds
     * @return array<string>
     */
    public static function codesForIds(array $outfitIds): array
    {
        $codesById = static::withoutGlobalScope('tutorial_scope')
            ->whereIn('id', $outfitIds)
            ->pluck('code', 'id');

        return array_map(
            fn ($id) => $codesById->get((int) $id) ?? ('#'.$id),
            $outfitIds
        );
    }

    /**
     * Pivot-linked rentals whose lifecycle status is still active (picked up / not yet returned).
     */
    public function activeRentals(): BelongsToMany
    {
        return $this->rentals()->where('rentals.status', 'active');
    }

    /**
     * True if any non-returned rental blocks deleting this item (pivot + optional legacy rentals.outfit_id).
     */
    public function hasActiveRentalsBlockingDelete(): bool
    {
        if ($this->activeRentals()->exists()) {
            return true;
        }

        if (! Schema::hasColumn('rentals', 'outfit_id')) {
            return false;
        }

        return Rental::query()
            ->where('outfit_id', $this->id)
            ->where('rentals.status', 'active')
            ->exists();
    }
}
