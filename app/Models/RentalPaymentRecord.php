<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RentalPaymentRecord extends Model
{
    protected $fillable = [
        'rental_id',
        'shift_session_id',
        'received_by_user_id',
        'amount',
        'payment_method',
        'payment_kind',
        'recorded_at',
        'journal_entry_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'recorded_at' => 'datetime',
        ];
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(Rental::class);
    }

    public function shiftSession(): BelongsTo
    {
        return $this->belongsTo(ShiftSession::class, 'shift_session_id');
    }

    public function receivedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by_user_id');
    }

    /** withoutGlobalScope: see Expense::journalEntry()'s docblock — same reasoning; RentalRevenueRecorder's cancel/delete reversal reads this directly. */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withoutGlobalScope('accounting_trial_scope');
    }
}
