<?php

namespace App\Support;

use App\Models\CashMovement;
use App\Support\AccountingTrialMode;
use Illuminate\Support\Facades\DB;

class CashMovementRecorder
{
    public static function record(
        int $storeId,
        string $movementDate,
        string $direction,
        int $cashAccountId,
        int $counterAccountId,
        float $amount,
        ?string $description,
        ?int $recordedByUserId,
    ): CashMovement {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new JournalEntryValidationException("Invalid cash movement direction: {$direction}");
        }

        return DB::transaction(function () use ($storeId, $movementDate, $direction, $cashAccountId, $counterAccountId, $amount, $description, $recordedByUserId) {
            $movement = CashMovement::create([
                'store_id' => $storeId,
                'movement_date' => $movementDate,
                'direction' => $direction,
                'cash_account_id' => $cashAccountId,
                'counter_account_id' => $counterAccountId,
                'amount' => round($amount, 2),
                'description' => $description,
                'recorded_by_user_id' => $recordedByUserId,
                'is_tutorial' => AccountingTrialMode::enabled(),
            ]);

            // 'in'  -> Dr cash / Cr counter (e.g. owner injects capital: Dr Cash, Cr Capital)
            // 'out' -> Dr counter / Cr cash (e.g. "monthly deposit": Dr Bank, Cr Store Cash)
            [$debitAccountId, $creditAccountId] = $direction === 'in'
                ? [$cashAccountId, $counterAccountId]
                : [$counterAccountId, $cashAccountId];

            $entry = JournalPostingService::postTwoLine(
                storeId: $storeId,
                entryDate: $movementDate,
                memo: $description ?: ('Cash '.($direction === 'in' ? 'in' : 'out')),
                debitAccountId: $debitAccountId,
                creditAccountId: $creditAccountId,
                amount: round($amount, 2),
                createdByUserId: $recordedByUserId,
                referenceType: 'cash_movement',
                referenceId: $movement->id,
            );

            $movement->update(['journal_entry_id' => $entry->id]);

            return $movement->fresh(['cashAccount', 'counterAccount', 'journalEntry']);
        });
    }

    /**
     * Corrects an already-recorded Cash In/Out. Same reverse-then-repost
     * shape as ExpenseRecorder::update() — see that docblock for why a
     * direct field update on the old journal entry isn't safe.
     */
    public static function update(
        CashMovement $movement,
        string $movementDate,
        string $direction,
        int $cashAccountId,
        int $counterAccountId,
        float $amount,
        ?string $description,
        ?int $updatedByUserId,
    ): CashMovement {
        if (! in_array($direction, ['in', 'out'], true)) {
            throw new JournalEntryValidationException("Invalid cash movement direction: {$direction}");
        }

        return DB::transaction(function () use ($movement, $movementDate, $direction, $cashAccountId, $counterAccountId, $amount, $description, $updatedByUserId) {
            // Preserve the record's OWN trial status throughout the correction — see
            // ExpenseRecorder::update()/JournalPostingService::reverse()'s docblocks.
            $originalIsTutorial = $movement->is_tutorial;
            $movement->loadMissing('journalEntry');
            if ($movement->journalEntry) {
                JournalPostingService::reverse(
                    $movement->journalEntry,
                    $updatedByUserId,
                    "Correction to Cash Movement #{$movement->id}",
                );
            }

            [$debitAccountId, $creditAccountId] = $direction === 'in'
                ? [$cashAccountId, $counterAccountId]
                : [$counterAccountId, $cashAccountId];

            $entry = JournalPostingService::postTwoLine(
                storeId: $movement->store_id,
                entryDate: $movementDate,
                memo: $description ?: ('Cash '.($direction === 'in' ? 'in' : 'out')),
                debitAccountId: $debitAccountId,
                creditAccountId: $creditAccountId,
                amount: round($amount, 2),
                createdByUserId: $updatedByUserId,
                referenceType: 'cash_movement',
                referenceId: $movement->id,
                isTutorial: $originalIsTutorial,
            );

            $movement->update([
                'movement_date' => $movementDate,
                'direction' => $direction,
                'cash_account_id' => $cashAccountId,
                'counter_account_id' => $counterAccountId,
                'amount' => round($amount, 2),
                'description' => $description,
                'journal_entry_id' => $entry->id,
                'is_tutorial' => $originalIsTutorial,
            ]);

            return $movement->fresh(['cashAccount', 'counterAccount', 'journalEntry']);
        });
    }
}
