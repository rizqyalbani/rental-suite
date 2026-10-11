<?php

namespace App\Models;

/**
 * RentalApprovalRequest subclass backed by `tutorial_rental_approval_requests`
 * — see TutorialRental for why. `rental_id` inside `payload` refers to a
 * TutorialRental row, not a real Rental one, so rentalModelClass() is
 * overridden to keep the shared diff/conflict-check logic pointed at the
 * right table.
 */
class TutorialRentalApprovalRequest extends RentalApprovalRequest
{
    protected $table = 'tutorial_rental_approval_requests';

    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('store_scope', fn ($query) => $query);
        static::addGlobalScope('tutorial_scope', fn ($query) => $query);
    }

    public function rentalModelClass(): string
    {
        return TutorialRental::class;
    }
}
