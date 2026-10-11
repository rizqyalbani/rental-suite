<?php

namespace App\Support;

use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Free rent": the bill becomes Rp 0 (100% order discount), only after a
 * supervisor/management approves — never the same person who asked. Rent money
 * already received is handed back to the customer: a refund entry (Dr Rental
 * Refunds / Cr Store Cash or Bank, by how it was paid) whose memo starts with "FREE",
 * and the entry id is kept in the approval and the activity log so every
 * free refund can be traced. Every step is in the rent's activity log.
 */
class RentalFreeRequest
{
    public const KIND = 'free';

    /** Why this rent cannot be made free right now, or null when it can. */
    public static function blockMessage(Rental $rental): ?string
    {
        if ($rental->trashed() || $rental->status === 'cancelled') {
            return 'This rent is cancelled or deleted, so it cannot be made free.';
        }
        if (self::rentTotal($rental) <= 0.004) {
            return 'This rent is already Rp 0.';
        }

        return null;
    }

    public static function pendingFor(Rental $rental): ?RentalApprovalRequest
    {
        return RentalApprovalRequest::query()
            ->where('status', 'pending')
            ->whereIn('payload->kind', [self::KIND, 'edit', 'delete'])
            ->where('payload->rental_id', $rental->id)
            ->first();
    }

    public static function submit(Rental $rental, User $by, string $reason, ?int $shiftId): RentalApprovalRequest
    {
        $total = self::rentTotal($rental);

        return DB::transaction(function () use ($rental, $by, $reason, $shiftId, $total) {
            $approval = RentalApprovalRequest::create([
                'requested_by_user_id' => $by->id,
                'source_shift_session_id' => $shiftId,
                'payload' => [
                    'kind' => self::KIND,
                    'rental_id' => $rental->id,
                    'customer_name' => $rental->customer_name,
                    'reason' => $reason,
                    'rent_total_before' => $total,
                    'paid_before' => self::refundDue($rental),
                ],
            ]);
            $paid = self::refundDue($rental);
            RentalActivityLog::record($rental, 'free_requested', array_filter([
                'rent_total' => [$total, 0],
                'free_reason' => [null, $reason],
                'free_refund_amount' => $paid > 0.004 ? [null, $paid] : null,
            ]), note: 'Approval #'.$approval->id.' · requested by '.$by->name, userId: $by->id);

            return $approval;
        });
    }

    /** Applies an approved request. Returns an error message instead when it cannot. */
    public static function approve(RentalApprovalRequest $approval, User $decider, string $decisionNote = ''): ?string
    {
        if ((int) $approval->requested_by_user_id === (int) $decider->id) {
            return 'You submitted this request — it needs a decision from someone else.';
        }
        $payload = $approval->payload ?? [];
        $rental = Rental::withoutGlobalScope('store_scope')->withTrashed()->find((int) ($payload['rental_id'] ?? 0));
        if (! $rental) {
            return 'Rental not found — it may have already been deleted.';
        }
        // Checked again: money may have come in while the request waited.
        if ($msg = self::blockMessage($rental)) {
            return $msg;
        }

        $reason = (string) ($payload['reason'] ?? '');
        $requester = $approval->requestedBy?->name ?? 'Unknown';
        $before = self::rentTotal($rental);

        try {
            DB::transaction(function () use ($approval, $rental, $decider, $decisionNote, $reason, $requester, $before) {
                $oldNote = (string) ($rental->discount_note ?? '');
                $rental->forceFill([
                    'discount' => 100,
                    'discount_type' => 'percent',
                    'discount_note' => 'FREE: '.$reason,
                ])->save();

                $refund = self::postFreeRefund($rental->fresh(), $approval, $decider, $reason);

                RentalActivityLog::record($rental, 'free_approved', array_filter([
                    'rent_total' => [$before, 0],
                    'discount_note' => [$oldNote !== '' ? $oldNote : null, 'FREE: '.$reason],
                    'free_refund_amount' => $refund ? [null, $refund['amount']] : null,
                    'free_refund_accounts' => $refund ? [null, implode(', ', array_map(
                        fn ($a) => $a['name'].' Rp '.number_format($a['amount'], 0, ',', '.'), $refund['accounts']))] : null,
                    'requested_by' => [null, $requester],
                    'approved_by' => [null, $decider->name],
                ]), note: 'Approval #'.$approval->id
                    .($refund ? ' · refund FREE journal #'.$refund['journal_entry_id'].' — hand Rp '.number_format($refund['amount'], 0, ',', '.').' back to the customer' : '')
                    .($decisionNote !== '' ? ' · '.$decisionNote : ''), userId: $decider->id);

                // The contract side (Rental Receivable / Customer Deposits / revenue) follows the new Rp 0 total.
                $row = RentalLedgerSync::evaluate($rental->fresh());
                if ($row !== null && $row['auto']) {
                    RentalLedgerSync::postAdjustment($row);
                }
                ShiftClosedTotals::recomputeClosedShifts(ShiftClosedTotals::shiftIdsForRental($rental));

                $approval->update([
                    'status' => 'approved',
                    'decided_by_user_id' => $decider->id,
                    'decision_note' => $decisionNote,
                    'decided_at' => now(),
                    'payload' => array_merge($approval->payload ?? [], array_filter([
                        'refund_amount' => $refund['amount'] ?? null,
                        'refund_journal_entry_id' => $refund['journal_entry_id'] ?? null,
                    ])),
                ]);
            });
        } catch (\Throwable $e) {
            report($e);

            return 'Could not make this rent free: the refund could not be recorded ('.$e->getMessage().'). Nothing was changed.';
        }

        return null;
    }

    /**
     * Hands back all rent money still held for this rent (overdue fees are not
     * part of the rent and stay). Null when nothing was paid.
     *
     * @return array{amount: float, journal_entry_id: int, accounts: list<array{code: string, name: string, amount: float}>}|null
     */
    private static function postFreeRefund(Rental $rental, RentalApprovalRequest $approval, User $decider, string $reason): ?array
    {
        $amount = self::refundDue($rental);
        if ($amount <= 0.004 || $rental->is_tutorial) {
            return null;
        }
        $paid = self::rentPaid($rental);

        return RentalLedgerSync::postRefund(
            ['rental' => $rental, 'overpaid' => $amount, 'due' => 0.0, 'totals' => ['paid' => $paid]],
            $decider->id,
            "FREE — refund rental #{$rental->id} ({$rental->customer_name}), Approval #{$approval->id}: {$reason}. Paid Rp "
                .number_format($paid, 0, ',', '.').', refunded Rp '.number_format($amount, 0, ',', '.'),
        );
    }

    /** Rent money (not overdue fees) received and not yet handed back. */
    public static function refundDue(Rental $rental): float
    {
        return max(0.0, round(self::rentPaid($rental) - RentalLedgerSync::refundedTotal($rental), 2));
    }

    private static function rentPaid(Rental $rental): float
    {
        return (float) \App\Models\RentalPaymentRecord::withoutGlobalScopes()->where('rental_id', $rental->id)
            ->where(fn ($q) => $q->whereNull('payment_kind')->orWhere('payment_kind', '!=', 'overdue'))
            ->sum('amount');
    }

    public static function rentTotal(Rental $rental): float
    {
        $summary = RentalFinancialSummary::summarize($rental->loadMissing('outfits'), max(0, (float) StoreCache::get('pricing_tax_percent', 0)));

        return max(0.0, (float) $summary['net_incl_tax'] - max(0.0, (float) ($summary['overdue_fee_amount'] ?? 0)));
    }
}
