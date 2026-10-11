<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\ExcludesAccountingTrialData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    use BelongsToStore;
    use ExcludesAccountingTrialData;

    protected $fillable = [
        'store_id',
        'expense_date',
        'account_id',
        'payment_account_id',
        'amount',
        'description',
        'recorded_by_user_id',
        'journal_entry_id',
        'is_tutorial',
        'is_tutorial_practice',
    ];

    protected function casts(): array
    {
        return [
            'expense_date' => 'date',
            'amount' => 'decimal:2',
            'is_tutorial' => 'boolean',
            'is_tutorial_practice' => 'boolean',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'payment_account_id');
    }

    /**
     * withoutGlobalScope: a direct FK lookup by this expense's OWN
     * journal_entry_id, not a listing — ExcludesAccountingTrialData exists
     * to keep trial rows out of aggregates/listings, not to hide an entry
     * from the one record that owns it (ExpenseRecorder's own edit-reversal
     * flow reads this to reverse a trial expense's old entry, same as it
     * would a real one).
     */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withoutGlobalScope('accounting_trial_scope');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
