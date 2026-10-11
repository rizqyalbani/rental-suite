<?php

namespace App\Support;

use App\Models\InvoiceNumberCounter;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Format: {STORE PREFIX}-{YEAR}-{4-digit sequence}, e.g. "DSB-2026-0001".
 * Sequence resets every year, per store — backed by a dedicated counter row
 * (not MAX(invoice_number)+1 on `rentals`, which races under concurrent
 * creates) locked with lockForUpdate() inside a transaction, so two rentals
 * created at the same moment for the same store never collide.
 */
class InvoiceNumberGenerator
{
    public static function next(int $storeId, ?Carbon $date = null): string
    {
        $year = (int) ($date ?? now())->format('Y');

        $sequence = DB::transaction(function () use ($storeId, $year) {
            $counter = InvoiceNumberCounter::query()
                ->where('store_id', $storeId)
                ->where('year', $year)
                ->lockForUpdate()
                ->first();

            if (! $counter) {
                // firstOrCreate isn't atomic on its own, but the unique
                // (store_id, year) index turns a concurrent double-create
                // into a query exception on the loser — acceptable here since
                // that only happens once per store per year, not per rental.
                $counter = InvoiceNumberCounter::create([
                    'store_id' => $storeId,
                    'year' => $year,
                    'next_sequence' => 1,
                ]);
            }

            $sequence = $counter->next_sequence;
            $counter->increment('next_sequence');

            return $sequence;
        });

        return sprintf('%s-%d-%04d', self::storePrefix($storeId), $year, $sequence);
    }

    /**
     * Fills in invoice_number for every rental that doesn't have one yet
     * (across every store — Super Admin only, see the Settings button),
     * oldest first, so the sequence stays a true chronological order rather
     * than "whatever order this happened to run in". Safe to re-run any
     * number of times: only touches rows where invoice_number IS NULL, so an
     * already-numbered rental is never renumbered.
     *
     * Deliberately excludes is_tutorial=true rentals (real staff practicing
     * with Trial Mode on, not real transactions) from ever getting a real
     * sequence number — TutorialRental (the guided-tour practice data) is
     * already a separate table entirely, so it never reaches this query.
     */
    public static function backfillMissing(): int
    {
        $rentals = \App\Models\Rental::withoutGlobalScope('store_scope')
            ->withTrashed()
            ->where('is_tutorial', false)
            ->whereNull('invoice_number')
            ->whereNotNull('store_id')
            ->orderBy('store_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        foreach ($rentals as $rental) {
            $rental->invoice_number = self::next((int) $rental->store_id, $rental->created_at);
            $rental->saveQuietly();
        }

        return $rentals->count();
    }

    private static function storePrefix(int $storeId): string
    {
        return match ($storeId) {
            1 => 'DSB',
            2 => 'DVK',
            default => 'INV'.$storeId,
        };
    }
}
