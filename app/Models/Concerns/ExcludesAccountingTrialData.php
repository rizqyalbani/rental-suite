<?php

namespace App\Models\Concerns;

use App\Support\AccountingTrialMode;
use Illuminate\Database\Eloquent\Builder;

/**
 * Applied to Account, Expense, CashMovement, and JournalEntry. Two distinct
 * "trial" cases, both tracked on the same rows:
 *
 * - is_tutorial: "created while Trial Mode was on" — a real person manually
 *   driving the real screens to test them before going live. Counts
 *   normally (Dashboard, History, Account::balance()) for as long as Trial
 *   Mode stays on, same as real data, since there's nothing real yet to
 *   protect it from — the original pre-launch bulk-testing design. Dropped
 *   the moment Trial Mode is switched off.
 *
 * - is_tutorial_practice: "created by a guided tutorial's own practice
 *   step" (the Expense/Manual Journal Entry tour's pre-filled example) —
 *   narrower, and can happen at ANY time, including long after real data
 *   already exists (re-onboarding a new staff member). That must never
 *   count toward real numbers, on or off — a practice entry showing up in
 *   the real books, even briefly, is confusing to whoever's watching them.
 *
 * So: exclude is_tutorial_practice rows unconditionally; exclude the
 * broader is_tutorial only once Trial Mode is off (unless the row is
 * ALSO is_tutorial_practice, already covered by the first rule).
 */
trait ExcludesAccountingTrialData
{
    public static function bootExcludesAccountingTrialData(): void
    {
        static::addGlobalScope('accounting_trial_scope', function (Builder $query): void {
            $query->where($query->qualifyColumn('is_tutorial_practice'), false);
            if (! AccountingTrialMode::enabled()) {
                $query->where($query->qualifyColumn('is_tutorial'), false);
            }
        });
    }
}
