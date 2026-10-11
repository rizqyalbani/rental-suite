<?php

namespace App\Support;

use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\TutorialShiftSession;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

/**
 * Single source of truth for a shift's stored money totals so the shift card,
 * the closing snapshot, and the report always agree. Mirrors the report:
 * payment records are authoritative (excluding records of deleted rents);
 * legacy rents without any record fall back to a capped estimate.
 */
class ShiftClosedTotals
{
    /** @return array{received: float, transactions: int} */
    public static function calculate(ShiftSession $shift): array
    {
        // Under the shift's own store, not the caller's selected one (management can act on the other store).
        return StoreContext::runAs((int) $shift->store_id, fn () => self::calculateInStore($shift));
    }

    /** @return array{received: float, transactions: int} */
    private static function calculateInStore(ShiftSession $shift): array
    {
        $rentals = $shift->rentals()->with('outfits')->get();
        // A practice shift never has payment records (practice rents skip RentalPaymentRecorder), and
        // rental_payment_records points at REAL shifts and rents that can share the same number.
        $isPractice = $shift instanceof TutorialShiftSession;
        $records = $isPractice
            ? new EloquentCollection
            : RentalPaymentRecord::query()
                ->where('shift_session_id', $shift->id)
                ->get();

        // Records of soft/force-deleted rents no longer count, matching the report.
        $liveRentalIds = Rental::query()
            ->whereIn('id', $records->pluck('rental_id')->unique())
            ->pluck('id')
            ->map(fn ($v) => (int) $v)
            ->flip();
        $recordTotal = (float) $records
            ->filter(fn ($rec) => isset($liveRentalIds[(int) $rec->rental_id]))
            ->sum('amount');

        $rentalIdsWithAnyRecord = $isPractice
            ? collect()
            : RentalPaymentRecord::query()
                ->whereIn('rental_id', $rentals->pluck('id'))
                ->distinct()
                ->pluck('rental_id')
                ->map(fn ($v) => (int) $v)
                ->flip();
        $taxPercent = max(0, (float) StoreCache::get('pricing_tax_percent', 0));
        $legacyTotal = (float) $rentals
            ->reject(fn ($rental) => isset($rentalIdsWithAnyRecord[(int) $rental->id]))
            ->sum(function ($rental) use ($taxPercent) {
                $net = RentalFinancialSummary::netInclTax($rental, $taxPercent);
                $paid = max(0.0, (float) ($rental->amount_paid ?? 0)) + max(0.0, (float) ($rental->overdue_amount_paid ?? 0));

                return $net > 0 ? min($paid, $net) : $paid;
            });

        return [
            'received' => $recordTotal + $legacyTotal,
            'transactions' => $rentals->count(),
        ];
    }

    /** Refresh stored totals on already-closed shifts after their rents change. */
    public static function recomputeClosedShifts(iterable $shiftIds): void
    {
        $ids = collect($shiftIds)->map(fn ($v) => (int) $v)->filter(fn (int $v) => $v > 0)->unique();
        if ($ids->isEmpty()) {
            return;
        }

        $shifts = ShiftSession::withoutGlobalScope('store_scope')
            ->whereIn('id', $ids)
            ->whereNotNull('closed_at')
            ->get();
        foreach ($shifts as $shift) {
            $totals = self::calculate($shift);
            $shift->update([
                'total_transactions' => $totals['transactions'],
                'total_revenue' => $totals['received'],
            ]);
        }
    }

    /** Shift ids whose stored totals depend on this rent (creation shift + payment shifts). */
    public static function shiftIdsForRental(Rental $rental): array
    {
        $ids = RentalPaymentRecord::query()
            ->where('rental_id', $rental->id)
            ->pluck('shift_session_id')
            ->filter()
            ->map(fn ($v) => (int) $v);
        if ($rental->shift_session_id) {
            $ids->push((int) $rental->shift_session_id);
        }

        return $ids->unique()->values()->all();
    }
}
