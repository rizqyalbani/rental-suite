<?php

namespace App\Support;

use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\TutorialRental;
use App\Models\TutorialRentalActivityLog;
use App\Models\TutorialRentalApprovalRequest;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * A pickup or return that really happened but was never clicked in the
 * system (for example: paid, picked up and returned, still "active", so a
 * receipt later added an overdue fee). Staff report what actually
 * happened — when, and who handed it over / took it back — and a supervisor
 * or management (never the requester) approves. Every step is in History.
 */
class RentalLateDone
{
    public const KIND = 'late_done';

    /** Why this rent cannot get this report now, or null when it can. */
    public static function blockMessage(Rental $rental, string $type): ?string
    {
        if ((! $rental instanceof TutorialRental && $rental->is_tutorial) || $rental->trashed()) {
            return 'This rent cannot be reported.';
        }
        if ($rental->status !== 'active') {
            return 'Only an active rent can be reported as picked up or returned.';
        }
        if ($type === 'pickup' && $rental->picked_up_at) {
            return 'This rent is already marked as picked up.';
        }
        if (self::pendingFor($rental)) {
            return 'This rent already has a pickup/return report waiting for approval.';
        }

        return null;
    }

    public static function pendingFor(Rental $rental): ?RentalApprovalRequest
    {
        $class = $rental instanceof TutorialRental ? TutorialRentalApprovalRequest::class : RentalApprovalRequest::class;

        return $class::query()
            ->where('status', 'pending')
            ->where('payload->kind', self::KIND)
            ->where('payload->rental_id', $rental->id)
            ->first();
    }

    /**
     * @param  array{type: string, picked_up_at: ?string, picked_up_by: ?int, returned_at: ?string, returned_by: ?int, note: ?string}  $data
     */
    public static function submit(Rental $rental, User $by, array $data, ?int $shiftId): RentalApprovalRequest
    {
        $names = User::withoutGlobalScopes()->whereIn('id', array_filter([$data['picked_up_by'] ?? null, $data['returned_by'] ?? null]))->pluck('name', 'id');
        $payload = [
            'kind' => self::KIND,
            'rental_id' => $rental->id,
            'customer_name' => $rental->customer_name,
            'type' => $data['type'],
            'already_picked_up' => (bool) $rental->picked_up_at,
            'picked_up_at' => $rental->picked_up_at ? null : $data['picked_up_at'],
            'picked_up_by' => $rental->picked_up_at ? null : ($data['picked_up_by'] ?? null),
            'picked_up_by_name' => $rental->picked_up_at ? null : ($names[$data['picked_up_by'] ?? 0] ?? null),
            'returned_at' => $data['type'] === 'return' ? $data['returned_at'] : null,
            'returned_by' => $data['type'] === 'return' ? ($data['returned_by'] ?? null) : null,
            'returned_by_name' => $data['type'] === 'return' ? ($names[$data['returned_by'] ?? 0] ?? null) : null,
            'note' => (string) ($data['note'] ?? ''),
        ];

        $practice = $rental instanceof TutorialRental;

        return DB::transaction(function () use ($rental, $by, $payload, $shiftId, $practice) {
            $approval = ($practice ? TutorialRentalApprovalRequest::class : RentalApprovalRequest::class)::create([
                'requested_by_user_id' => $by->id,
                'source_shift_session_id' => $practice ? null : $shiftId,
                'payload' => $payload,
            ]);
            ($practice ? TutorialRentalActivityLog::class : RentalActivityLog::class)::record($rental, 'late_done_requested', self::changes($payload), note: 'Approval #'.$approval->id
                .' · requested by '.$by->name.($payload['note'] !== '' ? ' · '.$payload['note'] : ''), userId: $by->id);

            return $approval;
        });
    }

    /** Applies an approved report. Returns an error message instead when it cannot. */
    public static function approve(RentalApprovalRequest $approval, User $decider, string $decisionNote = ''): ?string
    {
        // Practice data (the in-app tour) lives in its own tables, seeded by the
        // person taking the tour — they play both sides there.
        $practice = $approval instanceof TutorialRentalApprovalRequest;
        if (! $practice && (int) $approval->requested_by_user_id === (int) $decider->id) {
            return 'You submitted this report — it needs a decision from someone else.';
        }
        $p = $approval->payload ?? [];
        $rentalClass = $approval->rentalModelClass();
        $logClass = $practice ? TutorialRentalActivityLog::class : RentalActivityLog::class;
        $rental = ($practice ? $rentalClass::query() : Rental::withoutGlobalScope('store_scope'))->find((int) ($p['rental_id'] ?? 0));
        if (! $rental) {
            return 'Rental not found — it may have been deleted.';
        }
        if ($rental->status !== 'active') {
            return 'This rent is no longer active (status: '.$rental->status.'), so the report cannot be applied.';
        }

        DB::transaction(function () use ($approval, $decider, $decisionNote, $rental, $p, $practice, $logClass) {
            $changes = self::changes($p);
            $fill = [];
            if (! $rental->picked_up_at && ! empty($p['picked_up_at'])) {
                $fill['picked_up_at'] = Carbon::parse($p['picked_up_at']);
            }
            if (($p['type'] ?? '') === 'return') {
                $fill['status'] = 'returned';
                // Returned on time: an overdue fee nobody paid only exists because the
                // return was never clicked (a receipt adds it to a still-"active" rent).
                $returnedOnTime = ! empty($p['returned_at']) && $rental->end_date
                    && Carbon::parse($p['returned_at'])->toDateString() <= $rental->end_date->toDateString();
                // Payment records only exist for real rents (a practice rent's id can
                // collide with a real one in that table).
                $overduePaid = ($practice ? 0.0 : (float) RentalPaymentRecord::where('rental_id', $rental->id)->where('payment_kind', 'overdue')->sum('amount'))
                    + (float) ($rental->overdue_amount_paid ?? 0);
                if ($returnedOnTime && (float) $rental->overdue_fee_amount > 0.004 && $overduePaid <= 0.004) {
                    $changes['overdue_fee_amount'] = [(float) $rental->overdue_fee_amount, 0];
                    $fill += [
                        'overdue_fee_amount' => 0,
                        'overdue_fee_rate' => null,
                        'overdue_fee_days' => null,
                        'overdue_fee_applied_at' => null,
                        'overdue_charge_through_date' => null,
                    ];
                }
            }
            $rental->forceFill($fill)->save();

            $logClass::record($rental, 'late_done_approved', $changes + [
                'requested_by' => [null, $approval->requestedBy?->name],
                'approved_by' => [null, $decider->name],
            ], note: 'Approval #'.$approval->id.($decisionNote !== '' ? ' · '.$decisionNote : ''), userId: $decider->id);

            if (! $practice) {
                RentalLedgerSync::reconcileRental($rental->fresh());
            }

            $approval->update([
                'status' => 'approved',
                'decided_by_user_id' => $decider->id,
                'decision_note' => $decisionNote,
                'decided_at' => now(),
            ]);
        });

        return null;
    }

    /** One readable History entry: when, and who handed it over / took it back. */
    private static function changes(array $p): array
    {
        $fmt = fn ($d) => $d ? Carbon::parse($d)->format('d M Y H:i') : null;

        return array_filter([
            'picked_up_at' => ! empty($p['picked_up_at']) ? [null, $fmt($p['picked_up_at'])] : null,
            'picked_up_by' => ! empty($p['picked_up_by_name']) ? [null, $p['picked_up_by_name']] : null,
            'returned_at' => ! empty($p['returned_at']) ? [null, $fmt($p['returned_at'])] : null,
            'returned_by' => ! empty($p['returned_by_name']) ? [null, $p['returned_by_name']] : null,
        ]);
    }
}
