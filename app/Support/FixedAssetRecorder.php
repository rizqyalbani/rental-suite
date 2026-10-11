<?php

namespace App\Support;

use App\Models\FixedAsset;
use Illuminate\Support\Facades\DB;

/**
 * Registers a physical fixed asset (a broom, a hanger batch, a wardrobe
 * display) AND posts its purchase as a real journal entry in one step —
 * mirrors ExpenseRecorder exactly, since "add an asset" is the same shape
 * of action as "record an expense": a real Dr [asset account] / Cr
 * [cash/bank] transaction, just landing on an asset account instead of an
 * expense account.
 */
class FixedAssetRecorder
{
    public static function record(
        int $storeId,
        string $name,
        int $accountId,
        string $purchaseDate,
        float $purchasePrice,
        int $paymentAccountId,
        ?string $notes,
        ?int $createdByUserId,
        bool $isTutorialPractice = false,
    ): FixedAsset {
        return DB::transaction(function () use ($storeId, $name, $accountId, $purchaseDate, $purchasePrice, $paymentAccountId, $notes, $createdByUserId, $isTutorialPractice) {
            $asset = FixedAsset::create([
                'store_id' => $storeId,
                'name' => $name,
                'account_id' => $accountId,
                'purchase_date' => $purchaseDate,
                'purchase_price' => round($purchasePrice, 2),
                'payment_account_id' => $paymentAccountId,
                'notes' => $notes,
                'created_by_user_id' => $createdByUserId,
                'is_tutorial' => AccountingTrialMode::enabled(),
                'is_tutorial_practice' => $isTutorialPractice,
            ]);

            // Dr the asset account / Cr the cash-or-bank account paid from —
            // if posting fails, the exception rolls back the FixedAsset row
            // above too, same "no orphan without a journal entry" guarantee
            // ExpenseRecorder gives.
            $entry = JournalPostingService::postTwoLine(
                storeId: $storeId,
                entryDate: $purchaseDate,
                memo: "Asset purchase: {$name}",
                debitAccountId: $accountId,
                creditAccountId: $paymentAccountId,
                amount: round($purchasePrice, 2),
                createdByUserId: $createdByUserId,
                referenceType: 'fixed_asset_purchase',
                referenceId: $asset->id,
                isTutorialPractice: $isTutorialPractice,
            );

            $asset->update(['journal_entry_id' => $entry->id]);

            return $asset->fresh(['account', 'paymentAccount', 'journalEntry']);
        });
    }

    /**
     * Records depreciation for one registered item without the caller ever
     * touching a Debit/Credit account picker — Dr Depreciation Expense (5150) /
     * Cr Accumulated Depreciation (1610), tagged to this asset. A dedicated,
     * guided alternative to the general-purpose Manual Journal Entry page
     * for the one specific case of "this item lost this much value" — see
     * FixedAsset::totalDepreciated()/accumulatedDepreciationAccountId() for
     * why 1610 (not the item's own account) is the credit side.
     */
    public static function depreciate(
        FixedAsset $asset,
        string $date,
        float $amount,
        ?string $note,
        ?int $createdByUserId,
        bool $isTutorialPractice = false,
    ): \App\Models\JournalEntry {
        $expenseAccountId = \App\Models\Account::query()->where('code', '5150')->value('id');
        $contraAccountId = FixedAsset::accumulatedDepreciationAccountId();

        if (! $expenseAccountId || ! $contraAccountId) {
            throw new JournalEntryValidationException('Depreciation Expense (5150) or Accumulated Depreciation (1610) account not found in the Chart of Accounts.');
        }

        $memo = "Depreciation: {$asset->name}".($note ? " — {$note}" : '');

        return JournalPostingService::postTwoLine(
            storeId: $asset->store_id,
            entryDate: $date,
            memo: $memo,
            debitAccountId: $expenseAccountId,
            creditAccountId: $contraAccountId,
            amount: round($amount, 2),
            createdByUserId: $createdByUserId,
            referenceType: 'fixed_asset_depreciation',
            referenceId: $asset->id,
            isTutorialPractice: $isTutorialPractice,
            fixedAssetId: $asset->id,
        );
    }
}
