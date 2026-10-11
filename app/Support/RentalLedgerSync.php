<?php

namespace App\Support;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalPaymentRecord;
use App\Models\TutorialRental;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Self-service repair for rental payments that were actually collected but
 * never made it into the ledger. RentalRevenueRecorder::postForPaymentRecord()
 * is designed to fail silently ("posting is enrichment, never a hard
 * dependency of the Rentals flow"), which is right for keeping the register
 * usable, but can leave a real gap between the books and reality if
 * something upstream was briefly broken (a misconfigured account, a bad
 * deploy). Two separate gaps can appear:
 *
 *  1. Payment record exists, journal entry doesn't — fixed by re-posting
 *     the record.
 *  2. The payment record itself was never created, while the payment is
 *     still visible on the rental's own amount_paid/payment_kind columns —
 *     fixed by rebuilding the record from those columns, then posting it.
 *
 *  3. The rental was EDITED after its payment was posted (items added or
 *     removed, dates, discount) — the edit flow only journals the payment
 *     delta, never the change in contract value, so Customer Deposits/Rental Receivable stay
 *     frozen at the old total (seen on several real rentals).
 *     Detected by comparing the contract booked so far (Customer Deposits +
 *     recognized revenue) against the rental's current total; fixed with a
 *     Dr Rental Receivable / Cr Customer Deposits-or-Revenue adjustment entry, but ONLY for
 *     rentals still active — a returned rental's difference could be a
 *     refund, overdue add-on or discount at return, so it's listed for a
 *     human to judge instead.
 *
 *  4. The payment record for the rental's DOWN PAYMENT is missing while a
 *     later final payment exists (seen on three real rentals
 *     — each has down_payment_amount = 150.000 on the rental but only the
 *     final payment in the ledger). Gap 2 only looks at rentals with NO
 *     record at all, so these slipped through. Filled in as a down payment
 *     dated just before the first record that does exist.
 *
 *  6. A rental cancelled by having its last item taken over never went
 *     through the cancel flow — listed by cancelledWithOpenBalance().
 *
 *  5. An edit leaves the customer having paid MORE than the new total
 *     (item removed, discount raised). evaluate() sees the refunds already
 *     journaled ('rental_refund' entries and the older 'item_removal_refund')
 *     as part of the contract, so once the refund is posted the rental is
 *     balanced again. Live edit flows post it themselves (reconcileRental with
 *     $refundOverpayment) from the account the customer originally paid with;
 *     the bulk sync only LISTS such rentals — historical ones need a human to
 *     confirm the money really went back.
 *
 * Exposed as a Settings button (not a one-off migration) so it's safe to
 * re-run later if either gap ever reopens — see resources/views/settings/
 * index.blade.php.
 */
class RentalLedgerSync
{
    /**
     * Below this a payment is almost certainly a test/placeholder value
     * (seen for real: two rentals with amount_paid = 1.00), not money worth
     * inventing a ledger entry for — reported, never auto-posted.
     */
    private const MIN_PLAUSIBLE_AMOUNT = 1000;

    /**
     * Rentals whose payment record was never created at all. Bounded by the
     * moment payment records first existed (earlier rentals predate the
     * whole payment-record mechanism — nothing was ever supposed to exist
     * for them), and excludes tutorial/soft-deleted rentals and implausibly
     * small amounts.
     */
    public static function rentalsMissingRecordQuery(): Builder
    {
        $featureStart = RentalPaymentRecord::query()->min('recorded_at');

        return Rental::withoutGlobalScope('store_scope')
            ->where('is_tutorial', false)
            ->whereNotNull('store_id')
            ->whereIn('payment_kind', ['down_payment', 'full', 'final_payment'])
            ->where('amount_paid', '>=', self::MIN_PLAUSIBLE_AMOUNT)
            ->when($featureStart, fn ($q) => $q->where('created_at', '>=', $featureStart))
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('rental_payment_records')
                    ->whereColumn('rental_payment_records.rental_id', 'rentals.id');
            })
            ->orderBy('created_at');
    }

    /**
     * Every payment record with no journal entry pointing back to it,
     * oldest first — chronological order matters here: a rental's own down
     * payment must replay before its final payment, since
     * RentalRevenueRecorder's alreadyHasDownPayment()/alreadyRecognized()
     * guards read what's already been posted so far in the same pass.
     * Excludes tutorial and soft-deleted rentals: a cancelled rental's
     * payment shouldn't come back as a receivable.
     */
    public static function pendingQuery(): Builder
    {
        $excludedRentalIds = Rental::withoutGlobalScope('store_scope')
            ->withTrashed()
            ->where(fn ($q) => $q->where('is_tutorial', true)->orWhereNotNull('deleted_at'))
            ->pluck('id');

        return RentalPaymentRecord::query()
            ->whereNotIn('rental_id', $excludedRentalIds)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('journal_entries')
                    ->whereColumn('journal_entries.reference_id', 'rental_payment_records.id')
                    ->where('journal_entries.reference_type', 'rental_payment');
            })
            ->orderBy('recorded_at');
    }

    /** @param array<int, array>|null $driftRows already-computed driftRows(), to avoid a second full scan */
    public static function pendingCount(?array $driftRows = null): int
    {
        return self::pendingQuery()->count()
            + self::rentalsMissingRecordQuery()->count()
            + count(self::missingDownPaymentGaps())
            + count(array_filter($driftRows ?? self::driftRows(), fn ($row) => $row['auto']));
    }

    /**
     * Rentals that HAVE payment records, but fewer than the rental itself
     * says were paid (capped at the current bill, like every real payment
     * is) — and where the shortfall is unambiguous: a final/full payment is
     * already recorded, so what's missing is the down payment before it.
     * Anything murkier (only down payments recorded, gap after them) is not
     * guessed at; evaluate()/the review list shows it instead.
     *
     * @return list<array{rental: Rental, gap: float, recordedAt: Carbon}>
     */
    public static function missingDownPaymentGaps(): array
    {
        $candidates = Rental::withoutGlobalScope('store_scope')
            ->where('is_tutorial', false)
            ->whereNotNull('store_id')
            ->whereIn('status', ['active', 'returned'])
            ->whereIn('payment_kind', ['down_payment', 'full', 'final_payment'])
            ->where('amount_paid', '>=', self::MIN_PLAUSIBLE_AMOUNT)
            ->whereRaw("(select coalesce(sum(amount), 0) from rental_payment_records where rental_payment_records.rental_id = rentals.id and coalesce(rental_payment_records.payment_kind, '') != 'overdue') > 0")
            ->whereRaw("(select coalesce(sum(amount), 0) from rental_payment_records where rental_payment_records.rental_id = rentals.id and coalesce(rental_payment_records.payment_kind, '') != 'overdue') < rentals.amount_paid - 1")
            ->orderBy('id')
            ->get();

        $featureStart = RentalPaymentRecord::query()->min('recorded_at');
        $originalStoreId = StoreContext::storeId();
        $originalAllStores = StoreContext::allStores();
        $gaps = [];

        foreach ($candidates as $rental) {
            $records = RentalPaymentRecord::where('rental_id', $rental->id)->where(fn ($q) => $q->whereNull('payment_kind')->orWhere('payment_kind', '!=', 'overdue'))->orderBy('recorded_at')->get();
            // Only "final/full recorded, down payment missing" is unambiguous.
            if (! $records->contains(fn ($r) => in_array($r->payment_kind, ['final_payment', 'full'], true))
                || $records->contains(fn ($r) => $r->payment_kind === 'down_payment')) {
                continue;
            }

            StoreContext::set($rental->store_id);
            try {
                $due = self::totalDue($rental);
            } catch (\Throwable) {
                continue;
            } finally {
                StoreContext::set($originalStoreId, $originalAllStores);
            }

            $gap = round(min((float) $rental->amount_paid, $due) - (float) $records->sum('amount'), 2);
            if ($gap < self::MIN_PLAUSIBLE_AMOUNT) {
                continue;
            }

            // The real down payment is taken when the rental is created — but
            // a rental older than the payment-record feature has no such date
            // in the ledger, so it's placed just before its first record.
            $firstRecord = Carbon::parse($records->first()->recorded_at);
            $recordedAt = Carbon::parse($rental->created_at);
            if ($featureStart && $recordedAt->lt(Carbon::parse($featureStart))) {
                $recordedAt = $firstRecord->copy();
            }
            if ($recordedAt->gte($firstRecord)) {
                $recordedAt = $firstRecord->copy()->subSecond();
            }

            $gaps[] = ['rental' => $rental, 'gap' => $gap, 'recordedAt' => $recordedAt];
        }

        return $gaps;
    }

    /**
     * Cancelled rentals whose ledger still carries a receivable or deferred
     * deposit and was never settled by the cancellation policy (no
     * 'rental_cancellation' forfeiture entry, not reversed). Seen when the
     * last item of a rental is taken over by another one: the rental flips to
     * cancelled without going through the cancel flow. Never auto-fixed —
     * forfeiting the deposit vs. moving it to the new rental is the
     * the business's call — only listed.
     *
     * @return list<array{rental: Rental, piutang: float, uang_muka: float}>
     */
    public static function cancelledWithOpenBalance(): array
    {
        $rows = [];
        $rentals = Rental::withoutGlobalScope('store_scope')
            ->where('is_tutorial', false)
            ->whereNotNull('store_id')
            ->where('status', 'cancelled')
            ->orderBy('id')
            ->get();

        foreach ($rentals as $rental) {
            $settled = DB::table('journal_entries')
                ->where('reference_type', 'rental_cancellation')
                ->where('reference_id', $rental->id)
                ->exists();
            if ($settled) {
                continue;
            }

            $totals = self::ledgerTotals($rental);
            if ($totals === null) {
                continue;
            }
            if (abs($totals['piutang']) > 0.004 || abs($totals['uang_muka']) > 0.004) {
                $rows[] = ['rental' => $rental, 'piutang' => $totals['piutang'], 'uang_muka' => $totals['uang_muka']];
            }
        }

        return $rows;
    }

    /**
     * What a rental still owes as of a date, straight from Rents: its current
     * bill (rent only, no overdue fee — same "bill" the payment recorder gets)
     * minus what was paid up to the end of that day. Not the ledger: this is
     * the figure the business's own list uses, so a correction booked later
     * (an edit approved after $asOf) still counts against the booking. 0 for
     * a rental with no payment by then, like the rest of this class — nothing
     * was ever supposed to be booked for it.
     */
    public static function openBalanceAsOf(Rental $rental, string $asOf): float
    {
        return self::balanceAsOf($rental, $asOf)['remaining'];
    }

    /**
     * The three numbers behind openBalanceAsOf(): the rental's current bill,
     * what was paid by then, and what is left — so a screen can show
     * "contract − paid = remaining" the way the client reads their own list.
     *
     * @return array{due: float, paid: float, remaining: float}
     */
    public static function balanceAsOf(Rental $rental, string $asOf): array
    {
        $none = ['due' => 0.0, 'paid' => 0.0, 'remaining' => 0.0];

        $paid = (float) RentalPaymentRecord::where('rental_id', $rental->id)
            ->where('recorded_at', '<=', $asOf.' 23:59:59')
            ->where(fn ($q) => $q->whereNull('payment_kind')->orWhere('payment_kind', '!=', 'overdue'))
            ->sum('amount');
        if ($paid <= 0.004) {
            return $none;
        }

        $originalStoreId = StoreContext::storeId();
        $originalAllStores = StoreContext::allStores();
        StoreContext::set($rental->store_id);
        try {
            $due = self::totalDue($rental->loadMissing('outfits'));
        } catch (\Throwable) {
            return $none;
        } finally {
            StoreContext::set($originalStoreId, $originalAllStores);
        }

        return ['due' => round($due, 2), 'paid' => round($paid, 2), 'remaining' => round(max(0.0, $due - $paid), 2)];
    }

    /**
     * Money already handed back for a rental: refunds this class posts
     * ('rental_refund', keyed by rental) plus the older item-removal refunds
     * (keyed by approval id, which carries the rental in its payload).
     */
    public static function refundedTotal(Rental $rental): float
    {
        $refund = Account::where('code', '4910')->value('id');
        if (! $refund) {
            return 0.0;
        }

        $approvalIds = DB::table('rental_approval_requests')
            ->where('payload->rental_id', $rental->id)
            ->pluck('id');

        return (float) JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where('journal_entry_lines.account_id', $refund)
            ->where(function ($q) use ($rental, $approvalIds) {
                $q->where(fn ($w) => $w->where('journal_entries.reference_type', 'rental_refund')->where('journal_entries.reference_id', $rental->id))
                    ->orWhere(fn ($w) => $w->where('journal_entries.reference_type', 'item_removal_refund')->whereIn('journal_entries.reference_id', $approvalIds));
            })
            ->sum(DB::raw('journal_entry_lines.debit - journal_entry_lines.credit'));
    }

    /**
     * What the ledger has booked for one rental so far: money received
     * (payments), and the three accounts that carry the contract —
     * Rental Receivable, Customer Deposits, and recognized revenue. Includes this class's own
     * adjustment entries, so a second run sees them and stays quiet.
     * Null when nothing has been journaled for the rental at all.
     *
     * @return array{paid: float, piutang: float, uang_muka: float, revenue: float}|null
     */
    public static function ledgerTotals(Rental $rental): ?array
    {
        $recordIds = RentalPaymentRecord::where('rental_id', $rental->id)->pluck('id');
        $accounts = Account::whereIn('code', ['1300', '2300', '4100'])->pluck('id', 'code');

        $lines = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->where(function ($q) use ($recordIds, $rental) {
                $q->where(fn ($w) => $w->where('journal_entries.reference_type', 'rental_payment')->whereIn('journal_entries.reference_id', $recordIds))
                    ->orWhere(fn ($w) => $w->where('journal_entries.reference_type', 'rental_adjustment')->where('journal_entries.reference_id', $rental->id));
            })
            ->get(['journal_entry_lines.account_id', 'journal_entry_lines.debit', 'journal_entry_lines.credit']);

        if ($lines->isEmpty()) {
            return null;
        }

        return [
            // whereNull too: `!= 'overdue'` alone drops records whose kind is NULL in SQL.
            'paid' => (float) RentalPaymentRecord::where('rental_id', $rental->id)->where(fn ($q) => $q->whereNull('payment_kind')->orWhere('payment_kind', '!=', 'overdue'))->sum('amount'),
            'piutang' => (float) $lines->where('account_id', $accounts['1300'] ?? 0)->sum(fn ($l) => $l->debit - $l->credit),
            'uang_muka' => (float) $lines->where('account_id', $accounts['2300'] ?? 0)->sum(fn ($l) => $l->credit - $l->debit),
            'revenue' => (float) $lines->where('account_id', $accounts['4100'] ?? 0)->sum(fn ($l) => $l->credit - $l->debit),
        ];
    }

    /**
     * Compares what the ledger has booked for one rental against what the
     * rental's own numbers say it should be, and returns the adjustment
     * needed — or null when they already agree (or nothing was journaled).
     *
     * Target state: a recognized rental (revenue booked) has revenue = total,
     * Customer Deposits = 0; an unrecognized one (deposit only) has Customer Deposits = total,
     * revenue = 0; and Rental Receivable = total - paid in both. Because every posted
     * entry balances, the three deltas must satisfy piutang = revenue +
     * uang_muka — if they don't (an overpayment, a refund, a correction the
     * ledger models differently) the case isn't safe to fix by machine.
     *
     * @return array{rental: Rental, due: float, booked: float, drift: float, totals: array, deltas: array{piutang: float, revenue: float, uang_muka: float}, auto: bool, overpaid: float, refunded: float}|null
     */
    public static function evaluate(Rental $rental): ?array
    {
        $totals = self::ledgerTotals($rental);
        if ($totals === null) {
            return null;
        }

        $originalStoreId = StoreContext::storeId();
        $originalAllStores = StoreContext::allStores();
        StoreContext::set($rental->store_id);
        try {
            $due = self::totalDue($rental);
        } catch (\Throwable) {
            return null;
        } finally {
            StoreContext::set($originalStoreId, $originalAllStores);
        }

        // A refund already handed back is contra-revenue: the gross contract
        // in the books stays at what was paid, the refund brings the NET down
        // to the current total. So compare against total + refunds.
        $refunded = self::refundedTotal($rental);
        $contract = round($due + $refunded, 2);

        $recognized = $totals['revenue'] > 0.004;
        $deltas = [
            'revenue' => round(($recognized ? $contract : 0.0) - $totals['revenue'], 2),
            'uang_muka' => round(($recognized ? 0.0 : $contract) - $totals['uang_muka'], 2),
            'piutang' => round(max(0.0, $contract - $totals['paid']) - $totals['piutang'], 2),
        ];
        if (abs($deltas['revenue']) <= 1 && abs($deltas['uang_muka']) <= 1 && abs($deltas['piutang']) <= 1) {
            return null;
        }

        $booked = round($recognized ? $totals['revenue'] : $totals['uang_muka'], 2);
        // Same tolerance JournalPostingService enforces, so a row flagged auto can always be posted.
        $balanced = abs($deltas['piutang'] - $deltas['revenue'] - $deltas['uang_muka']) <= 0.005;
        // A returned rental that's still under-paid is a real receivable
        // (or a late fee/discount decided at return) and an over-paid one is a
        // refund/change — a human call either way. Active rentals, and
        // returned ones fully settled, are the unambiguous cases.
        $auto = $balanced && ($rental->status === 'active' || $totals['paid'] >= $contract - 1);
        $overpaid = round(max(0.0, $totals['paid'] - $contract), 2);

        return ['rental' => $rental, 'due' => $due, 'booked' => $booked, 'drift' => round($contract - $booked, 2), 'totals' => $totals, 'deltas' => $deltas, 'auto' => $auto, 'overpaid' => $overpaid, 'refunded' => $refunded];
    }

    /**
     * Rentals whose ledger no longer matches their current total (see
     * evaluate()). 'auto' rows are safe to adjust by machine; the rest need
     * a human.
     *
     * @return list<array>
     */
    public static function driftRows(): array
    {
        $rows = [];

        $rentals = Rental::withoutGlobalScope('store_scope')
            ->where('is_tutorial', false)
            ->whereNotNull('store_id')
            ->whereIn('status', ['active', 'returned'])
            ->orderBy('id')
            ->get();

        foreach ($rentals as $rental) {
            $row = self::evaluate($rental);
            if ($row !== null) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Posts the adjustment evaluate() calculated: one balanced entry moving
     * Rental Receivable / Rental Revenue / Customer Deposits to their target balances.
     *
     * @param array $row a row from evaluate()
     * @param string|null $entryDate null = today (live flows); the sync
     *                               passes the rental's last approved edit date.
     * @throws JournalEntryValidationException
     */
    public static function postAdjustment(array $row, ?string $entryDate = null): void
    {
        /** @var Rental $rental */
        $rental = $row['rental'];
        $accounts = Account::whereIn('code', ['1300', '2300', '4100'])->pluck('id', 'code');

        $lines = [];
        // Rental Receivable is debit-normal (a positive delta is a debit); revenue and
        // Customer Deposits are credit-normal (a positive delta is a credit).
        foreach ([['1300', 'piutang', true], ['4100', 'revenue', false], ['2300', 'uang_muka', false]] as [$code, $key, $debitNormal]) {
            $delta = $row['deltas'][$key];
            if (abs($delta) <= 0.004) {
                continue;
            }
            if (! isset($accounts[$code])) {
                throw new JournalEntryValidationException("Account {$code} not found.");
            }
            $isDebit = $debitNormal ? $delta > 0 : $delta < 0;
            $lines[] = ['account_id' => (int) $accounts[$code], ($isDebit ? 'debit' : 'credit') => round(abs($delta), 2)];
        }

        if (count($lines) < 2) {
            return;
        }

        JournalPostingService::post(
            storeId: (int) $rental->store_id,
            entryDate: $entryDate ?? now()->toDateString(),
            memo: "Adjustment for edited rental #{$rental->id} ({$rental->customer_name}) — contract Rp ".number_format($row['booked'], 0, ',', '.').' → Rp '.number_format($row['due'], 0, ',', '.'),
            lines: $lines,
            createdByUserId: null,
            referenceType: 'rental_adjustment',
            referenceId: $rental->id,
        );
    }

    /**
     * Credit lines that hand $amount back out of the cash/bank accounts the
     * customer originally paid through, split by how much each brought in.
     * The last account takes the rounding so the entry balances to the cent.
     *
     * @return list<array{account_id: int, credit: float}>
     */
    public static function cashCreditLines(Rental $rental, float $amount): array
    {
        $originalStoreId = StoreContext::storeId();
        $originalAllStores = StoreContext::allStores();
        StoreContext::set($rental->store_id);
        try {
            // How much came in through each cash/bank account.
            $byAccount = [];
            $records = RentalPaymentRecord::where('rental_id', $rental->id)->where(fn ($q) => $q->whereNull('payment_kind')->orWhere('payment_kind', '!=', 'overdue'))->get();
            foreach ($records as $record) {
                $accountId = RentalRevenueRecorder::resolveCashAccountId($record->payment_method ?? $rental->payment_method);
                $byAccount[$accountId] = ($byAccount[$accountId] ?? 0.0) + (float) $record->amount;
            }
            // A correction (negative) payment record can leave an account net
            // negative or zero — it brought no money in to hand back, and a
            // negative share would break the split. Only accounts that
            // really took money in take part.
            $byAccount = array_filter($byAccount, fn ($in) => $in > 0.004);
            if ($byAccount === []) {
                $byAccount[RentalRevenueRecorder::resolveCashAccountId($rental->payment_method)] = 1.0;
            }
        } finally {
            StoreContext::set($originalStoreId, $originalAllStores);
        }

        $lines = [];
        $totalIn = array_sum($byAccount);
        $left = round($amount, 2);
        $ids = array_keys($byAccount);
        foreach ($ids as $i => $accountId) {
            $part = $i === count($ids) - 1 ? $left : round($amount * $byAccount[$accountId] / $totalIn, 2);
            $left = round($left - $part, 2);
            if ($part > 0.004) {
                $lines[] = ['account_id' => (int) $accountId, 'credit' => $part];
            }
        }

        return $lines;
    }

    /**
     * Hands an overpayment back: Dr Rental Refunds (4910) / Cr the cash or bank
     * account each original payment came in through — cash back out of Store Cash,
     * QRIS/transfer back out of Bank, split by how much of the money each
     * method brought in. Same accounts refundForItemRemoval() uses, so
     * reports treat both alike.
     *
     * @param array $row a row from evaluate() with overpaid > 0
     * @throws JournalEntryValidationException
     */
    /**
     * @return array{amount: float, accounts: list<array{code: string, name: string, amount: float}>}|null
     *         null when there is nothing to refund; otherwise the total and the
     *         per-account breakdown — surfaced to whoever approved the edit so
     *         staff know they still owe the CUSTOMER this money physically
     *         (this only posts the bookkeeping entry, never moves real cash).
     */
    public static function postRefund(array $row, ?int $byUserId = null, ?string $memo = null): ?array
    {
        /** @var Rental $rental */
        $rental = $row['rental'];
        $amount = round((float) $row['overpaid'], 2);
        if ($amount <= 0.004) {
            return null;
        }

        $refundAccount = Account::where('code', '4910')->value('id');
        if (! $refundAccount) {
            throw new JournalEntryValidationException('Account 4910 (Rental Refunds) not found.');
        }

        $creditLines = self::cashCreditLines($rental, $amount);
        $lines = array_merge([['account_id' => (int) $refundAccount, 'debit' => $amount]], $creditLines);

        $entry = JournalPostingService::post(
            storeId: (int) $rental->store_id,
            entryDate: now()->toDateString(),
            memo: $memo ?? "Refund of overpayment on rental #{$rental->id} ({$rental->customer_name}) — total Rp ".number_format($row['due'], 0, ',', '.').', paid Rp '.number_format($row['totals']['paid'], 0, ',', '.'),
            lines: $lines,
            createdByUserId: $byUserId,
            referenceType: 'rental_refund',
            referenceId: $rental->id,
        );

        $accountsById = Account::whereIn('id', array_column($creditLines, 'account_id'))->get(['id', 'code', 'name'])->keyBy('id');

        return [
            'amount' => $amount,
            'journal_entry_id' => $entry->id,
            'accounts' => array_map(fn ($line) => [
                'code' => $accountsById[$line['account_id']]->code ?? '',
                'name' => $accountsById[$line['account_id']]->name ?? '',
                'amount' => round((float) $line['credit'], 2),
            ], $creditLines),
        ];
    }

    /**
     * Called right after any flow that can change a rental's total (an
     * approved edit, a direct edit, an item removal) — the ledger side of
     * "the edit only journaled the payment delta, never the new contract".
     * $refundOverpayment: only the live edit flows pass true — the bulk sync
     * never assumes a historical overpayment was really refunded.
     * Best-effort like the rest of the ledger posting: a rental's edit must
     * never fail because of accounting, so any problem is logged and the
     * Settings sync button (which finds the same gap) picks it up later.
     *
     * @return array{amount: float, accounts: list<array{code: string, name: string, amount: float}>}|null
     *         same shape as postRefund() — null unless a refund was actually
     *         posted just now. The caller (an edit approval, a direct save)
     *         must surface this to whoever just approved/saved: the entry
     *         only records that money is owed back, it does not hand it over.
     */
    public static function reconcileRental(Rental $rental, bool $refundOverpayment = false, ?int $byUserId = null): ?array
    {
        if ($rental instanceof TutorialRental || $rental->is_tutorial || ! $rental->store_id) {
            return null;
        }

        // A cancelled rental's books are settled by the cancellation policy
        // (forfeited deposit / written-off Rental Receivable) — never adjust or refund it here.
        if (! in_array($rental->status, ['active', 'returned'], true)) {
            return null;
        }

        $refunded = null;
        try {
            $row = self::evaluate($rental->fresh() ?? $rental);

            // The edit left the customer having paid more than the new total.
            // Live edit flows treat that as money handed back (same as the
            // item-removal flow always has) — post the refund, then look
            // again: with it counted, the contract usually balances.
            if ($row !== null && $refundOverpayment && $row['overpaid'] > 1) {
                $refunded = self::postRefund($row, $byUserId);
                $row = self::evaluate($rental->fresh() ?? $rental);
            }

            if ($row !== null && $row['auto']) {
                self::postAdjustment($row);
            }
        } catch (\Throwable $e) {
            Log::warning('Rental edit could not be reconciled to the ledger.', [
                'rental_id' => $rental->id,
                'reason' => $e->getMessage(),
            ]);
        }

        return $refunded;
    }

    /**
     * The Settings page needs the drift list on every visit, and building it
     * costs several queries per rental — so it is served from a short cache,
     * as plain rows (no models), and dropped whenever run() finishes.
     *
     * @return list<array>
     */
    public static function driftRowsForDisplay(): array
    {
        return Cache::remember('rental_ledger_drift_rows', 120, function () {
            return array_map(function (array $row) {
                $r = $row['rental'];
                $row['rental'] = (object) ['id' => $r->id, 'customer_name' => $r->customer_name, 'status' => $r->status];

                return $row;
            }, self::driftRows());
        });
    }

    /**
     * Runs the whole repair. A cache lock keeps a double click or a retry
     * after a hosting timeout from running two passes at once (each would
     * journal the same pending records / adjustments twice).
     *
     * @return array{created: int, fixed: int, adjusted: int, failed: int, failures: array<int, string>, review: array<int, string>}
     */
    public static function run(): array
    {
        $lock = Cache::lock('rental-ledger-sync', 900);
        if (! $lock->get()) {
            return ['created' => 0, 'fixed' => 0, 'adjusted' => 0, 'failed' => 1, 'failures' => ['Another sync is already running. Wait for it to finish and try again.'], 'review' => []];
        }

        $originalStoreId = StoreContext::storeId();
        $originalAllStores = StoreContext::allStores();
        try {
            return self::runLocked();
        } finally {
            // Restored even if something below throws — StoreContext is a static
            // that would otherwise leak into whatever runs next in this process.
            StoreContext::set($originalStoreId, $originalAllStores);
            Cache::forget('rental_ledger_drift_rows');
            $lock->release();
        }
    }

    /**
     * @return array{created: int, fixed: int, adjusted: int, failed: int, failures: array<int, string>, review: array<int, string>}
     */
    private static function runLocked(): array
    {
        $originalStoreId = StoreContext::storeId();
        $originalAllStores = StoreContext::allStores();

        $created = 0;
        $fixed = 0;
        $adjusted = 0;
        $failures = [];
        $review = [];

        // Phase 1: rebuild payment records that were never created, from
        // what the rental itself already stored. Amount and kind are exact;
        // recorded_at is the rental's created_at, since the real flow
        // records the initial payment in the same request that creates the
        // rental (RentalController::store).
        foreach (self::rentalsMissingRecordQuery()->get() as $rental) {
            StoreContext::set($rental->store_id);

            // Same gate the real flow uses — with pricing off it never
            // records payments at all.
            if (! StoreCache::get('pricing_enabled', true)) {
                continue;
            }

            try {
                $due = self::totalDue($rental);
            } catch (\Throwable $e) {
                $failures[] = "Rental #{$rental->id} ({$rental->customer_name}): could not calculate the invoice total — {$e->getMessage()}";

                continue;
            }

            // Same cap as RentalController::store — overpay is change, not revenue.
            $amount = round(min((float) $rental->amount_paid, $due), 2);
            if ($amount <= 0.004) {
                continue;
            }

            RentalPaymentRecord::create([
                'rental_id' => $rental->id,
                'shift_session_id' => $rental->shift_session_id,
                'received_by_user_id' => $rental->created_by_user_id,
                'amount' => $amount,
                'payment_method' => $rental->payment_method,
                'payment_kind' => $rental->payment_kind,
                'recorded_at' => $rental->created_at,
            ]);
            $created++;
        }

        // Phase 1b: the down payment missing from rentals that only have
        // their final payment recorded (see class doc, gap 4).
        foreach (self::missingDownPaymentGaps() as $gap) {
            /** @var Rental $rental */
            $rental = $gap['rental'];
            RentalPaymentRecord::create([
                'rental_id' => $rental->id,
                'shift_session_id' => $rental->shift_session_id,
                'received_by_user_id' => $rental->created_by_user_id,
                'amount' => $gap['gap'],
                'payment_method' => $rental->payment_method,
                'payment_kind' => 'down_payment',
                'recorded_at' => $gap['recordedAt']->toDateTimeString(),
            ]);
            $created++;
        }

        // Phase 2: post every record still missing its journal entry —
        // both the ones that were already there and the ones just rebuilt.
        foreach (self::pendingQuery()->get() as $record) {
            // withoutGlobalScope('store_scope'): this loop deliberately
            // walks records from BOTH stores in one pass — relying on the
            // scoped rental() relation would silently return null for
            // whichever store isn't the current viewer's active one.
            $rental = Rental::withoutGlobalScope('store_scope')->find($record->rental_id);
            if (! $rental) {
                $failures[] = "Payment #{$record->id}: rental #{$record->rental_id} not found.";

                continue;
            }

            // pricing_tax_percent is store-scoped (StoreCache) — set the
            // context to THIS record's own store before reading it, exactly
            // like the real request that should have posted this payment
            // would have had.
            StoreContext::set($rental->store_id);

            try {
                $due = self::totalDue($rental);
            } catch (\Throwable $e) {
                $failures[] = "Rental #{$rental->id} ({$rental->customer_name}): could not calculate the invoice total — {$e->getMessage()}";

                continue;
            }

            // A down payment whose rental's final payment was ALREADY posted
            // (after 2026-09-11, when posting started working again) saw no
            // prior DP at the time and was booked as "paid in full from the
            // start" — so this DP must not open a fresh Rental Receivable/Customer Deposits
            // that nothing will ever clear. See
            // RentalRevenueRecorder::postDownPaymentAsRecognizedRevenue().
            $laterRecognitionAlreadyPosted = $record->payment_kind === 'down_payment'
                && RentalPaymentRecord::where('rental_id', $record->rental_id)
                    ->whereIn('payment_kind', ['final_payment', 'full'])
                    ->whereNotNull('journal_entry_id')
                    ->where('recorded_at', '>', $record->recorded_at)
                    ->exists();

            if ($laterRecognitionAlreadyPosted) {
                RentalRevenueRecorder::postDownPaymentAsRecognizedRevenue($record, $rental);
            } else {
                RentalRevenueRecorder::postForPaymentRecord($record, $rental, $due);
            }

            if ($record->fresh()->journal_entry_id) {
                $fixed++;
            } else {
                // RentalRevenueRecorder already logged the real reason
                // (JournalEntryValidationException, usually a misconfigured
                // account) — this is just the user-facing tally.
                $failures[] = "Rental #{$rental->id} ({$rental->customer_name}), payment #{$record->id}: still failing, check the server log.";
            }
        }

        // Phase 3: edits that never reached the books. Recomputed AFTER
        // phase 2 so it sees the entries just posted.
        foreach (self::driftRows() as $row) {
            /** @var Rental $rental */
            $rental = $row['rental'];
            $label = "Rental #{$rental->id} ({$rental->customer_name}): total now Rp ".number_format($row['due'], 0, ',', '.')
                .', contract in the books Rp '.number_format($row['booked'], 0, ',', '.');

            if (! $row['auto']) {
                $paid = $row['totals']['paid'];
                $why = $paid > $row['due'] + 1
                    ? 'paid Rp '.number_format($paid, 0, ',', '.').' > total, overpaid (refund/change?)'
                    : ($paid < $row['due'] - 1
                        ? 'paid Rp '.number_format($paid, 0, ',', '.').' < total, underpaid (a real receivable, or a rental with a late fee/discount at return?)'
                        : 'cannot be reconciled automatically');
                $review[] = $label.' — '.$why.'. Check manually.';

                continue;
            }

            // Store-scoped model, and this loop walks rentals from every store — without
            // lifting the scope the lookup found nothing and every adjustment got the
            // sync's own date instead of the edit's.
            $lastEdit = RentalActivityLog::withoutGlobalScope('store_scope')->where('rental_id', $rental->id)->where('action', 'edit_approved')->max('created_at');
            $entryDate = $lastEdit ? Carbon::parse($lastEdit)->toDateString() : now()->toDateString();

            try {
                self::postAdjustment($row, $entryDate);
                $adjusted++;
            } catch (JournalEntryValidationException $e) {
                $failures[] = $label.' — '.$e->getMessage();
            }
        }

        foreach (self::cancelledWithOpenBalance() as $row) {
            $r = $row['rental'];
            $review[] = "Rental #{$r->id} ({$r->customer_name}): cancelled but the books still show Rental Receivable of Rp ".number_format($row['piutang'], 0, ',', '.').' and Customer Deposits of Rp '.number_format($row['uang_muka'], 0, ',', '.').' (deposit forfeited, or moved to another rental?). Check manually.';
        }

        StoreContext::set($originalStoreId, $originalAllStores);

        return ['created' => $created, 'fixed' => $fixed, 'adjusted' => $adjusted, 'failed' => count($failures), 'failures' => $failures, 'review' => $review];
    }

    /** Same "rent-only bill" RentalController passes to the recorder as $totalDue. */
    private static function totalDue(Rental $rental): float
    {
        $taxPercent = max(0, (float) StoreCache::get('pricing_tax_percent', 0));
        $summary = RentalFinancialSummary::summarize($rental, $taxPercent);

        return max(0.0, (float) $summary['net_incl_tax'] - max(0.0, (float) ($summary['overdue_fee_amount'] ?? 0)));
    }
}
