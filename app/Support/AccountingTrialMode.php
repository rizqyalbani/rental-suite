<?php

namespace App\Support;

/**
 * Global on/off switch for testing the Accounting module (Expenses, Cash
 * In/Out, Chart of Accounts) before going live with real transactions.
 * While on, every new Expense/CashMovement/Account/JournalEntry is tagged
 * is_tutorial=true and behaves completely normally — shows in history,
 * counts toward the Dashboard and account balances — because there's no
 * real data yet to protect it from. The moment it's switched off, every
 * is_tutorial=true row is excluded from all normal accounting queries (see
 * ExcludesAccountingTrialData) without a single row being deleted, so
 * History/Dashboard/balances reset to a clean, real-only view instantly.
 * Old trial rows can still be permanently purged later via the "Clear
 * trial data" action in Settings, whenever convenient.
 *
 * Global, not store-scoped (unlike AdminDeleteRestriction) — the Chart of
 * Accounts is shared across every store, and "are we live yet" is a
 * whole-business milestone, not something that should differ store to
 * store. Same plain-cache pattern as DebugTools/DebugClock.
 */
final class AccountingTrialMode
{
    public static function enabled(): bool
    {
        return (bool) cache()->get('accounting_trial_mode_enabled', false);
    }

    public static function setEnabled(bool $enabled): void
    {
        cache()->forever('accounting_trial_mode_enabled', $enabled);
    }
}
