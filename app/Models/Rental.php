<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\ExcludesTutorialData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Rental extends Model
{
    use BelongsToStore;
    use ExcludesTutorialData;
    use SoftDeletes;

    protected static function booted(): void
    {
        // Assigned once, on real creation only — TutorialRental is a separate
        // model/table entirely, so practice rentals never consume a real
        // sequence number. Reads store_id directly off StoreContext rather
        // than trusting $rental->store_id to already be populated, since
        // that's set by BelongsToStore's own `creating` listener and this
        // avoids depending on trait listener registration order.
        static::creating(function (Rental $rental) {
            // Practice rents (TutorialRental, its own table) have no invoice number
            // column and must never take a number from the real sequence.
            if (! empty($rental->invoice_number) || $rental instanceof TutorialRental) {
                return;
            }
            $storeId = $rental->store_id ?: \App\Support\StoreContext::storeId();
            if ($storeId) {
                $rental->invoice_number = \App\Support\InvoiceNumberGenerator::next((int) $storeId);
            }
        });
    }

    protected $fillable = [
        'store_id',
        'is_tutorial',
        'customer_name', 'phone', 'instagram', 'notes',
        'payment_method',
        'amount_paid',
        'payment_kind',
        'handled_by_user_id',
        'created_by_user_id',
        'shift_session_id',
        'discount', 'discount_type', 'extra_charge', 'discount_note',
        'down_payment_amount',
        'start_date', 'end_date', 'status', 'picked_up_at',
        'overdue_base_end_date', 'overdue_merged_at',
        'overdue_charge_through_date',
        'overdue_fee_rate', 'overdue_fee_days', 'overdue_fee_amount', 'overdue_fee_applied_at',
        'overdue_amount_paid', 'overdue_payment_method', 'overdue_payment_kind',
        'overdue_payment_received_by_user_id', 'overdue_payment_shift_session_id', 'overdue_paid_at',
        'overdue_change_amount',
    ];

    protected function casts(): array
    {
        return [
            'is_tutorial' => 'boolean',
            'start_date' => 'date',
            'end_date' => 'date',
            'picked_up_at' => 'datetime',
            'overdue_base_end_date' => 'date',
            'overdue_merged_at' => 'datetime',
            'overdue_charge_through_date' => 'date',
            'overdue_fee_rate' => 'decimal:2',
            'overdue_fee_days' => 'int',
            'overdue_fee_amount' => 'decimal:2',
            'overdue_fee_applied_at' => 'datetime',
            'overdue_amount_paid' => 'decimal:2',
            'overdue_change_amount' => 'decimal:2',
            'overdue_paid_at' => 'datetime',
            'discount' => 'decimal:2',
            'amount_paid' => 'decimal:2',
            'down_payment_amount' => 'decimal:2',
        ];
    }

    public function outfits(): BelongsToMany
    {
        // A tutorial rental can only ever be attached to tutorial-tagged outfits
        // (the dedicated fake items), so Outfit's own tutorial_scope global scope
        // has nothing to protect here — but left active, it silently filters
        // those outfits back out of every ->outfits load (edit modal, financial
        // totals, receipts), even though the pivot row is intact. Access to
        // tutorial data is already gated by Rental's own tutorial_scope.
        return $this->belongsToMany(Outfit::class, 'rental_outfit')->withoutGlobalScope('tutorial_scope')->withPivot(
            'discount',
            'discount_type',
            'discount_note',
            'price_incl_tax',
            'price_excl_tax',
            'tax_amount',
        );
    }

    public function discountCheck(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(DiscountCheck::class);
    }

    public function handledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by_user_id');
    }

    /** The admin who originally recorded the rent; unlike handledBy, never reassigned by edits. */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function shiftSession(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    /** Money actually taken for this rent (rent + overdue), from its payment records. */
    public function moneyReceived(): float
    {
        $recorded = (float) RentalPaymentRecord::withoutGlobalScopes()->where('rental_id', $this->id)->sum('amount');

        // Older rents can carry amount_paid without records until the ledger sync runs.
        return max($recorded, (float) ($this->amount_paid ?? 0) + (float) ($this->overdue_amount_paid ?? 0));
    }
}
