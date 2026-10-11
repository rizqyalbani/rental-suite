<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\ExcludesAccountingTrialData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FixedAsset extends Model
{
    use BelongsToStore;
    use ExcludesAccountingTrialData;

    protected $fillable = [
        'store_id',
        'name',
        'account_id',
        'purchase_date',
        'purchase_price',
        'payment_account_id',
        'notes',
        'created_by_user_id',
        'journal_entry_id',
        'is_tutorial',
        'is_tutorial_practice',
    ];

    protected function casts(): array
    {
        return [
            'purchase_date' => 'date',
            'purchase_price' => 'decimal:2',
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

    /** withoutGlobalScope: see Expense::journalEntry()'s docblock — same reasoning. */
    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class)->withoutGlobalScope('accounting_trial_scope');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Depreciation entries a Manual Journal Entry posted against this specific item (fixed_asset_id). */
    public function depreciationEntries(): HasMany
    {
        return $this->hasMany(JournalEntry::class, 'fixed_asset_id');
    }

    /**
     * Contra-asset account depreciation is credited to — standard practice
     * (keeps the asset's own account at original cost forever, accumulated
     * depreciation tracked separately) rather than crediting the asset's own
     * account directly. Resolved by code, same pattern as
     * RentalRevenueRecorder::forfeitedDepositRevenueAccountId().
     */
    public static function accumulatedDepreciationAccountId(): ?int
    {
        return Account::query()->where('code', '1610')->value('id');
    }

    /**
     * Sum of every depreciation entry's credit to the Accumulated Depreciation
     * (1610) contra-asset account tagged to THIS item specifically — not a
     * credit to the item's own account, which stays at original cost.
     */
    public function totalDepreciated(): float
    {
        $contraAccountId = self::accumulatedDepreciationAccountId();
        if (! $contraAccountId) {
            return 0.0;
        }

        return (float) $this->depreciationEntries()
            ->join('journal_entry_lines', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_lines.account_id', $contraAccountId)
            ->sum('journal_entry_lines.credit');
    }

    public function bookValue(): float
    {
        return round((float) $this->purchase_price - $this->totalDepreciated(), 2);
    }

    /**
     * Per-entry depreciation history for this item — date, amount, and the
     * free-text note from the "Depreciate" form, newest first. The note isn't
     * its own column; FixedAssetRecorder::depreciate() writes it into the
     * journal entry's memo as "Depreciation: {name} — {note}", so it's peeled
     * back off here for display instead of showing the redundant prefix in
     * a table already scoped to one named item.
     *
     * @return \Illuminate\Support\Collection<int, array{date: \Illuminate\Support\Carbon, amount: float, note: ?string, journal_entry_id: int}>
     */
    public function depreciationHistory(): \Illuminate\Support\Collection
    {
        $contraAccountId = self::accumulatedDepreciationAccountId();
        if (! $contraAccountId) {
            return collect();
        }

        $prefix = "Depreciation: {$this->name} — ";

        return $this->depreciationEntries()
            ->where('is_reversal', false)
            ->join('journal_entry_lines', 'journal_entry_lines.journal_entry_id', '=', 'journal_entries.id')
            ->where('journal_entry_lines.account_id', $contraAccountId)
            ->orderByDesc('journal_entries.entry_date')
            ->orderByDesc('journal_entries.id')
            ->get(['journal_entries.id', 'journal_entries.entry_date', 'journal_entries.memo', 'journal_entry_lines.credit'])
            ->map(fn ($row) => [
                'date' => $row->entry_date,
                'amount' => (float) $row->credit,
                'note' => str_starts_with($row->memo, $prefix) ? substr($row->memo, strlen($prefix)) : null,
                'journal_entry_id' => $row->id,
            ]);
    }
}
