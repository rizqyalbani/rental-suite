<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Posts rental payments to the ledger — the revenue-side counterpart to
 * ExpenseRecorder/CashMovementRecorder.
 *
 * The business's own mental model (confirmed against their notes — "the
 * receivable goes down" only makes sense if the receivable already existed):
 * Rental Receivable is posted immediately on a rental's FIRST down payment (the
 * full unpaid remainder, right away — not deferred), while the DP amount
 * itself sits in Customer Deposits. Revenue is only recognized later, when
 * final_payment/full succeeds — which RentalController's own validation
 * (rejectIfFullPaymentBelowTotalDue) already guarantees always fully covers
 * the total, so that payment simultaneously clears the receivable AND
 * recognizes the full contract value as revenue in one entry. A pure 'full'
 * payment with no prior down_payment never touches either account — same
 * simple Dr cash / Cr revenue as before B1 existed.
 *
 * Called from RentalPaymentRecorder::recordIfNonZero() — the single choke
 * point every rent/overdue payment already flows through — so nothing in
 * RentalController/RentalApprovalController needs to change to be covered.
 */
class RentalRevenueRecorder
{
    /**
     * Posting is enrichment, not a hard dependency of the Rentals flow: if
     * the Chart of Accounts is misconfigured (an account renamed away from
     * its expected code, deactivated, etc.), the rental payment itself must
     * still succeed — a cashier renting out an outfit can never be blocked by
     * an accounting-side setup problem. Failures are logged, not thrown;
     * $record->journal_entry_id simply stays null.
     *
     * $totalDue: the full rent-only bill for this rental (excl. overdue fee),
     * as already computed by the caller (RentalController, via
     * RentalFinancialSummary — the single source of truth for pricing math;
     * deliberately not re-derived here). Ignored for overdue payments.
     */
    public static function postForPaymentRecord(RentalPaymentRecord $record, Rental $rental, float $totalDue = 0.0): void
    {
        $amount = round((float) $record->amount, 2);
        if (abs($amount) <= 0.004) {
            return;
        }

        $storeId = $rental->store_id;
        if (! $storeId) {
            return;
        }

        $kind = $record->payment_kind;
        $isRecognitionKind = in_array($kind, ['final_payment', 'full'], true);

        try {
            if ($kind === 'down_payment' && $amount > 0) {
                self::postDownPayment($record, $rental, $amount, $storeId, $totalDue);

                return;
            }

            if (! $isRecognitionKind) {
                // 'down_payment' with a negative (correction) amount, plus
                // anything else that isn't a recognition-triggering kind
                // (overdue, or an unset/legacy kind) — plain 2-line posting,
                // same shape as before B1 existed.
                self::postSimple($record, $rental, $amount, $storeId, self::resolveDeferredOrRevenueAccountId($kind));

                return;
            }

            if ($amount < 0) {
                // Downward correction on a final_payment/full record — the
                // proportional reversal of a multi-line entry is a deeper
                // problem than this method should solve; fall back to the
                // simple 2-line reversal against Revenue directly. Rare in
                // practice (corrections on the *closing* payment).
                self::postSimple($record, $rental, $amount, $storeId, self::revenueAccountId());

                return;
            }

            self::postRecognition($record, $rental, $amount, $storeId, $totalDue);
        } catch (JournalEntryValidationException $e) {
            Log::warning('Rental payment could not be posted to the ledger.', [
                'rental_payment_record_id' => $record->id,
                'rental_id' => $rental->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Repair-only entry point for RentalLedgerSync: posts a down payment as
     * plain recognized revenue (Dr cash / Cr revenue), for a rental whose
     * final_payment/full was ALREADY posted "as if paid in full from the
     * start" while this DP was still missing its own entry (the gap this
     * repairs meant that final payment saw no prior down payment). Posting
     * the DP the normal way now would credit Customer Deposits and debit Rental Receivable
     * for a contract that's already been fully recognized, leaving both
     * stuck open forever — this keeps cash and revenue right and both
     * balance-sheet accounts at zero, which is what the completed rental's
     * ledger should look like.
     */
    public static function postDownPaymentAsRecognizedRevenue(RentalPaymentRecord $record, Rental $rental): void
    {
        $amount = round((float) $record->amount, 2);
        if ($amount <= 0.004 || ! $rental->store_id) {
            return;
        }

        try {
            self::postSimple($record, $rental, $amount, (int) $rental->store_id, self::revenueAccountId());
        } catch (JournalEntryValidationException $e) {
            Log::warning('Rental down payment could not be posted to the ledger.', [
                'rental_payment_record_id' => $record->id,
                'rental_id' => $rental->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A rental's FIRST down payment splits into: cash received, plus the
     * full unpaid remainder posted straight to Rental Receivable — the receivable
     * exists from this moment, not deferred until later. Customer Deposits
     * is credited for the FULL contract value (not just this payment), since
     * that's the liability the recognition entry will clear in one shot.
     * Any SECOND (or later) down_payment before final_payment is simpler:
     * the customer is just paying down an already-posted receivable, so it's
     * a plain Dr cash / Cr Rental Receivable — no change to Customer Deposits.
     */
    private static function postDownPayment(RentalPaymentRecord $record, Rental $rental, float $amount, int $storeId, float $totalDue): void
    {
        if (self::alreadyHasDownPayment($rental, $record->id)) {
            self::postSimple($record, $rental, $amount, $storeId, self::receivableAccountId());

            return;
        }

        $totalDue = round(max($amount, $totalDue), 2);
        $receivable = round($totalDue - $amount, 2);

        $lines = [
            ['account_id' => self::resolveCashAccountId($record->payment_method), 'debit' => $amount],
        ];
        if ($receivable > 0.004) {
            $lines[] = ['account_id' => self::receivableAccountId(), 'debit' => $receivable];
        }
        $lines[] = ['account_id' => self::deferredRevenueAccountId(), 'credit' => $totalDue];

        $entry = JournalPostingService::post(
            storeId: $storeId,
            entryDate: $record->recorded_at?->toDateString() ?? now()->toDateString(),
            memo: self::memoFor($rental, $record),
            lines: $lines,
            createdByUserId: $record->received_by_user_id,
            referenceType: 'rental_payment',
            referenceId: $record->id,
        );

        $record->update(['journal_entry_id' => $entry->id]);
    }

    /** Plain Dr cash/bank, Cr [account] (or reversed, for a negative amount) — the pre-existing shape. */
    private static function postSimple(RentalPaymentRecord $record, Rental $rental, float $amount, int $storeId, int $creditOrDebitAccountId): void
    {
        $cashAccountId = self::resolveCashAccountId($record->payment_method);
        $entryDate = $record->recorded_at?->toDateString() ?? now()->toDateString();
        $memo = self::memoFor($rental, $record);

        [$debitAccountId, $creditAccountId] = $amount > 0
            ? [$cashAccountId, $creditOrDebitAccountId]
            : [$creditOrDebitAccountId, $cashAccountId];

        $entry = JournalPostingService::postTwoLine(
            storeId: $storeId,
            entryDate: $entryDate,
            memo: $memo,
            debitAccountId: $debitAccountId,
            creditAccountId: $creditAccountId,
            amount: abs($amount),
            createdByUserId: $record->received_by_user_id,
            referenceType: 'rental_payment',
            referenceId: $record->id,
        );

        $record->update(['journal_entry_id' => $entry->id]);
    }

    /**
     * The revenue-recognition "trigger point" — fires once per rental, on its first
     * final_payment/full payment record. If the rental never had a down
     * payment, nothing was ever deferred: plain Dr cash / Cr revenue, same
     * as pre-B1 behavior. Otherwise this payment (guaranteed by
     * RentalController's own validation to fully cover whatever's left)
     * simultaneously clears the receivable posted back at the first DP and
     * recognizes the full contract value as revenue. Any RentalPaymentRecord
     * seen after this point is a plain postSimple() against Rental Receivable instead
     * — see the already-recognized guard below (covers the rare case of a
     * second final_payment/full somehow being submitted for the same rental).
     */
    private static function postRecognition(RentalPaymentRecord $record, Rental $rental, float $amount, int $storeId, float $totalDue): void
    {
        if (self::alreadyRecognized($rental)) {
            self::postSimple($record, $rental, $amount, $storeId, self::receivableAccountId());

            return;
        }

        if (! self::alreadyHasDownPayment($rental, null)) {
            // Paid in full from the very start — nothing was ever deferred.
            self::postSimple($record, $rental, $amount, $storeId, self::revenueAccountId());

            return;
        }

        // Never recognize LESS than what's actually been collected — a
        // missing/zero $totalDue (pricing disabled, or a caller that didn't
        // pass it) must not shrink revenue below real cash received.
        $totalDue = round(max($amount, $totalDue), 2);

        $entry = JournalPostingService::post(
            storeId: $storeId,
            entryDate: $record->recorded_at?->toDateString() ?? now()->toDateString(),
            memo: self::memoFor($rental, $record).' (recognized)',
            lines: [
                ['account_id' => self::resolveCashAccountId($record->payment_method), 'debit' => $amount],
                ['account_id' => self::deferredRevenueAccountId(), 'debit' => $totalDue],
                ['account_id' => self::receivableAccountId(), 'credit' => $amount],
                ['account_id' => self::revenueAccountId(), 'credit' => $totalDue],
            ],
            createdByUserId: $record->received_by_user_id,
            referenceType: 'rental_payment',
            referenceId: $record->id,
        );

        $record->update(['journal_entry_id' => $entry->id]);
    }

    /** Has this rental already had its final_payment/full recognition entry posted? */
    private static function alreadyRecognized(Rental $rental): bool
    {
        return RentalPaymentRecord::query()
            ->where('rental_id', $rental->id)
            ->whereIn('payment_kind', ['final_payment', 'full'])
            ->whereNotNull('journal_entry_id')
            ->exists();
    }

    /** Has this rental already posted a down_payment (the Rental Receivable/Customer Deposits entry)? */
    private static function alreadyHasDownPayment(Rental $rental, ?int $excludeRecordId): bool
    {
        return RentalPaymentRecord::query()
            ->where('rental_id', $rental->id)
            ->when($excludeRecordId, fn ($q) => $q->where('id', '!=', $excludeRecordId))
            ->where('payment_kind', 'down_payment')
            ->whereNotNull('journal_entry_id')
            ->exists();
    }

    /**
     * Reverses every journal entry linked to a rental's payment records —
     * called when a DELETED rental's money must be undone as if it never
     * happened. Idempotent: skips any entry already reversed, so it's safe
     * even if this ends up triggered twice for the same rental.
     *
     * Also reverses this rental's own forfeitForCancelledRental() entry, if
     * one exists (reference_type 'rental_cancellation') — that entry isn't
     * linked from any RentalPaymentRecord, so it would otherwise be missed
     * entirely, leaving a stray forfeited-revenue credit standing after a
     * Cancelled rental is later hard-Deleted.
     */
    public static function reverseForCancelledRental(Rental $rental, ?int $byUserId): void
    {
        $records = RentalPaymentRecord::query()
            ->where('rental_id', $rental->id)
            ->whereNotNull('journal_entry_id')
            ->with('journalEntry')
            ->get();

        $entries = $records->map(fn ($record) => $record->journalEntry)->filter();

        // Every cancellation entry still standing — a rent cancelled, reopened and
        // cancelled again has several, and only the last one is live. Reversals
        // themselves and already-reversed entries are skipped below.
        $cancellationEntries = JournalEntry::withoutGlobalScopes()
            ->where('reference_type', 'rental_cancellation')
            ->where('reference_id', $rental->id)
            ->where('is_reversal', false)
            ->get();
        foreach ($cancellationEntries as $entry) {
            $entries->push($entry);
        }

        // Entries RentalLedgerSync keeps against the RENTAL (not a payment
        // record): contract adjustments after an edit, and refunds of an
        // overpayment — plus the older per-approval item-removal refunds.
        // Left standing they would keep a receivable / refund alive for a
        // rental that no longer exists. Reversals themselves are skipped.
        $approvalIds = DB::table('rental_approval_requests')->where('payload->rental_id', $rental->id)->pluck('id');
        $linked = JournalEntry::withoutGlobalScopes()
            ->where('is_reversal', false)
            ->where(function ($q) use ($rental, $approvalIds) {
                $q->where(fn ($w) => $w->whereIn('reference_type', ['rental_adjustment', 'rental_refund'])->where('reference_id', $rental->id))
                    ->orWhere(fn ($w) => $w->where('reference_type', 'item_removal_refund')->whereIn('reference_id', $approvalIds));
            })
            ->get();
        foreach ($linked as $entry) {
            $entries->push($entry);
        }

        foreach ($entries as $entry) {
            $alreadyReversed = JournalEntry::withoutGlobalScopes()
                ->where('reverses_journal_entry_id', $entry->id)
                ->exists();
            if ($alreadyReversed) {
                continue;
            }

            JournalPostingService::reverse(
                $entry,
                $byUserId,
                "Cancelled rental #{$rental->id}",
            );
        }
    }

    /**
     * True while this rental has a cancellation entry (forfeit or refund) that
     * has NOT been reversed. A reopened rental's old entry is reversed, so
     * cancelling it again must be able to post a fresh one.
     */
    private static function hasActiveCancellationEntry(Rental $rental): bool
    {
        return JournalEntry::withoutGlobalScopes()
            ->where('reference_type', 'rental_cancellation')
            ->where('reference_id', $rental->id)
            ->where('is_reversal', false)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')->from('journal_entries as rev')->whereColumn('rev.reverses_journal_entry_id', 'journal_entries.id');
            })
            ->exists();
    }

    /**
     * Cancel WITH refund (management's choice; the default cancel keeps the
     * money — see forfeitForCancelledRental). Books the money going back out
     * of the same cash/bank account(s) the customer paid through:
     *  - deposit still deferred (DP only): release Customer Deposits, write off the
     *    unpaid Rental Receivable, and credit cash/bank instead of "forfeited deposit" revenue;
     *  - revenue already recognized (paid in full / returned): Dr Rental Refunds
     *    (4910), Cr cash/bank.
     * Idempotent like the forfeiture; reversed again by reverseCancellation().
     *
     * @throws JournalEntryValidationException
     */
    public static function refundForCancelledRental(Rental $rental, ?int $byUserId): void
    {
        if (self::hasActiveCancellationEntry($rental) || ! $rental->store_id) {
            return;
        }

        $totals = RentalLedgerSync::ledgerTotals($rental);
        if ($totals === null) {
            return; // nothing was ever posted for this rental
        }

        $paidHeld = round(max(0.0, $totals['paid'] - RentalLedgerSync::refundedTotal($rental)), 2);
        $deferred = round(max(0.0, $totals['uang_muka']), 2);
        $receivable = round(max(0.0, $totals['piutang']), 2);

        $lines = [];
        if ($deferred > 0.004) {
            $writtenOff = round(min($receivable, $deferred), 2);
            $cashBack = round($deferred - $writtenOff, 2);
            $lines[] = ['account_id' => self::deferredRevenueAccountId(), 'debit' => $deferred];
            if ($writtenOff > 0.004) {
                $lines[] = ['account_id' => self::receivableAccountId(), 'credit' => $writtenOff];
            }
            if ($cashBack > 0.004) {
                $lines = array_merge($lines, RentalLedgerSync::cashCreditLines($rental, $cashBack));
            }
        } elseif ($paidHeld > 0.004) {
            $lines[] = ['account_id' => self::itemRemovalRefundAccountId(), 'debit' => $paidHeld];
            $lines = array_merge($lines, RentalLedgerSync::cashCreditLines($rental, $paidHeld));
        }

        if (count($lines) < 2) {
            return;
        }

        JournalPostingService::post(
            storeId: (int) $rental->store_id,
            entryDate: now()->toDateString(),
            memo: "Cancelled rental #{$rental->id} — refunded to customer",
            lines: $lines,
            createdByUserId: $byUserId,
            referenceType: 'rental_cancellation',
            referenceId: $rental->id,
        );
    }

    /**
     * Management reopening a cancelled rental: reverse whatever the
     * cancellation booked (forfeited deposit or refund), so the contract, the
     * deposit and the receivable are back exactly as before it was cancelled.
     */
    public static function reverseCancellation(Rental $rental, ?int $byUserId): void
    {
        $entries = JournalEntry::withoutGlobalScopes()
            ->where('reference_type', 'rental_cancellation')
            ->where('reference_id', $rental->id)
            ->where('is_reversal', false)
            ->get();

        foreach ($entries as $entry) {
            $alreadyReversed = JournalEntry::withoutGlobalScopes()->where('reverses_journal_entry_id', $entry->id)->exists();
            if (! $alreadyReversed) {
                JournalPostingService::reverse($entry, $byUserId, "Reopened rental #{$rental->id} — cancellation reversed");
            }
        }
    }

    /**
     * Cancel (unlike Delete's full reversal above) treats DP already
     * collected as non-refundable, per the business's own cancellation policy:
     * whatever's still owed (Rental Receivable) is written off — the customer
     * isn't chased for it — and whatever cash was actually collected so far
     * is kept, recognized as forfeited-deposit revenue, in one entry.
     *
     * Both figures are derived from this rental's OWN posted journal lines
     * (net Rental Receivable/Customer Deposits across just its own payment records),
     * not re-derived from pricing — correct regardless of how many down
     * payment top-ups happened, and it naturally no-ops once revenue was
     * already fully recognized (a final_payment/full entry closes both
     * accounts back to zero for this rental) or if the rental never had a
     * down payment at all (nothing was ever deferred to forfeit).
     *
     * Idempotent — a second call for the same rental finds its own prior
     * forfeiture entry (reference_type 'rental_cancellation') and no-ops,
     * the same guard reverseForCancelledRental() relies on above.
     *
     * Overdue-fee payments are deliberately left untouched: they post
     * straight to their own revenue account (4200), never touch Rental Receivable/Customer
     * Deposits, and are treated as already-earned regardless of what happens to
     * the rest of the booking.
     */
    public static function forfeitForCancelledRental(Rental $rental, ?int $byUserId): void
    {
        if (self::hasActiveCancellationEntry($rental)) {
            return;
        }

        $entryIds = RentalPaymentRecord::query()
            ->where('rental_id', $rental->id)
            ->whereNotNull('journal_entry_id')
            ->pluck('journal_entry_id');
        // Contract adjustments after an edit (RentalLedgerSync) also moved this
        // rental's Rental Receivable / Customer Deposits but hang off the rental, not a payment
        // record — leave them out and a cancel strands their balance forever.
        $entryIds = $entryIds->merge(
            JournalEntry::withoutGlobalScopes()
                ->where('reference_type', 'rental_adjustment')
                ->where('reference_id', $rental->id)
                ->pluck('id')
        )->unique()->values();
        if ($entryIds->isEmpty()) {
            return;
        }

        $storeId = $rental->store_id;
        if (! $storeId) {
            return;
        }

        try {
            $receivableAccountId = self::receivableAccountId();
            $deferredAccountId = self::deferredRevenueAccountId();
        } catch (JournalEntryValidationException $e) {
            Log::warning('Rental cancellation forfeiture skipped — chart of accounts misconfigured.', [
                'rental_id' => $rental->id,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $lines = JournalEntryLine::query()
            ->whereIn('journal_entry_id', $entryIds)
            ->whereIn('account_id', [$receivableAccountId, $deferredAccountId])
            ->get(['account_id', 'debit', 'credit']);

        $netReceivable = 0.0; // Rental Receivable: debit-normal
        $netDeferred = 0.0;   // Customer Deposits: credit-normal
        foreach ($lines as $line) {
            if ((int) $line->account_id === $receivableAccountId) {
                $netReceivable += (float) $line->debit - (float) $line->credit;
            } else {
                $netDeferred += (float) $line->credit - (float) $line->debit;
            }
        }
        $netReceivable = round(max(0.0, $netReceivable), 2);
        $netDeferred = round(max(0.0, $netDeferred), 2);

        if ($netDeferred <= 0.004) {
            return;
        }

        $writtenOff = round(min($netReceivable, $netDeferred), 2);
        $forfeited = round($netDeferred - $writtenOff, 2);

        $postLines = [['account_id' => $deferredAccountId, 'debit' => $netDeferred]];
        if ($writtenOff > 0.004) {
            $postLines[] = ['account_id' => $receivableAccountId, 'credit' => $writtenOff];
        }
        if ($forfeited > 0.004) {
            try {
                $postLines[] = ['account_id' => self::forfeitedDepositRevenueAccountId(), 'credit' => $forfeited];
            } catch (JournalEntryValidationException $e) {
                Log::warning('Rental cancellation forfeiture skipped — forfeited-revenue account misconfigured.', [
                    'rental_id' => $rental->id,
                    'reason' => $e->getMessage(),
                ]);

                return;
            }
        }

        if (count($postLines) < 2) {
            return;
        }

        try {
            JournalPostingService::post(
                storeId: $storeId,
                entryDate: now()->toDateString(),
                memo: "Cancelled rental #{$rental->id} — DP forfeited",
                lines: $postLines,
                createdByUserId: $byUserId,
                referenceType: 'rental_cancellation',
                referenceId: $rental->id,
            );
        } catch (JournalEntryValidationException $e) {
            Log::warning('Rental cancellation forfeiture could not be posted.', [
                'rental_id' => $rental->id,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Posts the real cash-out when an item-removal approval leaves the
     * customer overpaid (RentalApprovalController::approveItemRemoval
     * already computes this as $refundFromRemoval — this only posts when
     * that's actually positive). Dr 4910 Rental Refunds / Cr the cash-or-bank
     * account the money physically left from — a contra-revenue account
     * kept separate from 4900 Discount Given, since a discount decided at
     * sale time and a refund for an item removed afterward are different
     * things a manager reviewing either line wants to read as different
     * things. Idempotent per approval, same pattern as
     * forfeitForCancelledRental() — a retry (double form submit, retried
     * request) never double-posts.
     */
    public static function refundForItemRemoval(Rental $rental, float $amount, int $paymentAccountId, ?int $approvalId, ?int $byUserId): void
    {
        $amount = round($amount, 2);
        if ($amount <= 0.004 || ! $approvalId) {
            return;
        }

        $alreadyPosted = JournalEntry::withoutGlobalScopes()
            ->where('reference_type', 'item_removal_refund')
            ->where('reference_id', $approvalId)
            ->exists();
        if ($alreadyPosted) {
            return;
        }

        $storeId = $rental->store_id;
        if (! $storeId) {
            return;
        }

        try {
            $refundAccountId = self::itemRemovalRefundAccountId();
        } catch (JournalEntryValidationException $e) {
            Log::warning('Item removal refund skipped — chart of accounts misconfigured.', [
                'rental_id' => $rental->id,
                'approval_id' => $approvalId,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        try {
            JournalPostingService::postTwoLine(
                storeId: $storeId,
                entryDate: now()->toDateString(),
                memo: "Refund for removed item — rental #{$rental->id}",
                debitAccountId: $refundAccountId,
                creditAccountId: $paymentAccountId,
                amount: $amount,
                createdByUserId: $byUserId,
                referenceType: 'item_removal_refund',
                referenceId: $approvalId,
            );
        } catch (JournalEntryValidationException $e) {
            Log::warning('Item removal refund could not be posted.', [
                'rental_id' => $rental->id,
                'approval_id' => $approvalId,
                'reason' => $e->getMessage(),
            ]);
        }
    }

    /** "Rental Refunds" — contra-revenue for a refund owed after an item is removed post-payment, kept separate from 4900 Discount Given. */
    private static function itemRemovalRefundAccountId(): int
    {
        return self::accountIdByCode('4910', 'Rental Refunds');
    }

    /** down_payment → deferred-revenue liability; overdue → its own revenue account; anything else → straight revenue (matches the old behavior for an unset/legacy payment_kind). */
    private static function resolveDeferredOrRevenueAccountId(?string $paymentKind): int
    {
        return match ($paymentKind) {
            'down_payment' => self::deferredRevenueAccountId(),
            'overdue' => self::accountIdByCode('4200', 'Overdue revenue'),
            default => self::revenueAccountId(),
        };
    }

    private static function revenueAccountId(): int
    {
        return self::accountIdByCode('4100', 'Revenue');
    }

    /** "Customer Deposits" — DP sits here until the rental's recognition point, not counted as revenue yet. */
    private static function deferredRevenueAccountId(): int
    {
        return self::accountIdByCode('2300', 'Customer Deposits');
    }

    /** "Rental Receivable" — the unpaid remainder once revenue has been recognized in full. */
    private static function receivableAccountId(): int
    {
        return self::accountIdByCode('1300', 'Rental Receivable');
    }

    /** "Cancellation Revenue (Forfeited Deposits)" — DP kept as revenue when a rental is cancelled, kept separate from 4100 so completed-rental revenue stays uncontaminated. */
    private static function forfeitedDepositRevenueAccountId(): int
    {
        return self::accountIdByCode('4400', 'Cancellation Revenue (Forfeited Deposits)');
    }

    private static function accountIdByCode(string $code, string $label): int
    {
        $accountId = Account::where('code', $code)->value('id');
        if (! $accountId) {
            throw new JournalEntryValidationException("{$label} account \"{$code}\" not found.");
        }

        return (int) $accountId;
    }

    /**
     * Same as resolveCashAccountId(), but read in the RENTAL's own store: the
     * account settings are store-scoped, and an approver working in another
     * store must not route this rental's refund to their store's accounts.
     */
    public static function resolveCashAccountIdForRental(Rental $rental): int
    {
        $originalStoreId = StoreContext::storeId();
        $originalAllStores = StoreContext::allStores();
        StoreContext::set($rental->store_id);
        try {
            return self::resolveCashAccountId($rental->payment_method);
        } finally {
            StoreContext::set($originalStoreId, $originalAllStores);
        }
    }

    /** Cash/bank account a payment of this method lands in — also where a refund of it must come from. */
    public static function resolveCashAccountId(?string $paymentMethod): int
    {
        $isCash = trim((string) $paymentMethod) === 'cash';
        $settingKey = $isCash ? 'rental_revenue_cash_account_id' : 'rental_revenue_noncash_account_id';
        $fallbackCode = $isCash ? '1100' : '1200';

        $configuredId = StoreCache::get($settingKey);
        if ($configuredId) {
            return (int) $configuredId;
        }

        $accountId = Account::where('code', $fallbackCode)->value('id');
        if (! $accountId) {
            throw new JournalEntryValidationException("Cash/bank account \"{$fallbackCode}\" not found.");
        }

        return (int) $accountId;
    }

    private static function memoFor(Rental $rental, RentalPaymentRecord $record): string
    {
        $kindLabel = match ($record->payment_kind) {
            'down_payment' => 'Down payment',
            'final_payment' => 'Final payment',
            'overdue' => 'Overdue fee payment',
            default => 'Rental payment',
        };

        return "{$kindLabel} — Rental #{$rental->id} ({$rental->customer_name})";
    }
}
