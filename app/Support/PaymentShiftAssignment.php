<?php

namespace App\Support;

use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A payment entered by someone without an open shift (supervisor/management)
 * belongs to no shift, so no closing report counts it. Management asks the
 * owner of a shift to take it; the payment joins that shift only when the
 * shift owner accepts. Asking and deciding are always different people, and
 * every step is in the rent's activity log.
 */
class PaymentShiftAssignment
{
    public const KIND = 'payment_assign';

    public static function mayRequest(?User $user): bool
    {
        // Supervisors can take such a payment into their own shift, but not hand them out.
        return (bool) $user && ($user->isManagement() || $user->isSuperAdmin() || $user->isAccounting());
    }

    /**
     * Positive payments of the current store with no shift (optionally only
     * those received between $from and $to), each with its pending request.
     *
     * @return Collection<int, array{payment: RentalPaymentRecord, rental: Rental, pending: ?RentalApprovalRequest}>
     */
    public static function unassigned(?string $from = null, ?string $to = null): Collection
    {
        $payments = RentalPaymentRecord::query()
            ->whereNull('shift_session_id')
            ->where('amount', '<>', 0) // a negative record is a correction (bill went down after paying)
            ->whereIn('rental_id', Rental::query()->where('is_tutorial', false)->select('id'))
            ->when($from && $to, fn ($q) => $q->whereBetween('recorded_at', [$from.' 00:00:00', $to.' 23:59:59']))
            ->orderBy('recorded_at')
            ->get();
        if ($payments->isEmpty()) {
            return collect();
        }
        $rentals = Rental::query()->whereIn('id', $payments->pluck('rental_id'))->get()->keyBy('id');
        $pending = self::pendingQuery()->get()->keyBy(fn ($a) => (int) ($a->payload['payment_record_id'] ?? 0));

        return $payments->map(fn ($p) => [
            'payment' => $p,
            'rental' => $rentals->get($p->rental_id),
            'pending' => $pending->get($p->id),
        ])->filter(fn ($row) => $row['rental'] !== null)->values();
    }

    /**
     * Shifts that can take this payment: open when the money came in, or open
     * right now, in the rent's store — never the requester's own.
     *
     * @return Collection<int, ShiftSession>
     */
    public static function candidateShifts(RentalPaymentRecord $payment, Rental $rental, ?User $requester = null): Collection
    {
        $at = $payment->recorded_at ?? now();
        // Staff shifts only: a Super Admin (developer) shift is not a till.
        $superAdminIds = User::withoutGlobalScopes()->where('role', 'super_admin')->pluck('id');

        return ShiftSession::withoutGlobalScopes()
            ->where('store_id', $rental->store_id)
            ->whereNotIn('opened_by_user_id', $superAdminIds)
            ->where(function ($q) use ($at) {
                $q->where(fn ($w) => $w->where('opened_at', '<=', $at)
                    ->where(fn ($c) => $c->whereNull('closed_at')->orWhere('closed_at', '>=', $at)))
                    ->orWhereNull('closed_at');
            })
            ->when($requester, fn ($q) => $q->where('opened_by_user_id', '!=', $requester->id))
            ->with('openedBy:id,name')
            ->get()
            // The shift that was open when the money came in first, then the ones open now.
            ->sortBy(fn ($s) => [$s->opened_at <= $at && ($s->closed_at === null || $s->closed_at >= $at) ? 0 : 1, -$s->opened_at->timestamp])
            ->take(10)
            ->values();
    }

    public static function shiftLabel(ShiftSession $shift): string
    {
        $open = $shift->opened_at;
        $close = $shift->closed_at;

        return '#'.$shift->id.' '.($shift->openedBy?->name ?? '?').' ('.$open?->format('d M H:i').'–'
            .($close ? ($open && $close->isSameDay($open) ? $close->format('H:i') : $close->format('d M H:i')) : 'still open').')';
    }

    /** Why this payment cannot be sent to this shift, or null when it can. */
    public static function blockMessage(RentalPaymentRecord $payment, ShiftSession $shift, User $by): ?string
    {
        $rental = Rental::query()->find($payment->rental_id);
        if (! $rental || $rental->is_tutorial) {
            return 'Rent not found.';
        }
        if ($payment->shift_session_id) {
            return 'This payment is already in shift #'.$payment->shift_session_id.'.';
        }
        if ((int) $shift->opened_by_user_id === (int) $by->id) {
            return 'Choose someone else\'s shift — the shift owner has to accept it.';
        }
        if (! self::candidateShifts($payment, $rental, $by)->contains('id', $shift->id)) {
            return 'That shift was not open when this payment came in, and is not open now.';
        }
        if (self::pendingFor($payment)) {
            return 'This payment already has a request waiting.';
        }

        return null;
    }

    public static function request(RentalPaymentRecord $payment, ShiftSession $shift, User $by, string $note = ''): RentalApprovalRequest
    {
        $rental = Rental::query()->findOrFail($payment->rental_id);
        $owner = $shift->openedBy()->first();

        return DB::transaction(function () use ($payment, $shift, $by, $note, $rental, $owner) {
            $approval = RentalApprovalRequest::create([
                'requested_by_user_id' => $by->id,
                'payload' => [
                    'kind' => self::KIND,
                    'rental_id' => $rental->id,
                    'customer_name' => $rental->customer_name,
                    'payment_record_id' => $payment->id,
                    'amount' => (float) $payment->amount,
                    'payment_method' => $payment->payment_method,
                    'recorded_at' => $payment->recorded_at?->format('Y-m-d H:i:s'),
                    'shift_session_id' => $shift->id,
                    'target_user_id' => (int) $shift->opened_by_user_id,
                    'note' => $note,
                ],
            ]);
            RentalActivityLog::record($rental, 'payment_assign_requested', [
                'payment_amount' => [null, (float) $payment->amount],
                'shift' => [null, self::shiftLabel($shift)],
            ], note: 'Approval #'.$approval->id.' · '.$by->name.' asked '.($owner?->name ?? '?').' to take this payment into their shift'
                .($note !== '' ? ' · '.$note : ''), userId: $by->id);

            return $approval;
        });
    }

    /** The shift owner takes the payment into their shift. Error message instead when they cannot. */
    public static function accept(RentalApprovalRequest $approval, User $by): ?string
    {
        if ($msg = self::decisionBlock($approval, $by)) {
            return $msg;
        }
        $payload = $approval->payload ?? [];
        $payment = RentalPaymentRecord::query()->find((int) ($payload['payment_record_id'] ?? 0));
        $shift = ShiftSession::withoutGlobalScopes()->find((int) ($payload['shift_session_id'] ?? 0));
        if (! $payment || ! $shift) {
            return 'The payment or shift no longer exists.';
        }
        if ($payment->shift_session_id) {
            return 'This payment is already in shift #'.$payment->shift_session_id.'.';
        }
        $rental = Rental::withoutGlobalScope('store_scope')->withTrashed()->find($payment->rental_id);
        $enteredBy = $payment->received_by_user_id ? User::withoutGlobalScopes()->find($payment->received_by_user_id)?->name : null;

        DB::transaction(function () use ($approval, $by, $payment, $shift, $rental, $enteredBy) {
            $payment->update(['shift_session_id' => $shift->id, 'received_by_user_id' => $shift->opened_by_user_id]);
            ShiftClosedTotals::recomputeClosedShifts([$shift->id]);

            if ($rental) {
                RentalActivityLog::record($rental, 'payment_assign_approved', [
                    'payment_amount' => [null, (float) $payment->amount],
                    'shift' => ['—', self::shiftLabel($shift)],
                    'received_by' => [$enteredBy, $by->name],
                    'requested_by' => [null, $approval->requestedBy?->name],
                    'approved_by' => [null, $by->name],
                ], note: 'Approval #'.$approval->id, userId: $by->id);
            }

            $approval->update([
                'status' => 'approved',
                'decided_by_user_id' => $by->id,
                'decision_note' => '',
                'decided_at' => now(),
            ]);
        });

        return null;
    }

    public static function reject(RentalApprovalRequest $approval, User $by, string $note = ''): ?string
    {
        if ($msg = self::decisionBlock($approval, $by)) {
            return $msg;
        }
        $rental = Rental::withoutGlobalScope('store_scope')->withTrashed()->find((int) ($approval->payload['rental_id'] ?? 0));

        DB::transaction(function () use ($approval, $by, $note, $rental) {
            $approval->update([
                'status' => 'rejected',
                'decided_by_user_id' => $by->id,
                'decision_note' => $note,
                'decided_at' => now(),
            ]);
            if ($rental) {
                RentalActivityLog::record($rental, 'payment_assign_rejected', [
                    'requested_by' => [null, $approval->requestedBy?->name],
                    'rejected_by' => [null, $by->name],
                ], note: 'Approval #'.$approval->id.($note !== '' ? ' · '.$note : ''), userId: $by->id);
            }
        });

        return null;
    }

    /** Requests waiting on this user's decision. */
    public static function incomingFor(User $user): Collection
    {
        return self::pendingQuery()
            ->where('payload->target_user_id', $user->id)
            ->with('requestedBy:id,name')
            ->get();
    }

    public static function pendingFor(RentalPaymentRecord $payment): ?RentalApprovalRequest
    {
        return self::pendingQuery()->where('payload->payment_record_id', $payment->id)->first();
    }

    private static function pendingQuery()
    {
        return RentalApprovalRequest::query()
            ->where('status', 'pending')
            ->where('payload->kind', self::KIND);
    }

    private static function decisionBlock(RentalApprovalRequest $approval, User $by): ?string
    {
        if ($approval->status !== 'pending' || ($approval->payload['kind'] ?? null) !== self::KIND) {
            return 'This request has already been processed.';
        }
        if ((int) ($approval->payload['target_user_id'] ?? 0) !== (int) $by->id) {
            return 'Only the owner of that shift can accept or reject this payment.';
        }

        return null;
    }
}
