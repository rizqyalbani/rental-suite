<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JournalEntryLine extends Model
{
    protected $fillable = [
        'journal_entry_id',
        'account_id',
        'debit',
        'credit',
        'line_memo',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
        ];
    }

    /** withoutGlobalScope: see Expense::journalEntry()'s docblock — same reasoning. */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withoutGlobalScope('accounting_trial_scope');
    }

    /** withoutGlobalScope: this line's own account, not a listing — same reasoning as journalEntry() above. */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class)->withoutGlobalScope('accounting_trial_scope');
    }
}
