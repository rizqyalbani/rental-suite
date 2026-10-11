<?php

namespace App\Support;

use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalPaymentRecord;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Flags rents in a period whose numbers are likely to disagree with the
 * store's own manual cash sheet — the patterns found while reconciling
 * September by hand: money on a deleted rent, a payment entered weeks later,
 * a bill changed after the period, paid more than the bill, and so on.
 * Read-only; it only describes, it never fixes.
 */
class RentalAnomalyDetector
{
    /** Changes that alter what the customer owes (payment-only edits do not). */
    private const BILL_FIELDS = ['outfit_ids', 'discount', 'discount_type', 'item_discounts', 'extra_charge'];

    /** @return Collection<int, array{rental: Rental, flags: list<string>}> */
    public static function forPeriod(string $from, string $to): Collection
    {
        $start = $from.' 00:00:00';
        $end = $to.' 23:59:59';
        $rp = fn (float $n) => 'Rp '.number_format($n, 0, ',', '.');
        $day = fn ($d) => Carbon::parse($d)->format('d M');

        // Store-scoped through Rental's own global scope (also inside the subquery).
        $storeRentals = Rental::withTrashed()->where('is_tutorial', false);
        $ids = (clone $storeRentals)->whereBetween('created_at', [$start, $end])->pluck('id')
            ->merge(RentalPaymentRecord::query()
                ->whereIn('rental_id', (clone $storeRentals)->select('id'))
                ->whereBetween('recorded_at', [$start, $end])
                ->pluck('rental_id'))
            ->merge(RentalActivityLog::withoutGlobalScopes()
                ->whereIn('rental_id', (clone $storeRentals)->select('id'))
                ->where('action', 'free_approved')
                ->whereBetween('created_at', [$start, $end])
                ->pluck('rental_id'))
            ->unique()->values();
        if ($ids->isEmpty()) {
            return collect();
        }

        $rentals = Rental::withTrashed()->with('outfits')->whereIn('id', $ids)->get();
        $payments = RentalPaymentRecord::query()->whereIn('rental_id', $ids)->orderBy('recorded_at')->get()->groupBy('rental_id');
        $laterLogs = RentalActivityLog::withoutGlobalScopes()->whereIn('rental_id', $ids)
            ->where('created_at', '>', $end)
            ->whereIn('action', ['edit_approved', 'updated', 'items_changed', 'item_removal_approved'])
            ->orderBy('created_at')->get()->groupBy('rental_id');
        $today = now()->toDateString();
        $batchSizes = $payments->flatten()->filter(fn ($r) => $r->created_at)
            ->countBy(fn ($r) => $r->created_at->format('Y-m-d H:i'))->all();
        $correctedIds = RentalActivityLog::withoutGlobalScopes()->whereIn('rental_id', $ids)
            ->where('action', 'payment_corrected')->pluck('rental_id');
        $pendingAssign = \App\Models\RentalApprovalRequest::query()->where('status', 'pending')
            ->where('payload->kind', PaymentShiftAssignment::KIND)->get()
            ->keyBy(fn ($a) => (int) ($a->payload['payment_record_id'] ?? 0));
        $freeLogs = RentalActivityLog::withoutGlobalScopes()->whereIn('rental_id', $ids)
            ->where('action', 'free_approved')->get()->keyBy('rental_id');

        $result = collect();
        foreach ($rentals as $rental) {
            $flags = [];
            $recs = $payments->get($rental->id, collect());
            $rentRecs = $recs->where('payment_kind', '!=', 'overdue');
            $paid = (float) $rentRecs->sum('amount');
            $due = self::due($rental);
            $bookedInPeriod = $rental->created_at && $rental->created_at->between($start, $end);

            if ($rental->trashed()) {
                $delLog = RentalActivityLog::withoutGlobalScopes()->where('rental_id', $rental->id)
                    ->whereIn('action', ['deleted', 'delete_approved'])->latest('id')->first();
                $delReason = $delLog?->changes['delete_reason'][1] ?? null;
                if ($paid > 0.004 && $delReason) {
                    $flags[] = 'Deleted ('.$delReason.'), payment of '.$rp($paid).' cancelled by '.(\App\Models\User::withoutGlobalScopes()->find($delLog->user_id)?->name ?? '?').'.';
                } elseif ($paid > 0.004) {
                    $flags[] = preg_match('/\btest\b/i', (string) $rental->customer_name)
                        ? 'Deleted test data ('.$rp($paid).').'
                        : 'Deleted although it had already received '.$rp($paid).'; check whether the money was returned or is still in the store.';
                }
                $result->push(['rental' => $rental, 'flags' => $flags]);

                continue;
            }

            if ($due <= 1 && (float) $rental->discount > 0 && ! $freeLogs->has($rental->id)) {
                // Made free by typing a discount instead of the Free request: no record of who approved.
                $flags[] = 'Made free manually via a discount, with no approval in the system'.($rental->discount_note ? ' (note: '.$rental->discount_note.')' : '').'.';
            } elseif ($due <= 1 && $paid <= 1 && (float) $rental->amount_paid > 0) {
                $flags[] = 'Bill of '.$rp($due).', probably test data.';
            }
            if ($due > 1 && $paid > $due + 0.5) {
                $flags[] = 'Overpaid by '.$rp($paid - $due).' (paid '.$rp($paid).', bill '.$rp($due).').';
            }
            if (abs((float) $rental->amount_paid - $paid) > 0.5) {
                $flags[] = 'The rental shows '.$rp((float) $rental->amount_paid).' paid, but its payment history adds up to '.$rp($paid).'.';
            }
            $remaining = max(0.0, $due - $paid);
            if ($remaining > 0.5 && $rental->status === 'returned') {
                $flags[] = 'Already returned but still short by '.$rp($remaining).'.';
            } elseif ($remaining > 0.5 && $rental->status === 'active' && $rental->picked_up_at) {
                $flags[] = 'Already picked up but still short by '.$rp($remaining).'.';
            }
            if ($rental->status === 'active' && $rental->picked_up_at && $rental->end_date && $rental->end_date->toDateString() < $today) {
                $flags[] = 'Not returned yet, rental ended '.$day($rental->end_date).'.';
            }

            if ($free = $freeLogs->get($rental->id)) {
                $c = (array) $free->changes;
                $refund = (float) ($c['free_refund_amount'][1] ?? 0);
                $flags[] = 'FREE, approved by '.($c['approved_by'][1] ?? '?').' (requested by '.($c['requested_by'][1] ?? '?').') '.$day($free->created_at)
                    .': bill '.$rp((float) ($c['rent_total'][0] ?? 0)).' → Rp 0'
                    .($refund > 0.004 ? ', refunded '.$rp($refund).' to the customer' : '')
                    .'. '.($c['discount_note'][1] ?? '');
            }

            $corrected = $correctedIds->contains($rental->id);
            foreach ($recs->whereBetween('recorded_at', [$start, $end]) as $rec) {
                if ((float) $rec->amount < 0) {
                    $flags[] = 'Payment correction of '.$rp((float) $rec->amount).' ('.$day($rec->recorded_at).').';
                } elseif (! $rec->shift_session_id) {
                    $wait = $pendingAssign->get($rec->id);
                    $flags[] = 'Payment of '.$rp((float) $rec->amount).' ('.$day($rec->recorded_at).') is not in any shift'
                        .($wait ? '; waiting to be accepted by shift #'.($wait->payload['shift_session_id'] ?? '?') : '; send it to a shift via the "Payments not in any shift" panel (on this dashboard or the Rents page)').'.';
                }
                // A payment typed in by hand long after it happened (a whole batch
                // created in the same minute is the ledger sync backfilling, not a person).
                $batch = $rec->created_at ? ($batchSizes[$rec->created_at->format('Y-m-d H:i')] ?? 0) : 0;
                if (! $corrected && $batch < 5 && $rec->created_at && $rec->recorded_at && $rec->created_at->diffInDays($rec->recorded_at, true) >= 2) {
                    $flags[] = 'Payment of '.$rp((float) $rec->amount).' dated '.$day($rec->recorded_at).' was only entered on '.$day($rec->created_at).'.';
                }
            }
            // A deposit dated at the settlement, weeks after the booking, usually
            // belongs to the booking's own month.
            $firstDp = $rentRecs->where('payment_kind', 'down_payment')->where('amount', '>', 0)->first();
            if ($firstDp && $rental->created_at && $firstDp->recorded_at->between($start, $end)
                && $rental->created_at->diffInDays($firstDp->recorded_at, true) > 3) {
                $flags[] = 'Deposit of '.$rp((float) $firstDp->amount).' recorded on '.$day($firstDp->recorded_at).', although the booking was on '.$day($rental->created_at).'; it may have been paid at booking.';
            }

            if ($bookedInPeriod) {
                $owedAtEnd = $due - (float) $rentRecs->where('recorded_at', '<=', $end)->sum('amount') > 0.5;
                $changed = $laterLogs->get($rental->id, collect())->first(function ($log) use ($owedAtEnd) {
                    if ($log->action === 'updated') {
                        return array_intersect(array_keys((array) ($log->changes ?? [])), self::BILL_FIELDS) !== [];
                    }
                    if ($log->action === 'edit_approved') {
                        // An approved edit usually carries the settlement; it only matters
                        // for the period's receivable while something was still owed.
                        return $owedAtEnd;
                    }

                    return true;
                });
                if ($changed) {
                    $what = $changed->action === 'updated' && isset($changed->changes['outfit_ids'])
                        ? ' (items: '.implode(', ', (array) ($changed->changes['outfit_ids'][0] ?? [])).' → '.implode(', ', (array) ($changed->changes['outfit_ids'][1] ?? [])).')'
                        : '';
                    $flags[] = 'Changed after the period, '.$day($changed->created_at).$what.'; check whether the rental amount changed.';
                }

                $twin = $rental->phone
                    ? Rental::withTrashed()->where('id', '!=', $rental->id)->where('is_tutorial', false)
                        ->where('phone', $rental->phone)
                        ->whereDate('start_date', $rental->start_date)
                        ->whereBetween('created_at', [$rental->created_at->copy()->subDay(), $rental->created_at->copy()->addDay()])
                        ->onlyTrashed()
                        ->first(['id', 'deleted_at'])
                    : null;
                if ($twin) {
                    $flags[] = 'Re-entered from deleted rental #'.$twin->id.' (same phone number and rental date); check whether the amount is the same.';
                }
            }

            if ($flags !== []) {
                $result->push(['rental' => $rental, 'flags' => array_values(array_unique($flags))]);
            }
        }

        return $result->filter(fn ($row) => $row['flags'] !== [])
            ->sortBy(fn ($row) => $row['rental']->created_at)
            ->values();
    }

    private static function due(Rental $rental): float
    {
        try {
            $summary = RentalFinancialSummary::summarize($rental, max(0, (float) StoreCache::get('pricing_tax_percent', 0)));

            return max(0.0, (float) $summary['net_incl_tax'] - max(0.0, (float) ($summary['overdue_fee_amount'] ?? 0)));
        } catch (\Throwable) {
            return 0.0;
        }
    }
}
