<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\ExcludesAccountingTrialData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends Model
{
    use BelongsToStore;
    use ExcludesAccountingTrialData;

    protected $fillable = [
        'store_id',
        'movement_date',
        'direction',
        'cash_account_id',
        'counter_account_id',
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
            'movement_date' => 'date',
            'amount' => 'decimal:2',
            'is_tutorial' => 'boolean',
            'is_tutorial_practice' => 'boolean',
        ];
    }

    public function cashAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'cash_account_id');
    }

    public function counterAccount(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'counter_account_id');
    }

    /** withoutGlobalScope: see Expense::journalEntry()'s docblock — same reasoning. */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withoutGlobalScope('accounting_trial_scope');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
