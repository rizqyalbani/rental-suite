<?php

namespace App\Support;

use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Models\TutorialRental;

class RentalPaymentRecorder
{
    public static function recordIfNonZero(
        Rental $rental,
        float $amount,
        ?int $shiftSessionId,
        ?int $receivedByUserId,
        ?string $paymentMethod,
        ?string $paymentKind,
        ?string $recordedAt = null,
        float $totalDue = 0.0,
    ): void {
        // rental_payment_records.rental_id has a hard FK to the real
        // `rentals` table — writing a TutorialRental's id there would either
        // throw (no matching real row) or, worse, silently attach to an
        // unrelated real rental that happens to share the id. Nothing in the
        // tutorial UI reads this ledger (the practice rental's own
        // amount_paid field is what's displayed), so there's nothing to lose
        // by skipping it entirely for tutorial data.
        if ($rental instanceof TutorialRental) {
            return;
        }

        $rounded = round($amount, 2);
        if (abs($rounded) <= 0.004) {
            return;
        }

        $record = RentalPaymentRecord::create([
            'rental_id' => $rental->id,
            'shift_session_id' => $shiftSessionId,
            'received_by_user_id' => $receivedByUserId,
            'amount' => $rounded,
            'payment_method' => $paymentMethod !== null ? trim($paymentMethod) : null,
            'payment_kind' => $paymentKind !== null ? trim($paymentKind) : null,
            'recorded_at' => $recordedAt ?? now()->toDateTimeString(),
        ]);

        RentalRevenueRecorder::postForPaymentRecord($record, $rental, $totalDue);
    }
}
