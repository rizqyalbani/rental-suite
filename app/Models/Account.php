<?php

namespace App\Models;

use App\Models\Concerns\ExcludesAccountingTrialData;
use App\Support\AccountingTrialMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Account extends Model
{
    use ExcludesAccountingTrialData;

    protected $fillable = [
        'parent_id',
        'code',
        'name',
        'type',
        'normal_balance',
        'is_cash_equivalent',
        'is_active',
        'is_tutorial',
        'is_tutorial_practice',
        'description',
    ];

    protected function casts(): array
    {
        return [
            'is_cash_equivalent' => 'boolean',
            'is_active' => 'boolean',
            'is_tutorial' => 'boolean',
            'is_tutorial_practice' => 'boolean',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function isDebitNormal(): bool
    {
        return $this->normal_balance === 'debit';
    }

    /**
     * Running balance for this account, optionally scoped to one store's
     * books, a date range, and/or as-of a date. Account itself carries no
     * store_id (the Chart of Accounts is global), so store scoping here is
     * always an explicit param, never ambient StoreContext.
     *
     * $from bounds the range for period reports (P&L: "activity between two
     * dates"); $asOf alone (the original, still cumulative-since-inception
     * behavior used by Trial Balance/Balance Sheet) is unaffected when $from
     * is left null.
     */
    public function balance(?int $storeId = null, ?string $asOf = null, ?string $from = null): float
    {
        ['debit' => $debit, 'credit' => $credit] = $this->grossActivity($storeId, $asOf, $from);

        return $this->isDebitNormal() ? $debit - $credit : $credit - $debit;
    }

    /**
     * The two raw totals balance() nets against each other — split out for
     * callers that need the gross figures themselves (e.g. a fixed-asset
     * summary: "Purchase Price" is the cumulative debit side, "Total
     * Depreciated" the cumulative credit side, not just their net).
     * Same scoping rules as balance() — see its docblock.
     *
     * @return array{debit: float, credit: float}
     */
    public function grossActivity(?int $storeId = null, ?string $asOf = null, ?string $from = null): array
    {
        $query = JournalEntryLine::query()
            ->where('account_id', $this->id)
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id');

        // JournalEntryLine has no is_tutorial/is_tutorial_practice column of
        // its own and this is a raw join, not an Eloquent relationship —
        // JournalEntry's own ExcludesAccountingTrialData scope never gets a
        // chance to apply, so the same two-tier rule is repeated explicitly
        // here (see that trait's docblock for why there are two).
        $query->where('journal_entries.is_tutorial_practice', false);
        if (! AccountingTrialMode::enabled()) {
            $query->where('journal_entries.is_tutorial', false);
        }

        if ($storeId !== null) {
            $query->where('journal_entries.store_id', $storeId);
        }
        // whereDate() (not a plain where()) on purpose: entry_date is a DATE
        // column, but on SQLite it round-trips with a "00:00:00" time suffix
        // — a bare string "<=" comparison against "2026-08-16" then loses to
        // "2026-08-16 00:00:00" lexicographically, silently excluding same-day
        // entries. whereDate() extracts the date part before comparing, so it
        // works the same regardless of how the driver stores the value.
        if ($from !== null) {
            $query->whereDate('journal_entries.entry_date', '>=', $from);
        }
        if ($asOf !== null) {
            $query->whereDate('journal_entries.entry_date', '<=', $asOf);
        }

        return [
            'debit' => (float) $query->clone()->sum('journal_entry_lines.debit'),
            'credit' => (float) $query->clone()->sum('journal_entry_lines.credit'),
        ];
    }
}
