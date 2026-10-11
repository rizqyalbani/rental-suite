<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Support\AccountingTrialMode;
use Illuminate\Support\Facades\DB;

/**
 * The single write path for every journal entry in the ledger — guarantees
 * every entry that's ever persisted is balanced (debits == credits) and
 * posted against real, active, leaf-level accounts, wrapped in one DB
 * transaction so a rejected entry never leaves partial rows behind. Mirrors
 * this codebase's existing single-canonical-recorder convention (see
 * RentalPaymentRecorder).
 */
class JournalPostingService
{
    /** Same float-rounding tolerance RentalPaymentRecorder uses. */
    private const EPSILON = 0.004;

    /**
     * @param  list<array{account_id:int, debit?:float, credit?:float, memo?:string}>  $lines
     */
    public static function post(
        int $storeId,
        string $entryDate,
        string $memo,
        array $lines,
        ?int $createdByUserId,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?bool $isTutorial = null,
        bool $isTutorialPractice = false,
        ?int $fixedAssetId = null,
    ): JournalEntry {
        if (count($lines) < 2) {
            throw new JournalEntryValidationException('A journal entry needs at least two lines.');
        }

        $totalDebit = 0.0;
        $totalCredit = 0.0;
        $accountIds = [];
        foreach ($lines as $line) {
            $debit = round((float) ($line['debit'] ?? 0), 2);
            $credit = round((float) ($line['credit'] ?? 0), 2);
            $hasDebit = $debit > self::EPSILON;
            $hasCredit = $credit > self::EPSILON;
            if ($hasDebit === $hasCredit) {
                throw new JournalEntryValidationException('Each journal line must have exactly one of debit or credit greater than zero.');
            }
            $totalDebit += $debit;
            $totalCredit += $credit;
            $accountIds[] = (int) $line['account_id'];
        }

        if (abs(round($totalDebit, 2) - round($totalCredit, 2)) > self::EPSILON) {
            throw new JournalEntryValidationException(
                "Journal entry is not balanced: debits {$totalDebit} != credits {$totalCredit}."
            );
        }

        $accounts = Account::query()->whereIn('id', array_unique($accountIds))->get()->keyBy('id');
        foreach (array_unique($accountIds) as $accountId) {
            $account = $accounts->get($accountId);
            if (! $account) {
                throw new JournalEntryValidationException("Account {$accountId} does not exist.");
            }
            if (! $account->is_active) {
                throw new JournalEntryValidationException("Account \"{$account->name}\" is not active.");
            }
            // Force leaf-level posting — the 5 top-level rows (and any other
            // account with children) are pure grouping headers; posting
            // against them would make balances double-count across levels.
            if (Account::query()->where('parent_id', $accountId)->exists()) {
                throw new JournalEntryValidationException("Account \"{$account->name}\" has sub-accounts — post against a specific sub-account instead.");
            }
        }

        return DB::transaction(function () use ($storeId, $entryDate, $memo, $lines, $createdByUserId, $referenceType, $referenceId, $isTutorial, $isTutorialPractice, $fixedAssetId) {
            $entry = JournalEntry::create([
                'store_id' => $storeId,
                'entry_date' => $entryDate,
                'memo' => $memo,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'fixed_asset_id' => $fixedAssetId,
                'created_by_user_id' => $createdByUserId,
                'is_tutorial' => $isTutorial ?? AccountingTrialMode::enabled(),
                'is_tutorial_practice' => $isTutorialPractice,
            ]);

            foreach ($lines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $entry->id,
                    'account_id' => $line['account_id'],
                    'debit' => round((float) ($line['debit'] ?? 0), 2),
                    'credit' => round((float) ($line['credit'] ?? 0), 2),
                    'line_memo' => $line['memo'] ?? null,
                ]);
            }

            return $entry->load('lines.account');
        });
    }

    /** Convenience for the always-balanced 2-line case (Expense / Cash In-Out). */
    public static function postTwoLine(
        int $storeId,
        string $entryDate,
        string $memo,
        int $debitAccountId,
        int $creditAccountId,
        float $amount,
        ?int $createdByUserId,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?bool $isTutorial = null,
        bool $isTutorialPractice = false,
        ?int $fixedAssetId = null,
    ): JournalEntry {
        return self::post(
            storeId: $storeId,
            entryDate: $entryDate,
            memo: $memo,
            lines: [
                ['account_id' => $debitAccountId, 'debit' => $amount],
                ['account_id' => $creditAccountId, 'credit' => $amount],
            ],
            createdByUserId: $createdByUserId,
            referenceType: $referenceType,
            referenceId: $referenceId,
            isTutorial: $isTutorial,
            isTutorialPractice: $isTutorialPractice,
            fixedAssetId: $fixedAssetId,
        );
    }

    /**
     * Creates a new balancing entry with every line's debit/credit swapped.
     * Used by ExpenseRecorder::update() / CashMovementRecorder::update() to
     * correct an entry without ever mutating the original in place.
     */
    public static function reverse(JournalEntry $entry, ?int $byUserId, ?string $memo = null): JournalEntry
    {
        $entry->loadMissing('lines');

        return DB::transaction(function () use ($entry, $byUserId, $memo) {
            $reversal = JournalEntry::create([
                'store_id' => $entry->store_id,
                'entry_date' => now()->toDateString(),
                'memo' => $memo ?? "Reversal of entry #{$entry->id}",
                'reference_type' => $entry->reference_type,
                'reference_id' => $entry->reference_id,
                'created_by_user_id' => $byUserId,
                'is_reversal' => true,
                'reverses_journal_entry_id' => $entry->id,
                // Inherits the ORIGINAL entry's own tag, not the current global
                // toggle — a real entry's reversal must stay real (and a trial
                // entry's, trial) regardless of whether someone flips Trial Mode
                // on/off in between. ExpenseRecorder::update() and
                // CashMovementRecorder::update() can reverse a real entry while
                // Trial Mode happens to be on (e.g. testing something unrelated);
                // tagging by the current toggle there would silently reclassify a
                // real correction as trial data, making it vanish from every
                // report the next time Trial Mode is switched off.
                'is_tutorial' => $entry->is_tutorial,
                'is_tutorial_practice' => $entry->is_tutorial_practice,
            ]);

            foreach ($entry->lines as $line) {
                JournalEntryLine::create([
                    'journal_entry_id' => $reversal->id,
                    'account_id' => $line->account_id,
                    'debit' => $line->credit,
                    'credit' => $line->debit,
                    'line_memo' => $line->line_memo,
                ]);
            }

            return $reversal->load('lines.account');
        });
    }
}
