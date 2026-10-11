<?php

namespace App\Support;

use App\Models\Expense;
use App\Support\AccountingTrialMode;
use Illuminate\Support\Facades\DB;

class ExpenseRecorder
{
    public static function record(
        int $storeId,
        string $expenseDate,
        int $accountId,
        int $paymentAccountId,
        float $amount,
        ?string $description,
        ?int $recordedByUserId,
        bool $isTutorialPractice = false,
    ): Expense {
        return DB::transaction(function () use ($storeId, $expenseDate, $accountId, $paymentAccountId, $amount, $description, $recordedByUserId, $isTutorialPractice) {
            $expense = Expense::create([
                'store_id' => $storeId,
                'expense_date' => $expenseDate,
                'account_id' => $accountId,
                'payment_account_id' => $paymentAccountId,
                'amount' => round($amount, 2),
                'description' => $description,
                'recorded_by_user_id' => $recordedByUserId,
                'is_tutorial' => AccountingTrialMode::enabled(),
                'is_tutorial_practice' => $isTutorialPractice,
            ]);

            // Dr the expense category / Cr the cash-or-bank account paid from.
            // If posting fails (e.g. an account went inactive between page
            // load and submit), the exception propagates out of this
            // transaction and the Expense row above is rolled back too — no
            // orphan Expense without a journal entry.
            $entry = JournalPostingService::postTwoLine(
                storeId: $storeId,
                entryDate: $expenseDate,
                memo: $description ?: 'Expense',
                debitAccountId: $accountId,
                creditAccountId: $paymentAccountId,
                amount: round($amount, 2),
                createdByUserId: $recordedByUserId,
                referenceType: 'expense',
                referenceId: $expense->id,
                isTutorialPractice: $isTutorialPractice,
            );

            $expense->update(['journal_entry_id' => $entry->id]);

            return $expense->fresh(['account', 'paymentAccount', 'journalEntry']);
        });
    }

    /**
     * Corrects an already-recorded Expense. Never touches the original
     * journal entry's lines in place — that would leave the ledger
     * reflecting a value nobody actually posted. Instead: reverse the old
     * entry (a new, offsetting entry — see JournalPostingService::reverse())
     * and post a fresh one for the corrected values, same as record(). The
     * Expense row's journal_entry_id ends up pointing at the new entry; the
     * original and its reversal stay in the ledger under the same
     * reference_type/reference_id, so the full correction history is still
     * there for anyone who goes looking.
     */
    public static function update(
        Expense $expense,
        string $expenseDate,
        int $accountId,
        int $paymentAccountId,
        float $amount,
        ?string $description,
        ?int $updatedByUserId,
    ): Expense {
        return DB::transaction(function () use ($expense, $expenseDate, $accountId, $paymentAccountId, $amount, $description, $updatedByUserId) {
            // Preserve the record's OWN trial status throughout the correction,
            // not whatever the global toggle happens to read right now — see
            // JournalPostingService::reverse()'s docblock for why re-deriving it
            // from the current toggle silently corrupts real data. Same reasoning
            // for is_tutorial_practice — a guided tutorial's practice expense
            // must stay excluded through a correction, not suddenly start
            // counting because the edit itself wasn't made through a tour.
            $originalIsTutorial = $expense->is_tutorial;
            $originalIsTutorialPractice = $expense->is_tutorial_practice;
            $expense->loadMissing('journalEntry');
            if ($expense->journalEntry) {
                JournalPostingService::reverse(
                    $expense->journalEntry,
                    $updatedByUserId,
                    "Correction to Expense #{$expense->id}",
                );
            }

            $entry = JournalPostingService::postTwoLine(
                storeId: $expense->store_id,
                entryDate: $expenseDate,
                memo: $description ?: 'Expense',
                debitAccountId: $accountId,
                creditAccountId: $paymentAccountId,
                amount: round($amount, 2),
                createdByUserId: $updatedByUserId,
                referenceType: 'expense',
                referenceId: $expense->id,
                isTutorial: $originalIsTutorial,
                isTutorialPractice: $originalIsTutorialPractice,
            );

            $expense->update([
                'expense_date' => $expenseDate,
                'account_id' => $accountId,
                'payment_account_id' => $paymentAccountId,
                'amount' => round($amount, 2),
                'description' => $description,
                'journal_entry_id' => $entry->id,
                'is_tutorial' => $originalIsTutorial,
                'is_tutorial_practice' => $originalIsTutorialPractice,
            ]);

            return $expense->fresh(['account', 'paymentAccount', 'journalEntry']);
        });
    }
}
