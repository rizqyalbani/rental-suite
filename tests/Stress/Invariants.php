<?php

namespace Tests\Stress;

use App\Models\ShiftSession;
use App\Support\RentalLedgerSync;
use App\Support\ShiftClosedTotals;
use App\Support\StoreContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Checks that must hold after EVERY step of the walk. They read the raw tables (no global scopes,
 * no app helpers) so they are an independent second opinion on what the app reports.
 *
 * A violation is recorded once per (invariant, entity); after that it is muted for the seed so one
 * root cause does not bury a second, different problem.
 */
final class Invariants
{
    /** @var array<string, true> */
    private array $muted = [];

    /** @var list<string> first-run sync changes: the live flows left the ledger needing repair (information) */
    public array $drift = [];

    /** @var array<string, int> mismatches that match a KNOWN finding (not violations) */
    public array $knownHits = [];

    /** @var array<string, int> */
    private array $accountIds = [];

    private int $round = 0;

    public int $dashboardChecks = 0;

    public int $dashboardNonZero = 0;

    public function __construct(private readonly StressWorld $w)
    {
    }

    public function check(): void
    {
        $this->round++;
        $this->accountIds = DB::table('accounts')->pluck('id', 'code')->map(fn ($v) => (int) $v)->all();
        $this->payments();
        $this->journal();
        $this->negatives();
        $this->refundsAndOverpay();
        $this->cancelledAndDeletedLedger();
        $this->statuses();
        $this->shifts();
        $this->dashboard($this->round % 6, ($this->round % 2) + 1);
        $this->syncIdempotent();
    }

    /** End-of-walk sweep: every date range on both stores. */
    public function finalSweep(): void
    {
        for ($range = 0; $range < 6; $range++) {
            foreach ([1, 2] as $store) {
                $this->dashboard($range, $store);
            }
        }
    }

    private function fail(string $id, string $entity, string $msg): void
    {
        $key = $id.'|'.$entity;
        if (isset($this->muted[$key])) {
            return;
        }
        $this->muted[$key] = true;
        $this->w->violate($id, "{$entity}: {$msg}");
    }

    // ---------------------------------------------------------------- I1 payments

    private function payments(): void
    {
        foreach (DB::table('rentals')->whereNull('deleted_at')->get() as $r) {
            $recorded = $this->w->paidOf((int) $r->id);
            if (abs($recorded - (float) $r->amount_paid) > 0.01) {
                $this->fail('I1-paid-vs-records', "rental#{$r->id}", sprintf('rentals.amount_paid=%s but payment records add up to %s (status %s)', (float) $r->amount_paid, $recorded, $r->status));
            }
        }
    }

    // ---------------------------------------------------------------- I2 journal

    private function journal(): void
    {
        $rows = DB::table('journal_entry_lines')->select('journal_entry_id', DB::raw('SUM(debit) as d'), DB::raw('SUM(credit) as c'))->groupBy('journal_entry_id')->get();
        foreach ($rows as $row) {
            if (abs((float) $row->d - (float) $row->c) > 0.005) {
                $this->fail('I2-journal-unbalanced', "journal#{$row->journal_entry_id}", "debit {$row->d} != credit {$row->c}");
            }
        }
        $empty = DB::table('journal_entries')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('journal_entry_lines')->whereColumn('journal_entry_lines.journal_entry_id', 'journal_entries.id'))->pluck('id');
        foreach ($empty as $id) {
            $this->fail('I2-journal-empty', "journal#{$id}", 'entry has no lines');
        }

        // I15: the cash + bank accounts of the ledger equal what the payment records say came in, minus what was handed back.
        $cashIds = DB::table('accounts')->whereIn('code', ['1100', '1200'])->pluck('id');
        $ledgerCash = round((float) DB::table('journal_entry_lines')->whereIn('account_id', $cashIds)->sum(DB::raw('debit - credit')), 2);
        $received = round((float) DB::table('rental_payment_records as p')->join('rentals as r', 'r.id', '=', 'p.rental_id')->whereNull('r.deleted_at')->sum('p.amount'), 2);
        $handedBack = 0.0;
        foreach (DB::table('rentals')->pluck('id') as $rid) {
            $handedBack += $this->w->cashBack((int) $rid);
        }
        if (abs($ledgerCash - ($received - $handedBack)) > 0.02) {
            $this->fail('I15-cash-ledger-vs-payments', 'ledger cash', "cash+bank in the ledger = {$ledgerCash}, payment records of live rents ({$received}) minus money handed back ({$handedBack}) = ".round($received - $handedBack, 2));
        }

        // I10: every real payment has its journal entry (posting failures are swallowed by design).
        $missing = DB::table('rental_payment_records as p')->join('rentals as r', 'r.id', '=', 'p.rental_id')
            ->whereNull('r.deleted_at')->whereNull('p.journal_entry_id')->where('p.amount', '!=', 0)->whereNotNull('r.store_id')
            ->select('p.id', 'p.rental_id', 'p.amount', 'p.payment_kind')->get();
        foreach ($missing as $m) {
            $this->fail('I10-payment-without-journal', "payment#{$m->id}", "rental#{$m->rental_id} {$m->payment_kind} {$m->amount} has no journal entry");
        }
    }

    // ---------------------------------------------------------------- I3 negatives

    private function negatives(): void
    {
        foreach (DB::table('rentals')->whereNull('deleted_at')->get() as $r) {
            foreach (['amount_paid', 'discount', 'extra_charge', 'down_payment_amount', 'overdue_fee_amount', 'overdue_amount_paid'] as $col) {
                if ($r->{$col} !== null && (float) $r->{$col} < -0.004) {
                    $this->fail('I3-negative-amount', "rental#{$r->id}.{$col}", (string) $r->{$col});
                }
            }
            if ($this->w->paidOf((int) $r->id) < -0.004) {
                $this->fail('I3-negative-paid-total', "rental#{$r->id}", 'payment records add up to '.$this->w->paidOf((int) $r->id));
            }
            if ($this->w->billOf($r) < -0.004) {
                $this->fail('I3-negative-bill', "rental#{$r->id}", (string) $this->w->billOf($r));
            }
        }
        foreach (DB::table('rental_outfit')->get() as $p) {
            foreach (['price_incl_tax', 'discount'] as $col) {
                if ($p->{$col} !== null && (float) $p->{$col} < -0.004) {
                    $this->fail('I3-negative-amount', "rental_outfit {$p->rental_id}/{$p->outfit_id}.{$col}", (string) $p->{$col});
                }
            }
        }
        foreach (DB::table('shift_sessions')->where('total_revenue', '<', -0.004)->get() as $s) {
            $this->fail('I3-negative-amount', "shift#{$s->id}.total_revenue", (string) $s->total_revenue);
        }
        $neg = DB::table('journal_entry_lines')->where(fn ($q) => $q->where('debit', '<', 0)->orWhere('credit', '<', 0))->pluck('id');
        foreach ($neg as $id) {
            $this->fail('I3-negative-journal-line', "line#{$id}", 'negative debit or credit');
        }
    }

    // ---------------------------------------------------------------- I4 / I12 refunds and overpay

    private function cashBack(int $rentalId): float
    {
        return $this->w->cashBack($rentalId);
    }

    private function refundsAndOverpay(): void
    {
        foreach (DB::table('rentals')->whereNull('deleted_at')->get() as $r) {
            $paid = $this->w->paidOf((int) $r->id);
            $back = $this->cashBack((int) $r->id);
            if ($back > $paid + 0.01) {
                $this->fail('I4-refund-exceeds-paid', "rental#{$r->id}", "cash handed back {$back} > received {$paid} (status {$r->status})");
            }
            if (in_array($r->status, ['active', 'returned'], true)) {
                $bill = $this->w->billOf($r);
                $net = $paid - $back;
                if ($net > $bill + 0.01) {
                    $this->fail('I12-overpaid-not-refunded', "rental#{$r->id}", "received {$paid} - handed back {$back} = {$net} > bill {$bill} (status {$r->status})");
                }
            }
        }
    }

    // ---------------------------------------------------------------- I5 cancelled / deleted leave nothing live

    /** @return array<string, float> account code => net debit of everything booked for this rent */
    private function ledgerNet(int $rentalId): array
    {
        $recordIds = DB::table('rental_payment_records')->where('rental_id', $rentalId)->pluck('id');
        $approvalIds = DB::table('rental_approval_requests')->where('payload->rental_id', $rentalId)->pluck('id');
        $entryIds = DB::table('journal_entries')->where(function ($q) use ($rentalId, $recordIds, $approvalIds) {
            $q->where(fn ($w) => $w->where('reference_type', 'rental_payment')->whereIn('reference_id', $recordIds))
                ->orWhere(fn ($w) => $w->whereIn('reference_type', ['rental_adjustment', 'rental_refund', 'rental_cancellation'])->where('reference_id', $rentalId))
                ->orWhere(fn ($w) => $w->where('reference_type', 'item_removal_refund')->whereIn('reference_id', $approvalIds));
        })->pluck('id');
        if ($entryIds->isEmpty()) {
            return [];
        }
        $codeById = array_flip($this->accountIds);
        $net = [];
        foreach (DB::table('journal_entry_lines')->whereIn('journal_entry_id', $entryIds)->select('account_id', DB::raw('SUM(debit - credit) as n'))->groupBy('account_id')->get() as $row) {
            $net[$codeById[(int) $row->account_id] ?? (string) $row->account_id] = round((float) $row->n, 2);
        }

        return $net;
    }

    private function cancelledAndDeletedLedger(): void
    {
        foreach (DB::table('rentals')->where(fn ($q) => $q->whereNotNull('deleted_at')->orWhere('status', 'cancelled'))->get() as $r) {
            $net = $this->ledgerNet((int) $r->id);
            $deleted = $r->deleted_at !== null;
            if ($deleted) {
                foreach (['4100', '2300', '1300'] as $code) {
                    if (abs($net[$code] ?? 0.0) > 0.01) {
                        $this->fail('I5-deleted-rental-leaves-ledger', "rental#{$r->id}", "deleted rent still carries net {$net[$code]} on account {$code}");
                    }
                }
                continue;
            }
            foreach (['2300', '1300'] as $code) {
                if (abs($net[$code] ?? 0.0) > 0.01) {
                    $this->fail('I5-cancelled-rental-open-balance', "rental#{$r->id}", "cancelled rent still carries net {$net[$code]} on account {$code} (".($code === '2300' ? 'Customer Deposits' : 'Receivable').')');
                }
            }
            $refundCancel = DB::table('journal_entries')->where('reference_type', 'rental_cancellation')->where('reference_id', $r->id)->where('is_reversal', false)
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('journal_entries as rev')->whereColumn('rev.reverses_journal_entry_id', 'journal_entries.id'))
                ->where('memo', 'like', '%refunded%')->exists();
            if ($refundCancel) {
                $revenueLeft = -($net['4100'] ?? 0.0) - ($net['4910'] ?? 0.0); // credit 4100 minus debit 4910
                if (abs($revenueLeft) > 0.01) {
                    $this->fail('I5-cancelled-refund-keeps-revenue', "rental#{$r->id}", "cancelled WITH refund but revenue net of returns is {$revenueLeft}");
                }
            }
        }
    }

    // ---------------------------------------------------------------- I11 status rules + I13 bill math

    private function statuses(): void
    {
        foreach (DB::table('rentals')->whereNull('deleted_at')->get() as $r) {
            // I13: the app's bill (RentalFinancialSummary, used by invoices / reports / reminders) equals the independent recomputation.
            $original = [StoreContext::storeId(), StoreContext::allStores()];
            StoreContext::set((int) $r->store_id);
            try {
                $model = \App\Models\Rental::withoutGlobalScopes()->with('outfits')->find($r->id);
                $app = (float) \App\Support\RentalFinancialSummary::summarize($model, 10)['net_incl_tax'];
            } finally {
                StoreContext::set($original[0], $original[1]);
            }
            $mine = $this->w->billOf($r);
            if (abs($app - $mine) > 0.02) {
                $this->fail('I13-bill-math', "rental#{$r->id}", "RentalFinancialSummary says {$app}, independent recomputation (pivot prices x blocks - discounts) says {$mine}");
            }

            if (! in_array($r->status, ['active', 'returned', 'cancelled'], true)) {
                $this->fail('I11-bad-status', "rental#{$r->id}", (string) $r->status);
            }
            if ($r->status === 'returned') {
                // I14: the money really held (received minus handed back) covers the bill. Refunds are journal-only, so this is
                // NOT the same as "payment records cover the bill" (N3/N3b: an edit after a refund is absorbed by stale amount_paid).
                $held = round($this->w->paidOf((int) $r->id) - $this->w->cashBack((int) $r->id), 2);
                $short = round($this->w->billOf($r) - $held, 2);
                if ($short > 0.01) {
                    $this->fail('I14-returned-but-money-handed-back', "rental#{$r->id}", "returned with bill ".$this->w->billOf($r).", received ".$this->w->paidOf((int) $r->id)." but ".$this->w->cashBack((int) $r->id)." was handed back: Rp {$short} short");
                }
                $left = round($this->w->billOf($r) - $this->w->paidOf((int) $r->id), 2);
                if ($left > 0.01) {
                    $this->fail('I11-returned-but-unpaid', "rental#{$r->id}", "returned with Rp {$left} of the bill still unpaid (bill ".$this->w->billOf($r).', received '.$this->w->paidOf((int) $r->id).')');
                }
            }
        }
    }

    // ---------------------------------------------------------------- I7 shifts

    private function shifts(): void
    {
        foreach (DB::table('shift_sessions')->orderBy('id')->get() as $row) {
            $id = (int) $row->id;
            $rawReceived = round((float) DB::table('rental_payment_records as p')->join('rentals as r', 'r.id', '=', 'p.rental_id')
                ->whereNull('r.deleted_at')->where('p.shift_session_id', $id)->sum('p.amount'), 2);
            $rawTransactions = DB::table('rentals')->whereNull('deleted_at')->where('shift_session_id', $id)->count();

            $original = [StoreContext::storeId(), StoreContext::allStores()];
            StoreContext::set((int) $row->store_id);
            try {
                $shift = ShiftSession::withoutGlobalScopes()->find($id);
                $calc = ShiftClosedTotals::calculate($shift);
                $stats = $this->shiftStats($shift);
            } finally {
                StoreContext::set($original[0], $original[1]);
            }

            if (abs($calc['received'] - $rawReceived) > 0.01) {
                $this->fail('I7-shift-calculate-vs-records', "shift#{$id}", "ShiftClosedTotals says {$calc['received']}, payment records of the shift add up to {$rawReceived}");
            }
            if ($calc['transactions'] !== $rawTransactions) {
                $this->fail('I7-shift-transactions', "shift#{$id}", "ShiftClosedTotals counts {$calc['transactions']} rents, table has {$rawTransactions}");
            }
            if ($stats !== null && abs($stats['total_received'] - $rawReceived) > 0.01) {
                $negative = DB::table('rental_payment_records')->where('shift_session_id', $id)->groupBy('rental_id')->havingRaw('SUM(amount) < 0')->exists();
                if ($negative) {
                    $this->knownHits['C4-01 (negative per-rent total floored in shift card)'] = ($this->knownHits['C4-01 (negative per-rent total floored in shift card)'] ?? 0) + 1;
                } else {
                    $this->fail('I7-shift-card-vs-records', "shift#{$id}", "shift card (computeShiftStats) shows {$stats['total_received']}, payment records add up to {$rawReceived}");
                }
            }

            if ($row->closed_at !== null) {
                $storedRevenue = round((float) $row->total_revenue, 2);
                if (abs($storedRevenue - $rawReceived) > 0.01 || (int) $row->total_transactions !== $rawTransactions) {
                    // Known: payments recorded into an already-closed shift by an approval (C2-5 / C4-07) are not recounted.
                    $late = DB::table('rental_payment_records')->where('shift_session_id', $id)->where('created_at', '>', $row->closed_at)->exists()
                        || DB::table('rentals')->where('shift_session_id', $id)->where('created_at', '>', $row->closed_at)->exists();
                    if ($late) {
                        $this->knownHits['C2-5/C4-07 (money booked into a closed shift is not recounted)'] = ($this->knownHits['C2-5/C4-07 (money booked into a closed shift is not recounted)'] ?? 0) + 1;
                    } else {
                        $this->fail('I7-closed-shift-stored-total-stale', "shift#{$id}", "closed shift stores revenue {$storedRevenue} / {$row->total_transactions} rents, live data says {$rawReceived} / {$rawTransactions} (no payment was added after the close)");
                    }
                }
            }
        }
    }

    /** RentalController::computeShiftStats is private; the shift card is the user-visible number. */
    private function shiftStats(ShiftSession $shift): ?array
    {
        try {
            $controller = app(\App\Http\Controllers\RentalController::class);
            $m = new \ReflectionMethod($controller, 'computeShiftStats');
            $m->setAccessible(true);

            return $m->invoke($controller, $shift);
        } catch (\Throwable $e) {
            $this->fail('I7-shift-card-crash', "shift#{$shift->id}", get_class($e).': '.$e->getMessage());

            return null;
        }
    }

    // ---------------------------------------------------------------- I8 dashboard

    /** @return array{0: string, 1: string} */
    private function range(int $which): array
    {
        $today = Carbon::now()->toDateString();

        return match ($which) {
            0 => [Carbon::now()->startOfMonth()->toDateString(), $today],
            1 => [$today, $today],
            2 => [Carbon::now()->subDay()->toDateString(), $today],
            3 => [$this->w->startedAt->toDateString(), $today],
            4 => [Carbon::now()->subMonthNoOverflow()->startOfMonth()->toDateString(), Carbon::now()->subMonthNoOverflow()->endOfMonth()->toDateString()],
            default => [Carbon::now()->subDays(3)->toDateString(), Carbon::now()->subDay()->toDateString()],
        };
    }

    private function dashboard(int $rangeIndex, int $store): void
    {
        [$from, $to] = $this->range($rangeIndex);
        $actor = $store === 1 ? 'mgr1' : 'mgr2';
        $res = $this->w->http('get', route('accounting.dashboard', ['date_from' => $from, 'date_to' => $to]), [], $actor, $store);
        if ($res['status'] !== 200) {
            $this->fail('I8-dashboard-http', "store{$store} {$from}..{$to}", "dashboard answered HTTP {$res['status']}: ".mb_substr($res['body'], 0, 200));

            return;
        }
        $view = $res['res'];
        $app = [
            'Cash Collected from Rentals' => (float) $view->viewData('rentalRevenue'),
            'Settlement of earlier rentals' => (float) $view->viewData('pelunasanLama'),
            'Recognised Rental Revenue' => (float) $view->viewData('pendapatanDiakuiUpdate'),
            'Outstanding Rental Receivables' => (float) $view->viewData('piutangPeriode'),
            'Cash' => (float) $view->viewData('rentalCash'),
        ];

        // ---- independent recomputation from the raw rental + payment tables
        $recs = fn () => DB::table('rental_payment_records as p')->join('rentals as r', 'r.id', '=', 'p.rental_id')
            ->whereNull('r.deleted_at')->where('r.store_id', $store)
            ->where('p.recorded_at', '>=', $from.' 00:00:00')->where('p.recorded_at', '<=', $to.' 23:59:59');
        $kas = round((float) $recs()->sum('p.amount'), 2);
        $cash = round((float) $recs()->where('p.payment_method', 'cash')->sum('p.amount'), 2);
        $lama = round((float) $recs()->whereRaw('date(r.created_at) < ?', [$from])->whereIn('p.payment_kind', ['final_payment', 'down_payment', 'full'])->sum('p.amount'), 2);
        $piutang = 0.0;
        $rentals = DB::table('rentals')->whereNull('deleted_at')->where('store_id', $store)->where('is_tutorial', false)->whereIn('status', ['active', 'returned'])
            ->whereRaw('date(created_at) >= ?', [$from])->whereRaw('date(created_at) <= ?', [$to])->get();
        foreach ($rentals as $r) {
            $paid = round((float) DB::table('rental_payment_records')->where('rental_id', $r->id)->where('recorded_at', '<=', $to.' 23:59:59')
                ->where(fn ($q) => $q->whereNull('payment_kind')->orWhere('payment_kind', '!=', 'overdue'))->sum('amount'), 2);
            if ($paid <= 0.004) {
                continue;
            }
            $piutang += round(max(0.0, $this->w->billOf($r) - $paid), 2);
        }
        $expected = [
            'Cash Collected from Rentals' => $kas,
            'Settlement of earlier rentals' => $lama,
            'Recognised Rental Revenue' => round($kas - $lama, 2),
            'Outstanding Rental Receivables' => round($piutang, 2),
            'Cash' => $cash,
        ];
        $this->dashboardChecks++;
        if ($expected['Cash Collected from Rentals'] > 0 || $expected['Outstanding Rental Receivables'] > 0) {
            $this->dashboardNonZero++;
        }
        foreach ($expected as $name => $value) {
            if (abs($app[$name] - $value) > 0.02) {
                $this->fail('I8-dashboard-formula', "{$name} store{$store} {$from}..{$to}", "dashboard shows {$app[$name]}, recomputation from raw tables gives {$value}");
            }
        }
    }

    // ---------------------------------------------------------------- I6 ledger sync idempotent

    private function syncIdempotent(): void
    {
        $count = fn () => [
            DB::table('journal_entries')->count(), DB::table('journal_entry_lines')->count(),
            DB::table('rental_payment_records')->count(), round((float) DB::table('journal_entry_lines')->sum('debit'), 2),
        ];
        $original = [StoreContext::storeId(), StoreContext::allStores()];
        DB::beginTransaction();
        try {
            $a0 = $count();
            $first = RentalLedgerSync::run();
            $a1 = $count();
            $second = RentalLedgerSync::run();
            $a2 = $count();
        } catch (\Throwable $e) {
            DB::rollBack();
            StoreContext::set($original[0], $original[1]);
            $this->fail('I6-sync-crash', 'RentalLedgerSync::run', get_class($e).': '.$e->getMessage());

            return;
        }
        DB::rollBack();
        StoreContext::set($original[0], $original[1]);

        if ($a2 !== $a1 || ($second['created'] ?? 0) + ($second['fixed'] ?? 0) + ($second['adjusted'] ?? 0) > 0) {
            $this->fail('I6-sync-not-idempotent', 'RentalLedgerSync::run', 'second run still changed data: '.json_encode(['after1' => $a1, 'after2' => $a2, 'second' => array_intersect_key($second, array_flip(['created', 'fixed', 'adjusted', 'failed']))]));
        }
        if ($a1 !== $a0 || ($first['created'] ?? 0) + ($first['fixed'] ?? 0) + ($first['adjusted'] ?? 0) > 0 || ($first['failed'] ?? 0) > 0) {
            // Information: the live flows left something for the repair to do.
            $this->drift[] = sprintf('step %d: sync repaired %s (before %s, after %s) failures=%s review=%s', $this->w->step,
                json_encode(array_intersect_key($first, array_flip(['created', 'fixed', 'adjusted', 'failed']))), json_encode($a0), json_encode($a1),
                json_encode($first['failures'] ?? []), json_encode($first['review'] ?? []));
        }
    }
}
