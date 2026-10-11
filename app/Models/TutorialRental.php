<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A Rental subclass backed by its own `tutorial_rentals` table, so
 * onboarding-tour practice data never shares the real `rentals` table's
 * auto-increment sequence — repeated tutorial runs no longer advance real
 * rental ID numbers. Inherits all of Rental's business logic (financial
 * summary calc, relationships, casts) for free; only the table and the two
 * global scopes that would otherwise misfire against a table that's ALL
 * tutorial data are overridden.
 */
class TutorialRental extends Rental
{
    protected $table = 'tutorial_rentals';

    protected static function booted(): void
    {
        parent::booted();
        // store_scope (BelongsToStore) and tutorial_scope (ExcludesTutorialData)
        // are inherited from Rental via its traits — both are meaningless here
        // (every row in this table already belongs to a tutorial run for
        // whichever store/user created it), and left active they'd silently
        // filter out the very rows this model exists to find. Replace both
        // with permissive no-ops rather than sprinkling withoutGlobalScope()
        // calls across every query site again, the exact problem this table
        // was built to get away from.
        static::addGlobalScope('store_scope', fn ($query) => $query);
        static::addGlobalScope('tutorial_scope', fn ($query) => $query);
    }

    /**
     * A practice rent never writes payment records (RentalPaymentRecorder skips it), and
     * rental_payment_records.rental_id points at REAL rents — so the inherited sum by id would
     * count the money of the real rent that shares this number. Only the rent's own columns count.
     */
    public function moneyReceived(): float
    {
        return max(0.0, (float) ($this->amount_paid ?? 0) + (float) ($this->overdue_amount_paid ?? 0));
    }

    public function outfits(): BelongsToMany
    {
        return $this->belongsToMany(Outfit::class, 'tutorial_rental_outfit', 'rental_id', 'outfit_id')
            ->withoutGlobalScope('tutorial_scope')
            ->withPivot(
                'discount',
                'discount_type',
                'discount_note',
                'price_incl_tax',
                'price_excl_tax',
                'tax_amount',
            );
    }
}
