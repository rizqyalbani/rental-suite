<?php

namespace Tests\Repro;

use App\Models\Account;
use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalAnomalyDetector;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Throwaway repros for the C5 overnight review (docs/overnight-2026-10-09/03e-dashboard-review.md).
 * Tests named BUG_/INFO_ assert the CURRENT behaviour (a pass = the behaviour is real);
 * OK_ tests are controls that prove the good behaviour.
 * Run: php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Repro/DashboardC5ReproTest.php
 * Every rent costs 300.000 (price_incl_tax == price_excl_tax, tax 0), 3 days.
 */
class DashboardC5ReproTest extends TestCase
{
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    private User $mgmt;

    private int $n = 0;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
        foreach ([
            ['1100', 'Store Cash', 'asset', 'debit', true], ['1200', 'Bank', 'asset', 'debit', true],
            ['1300', 'Rental Receivable', 'asset', 'debit', false], ['2300', 'Customer Deposits', 'liability', 'credit', false],
            ['4100', 'Rental Revenue', 'revenue', 'credit', false], ['4200', 'Late Fee Revenue', 'revenue', 'credit', false],
            ['4400', 'Forfeited Deposits', 'revenue', 'credit', false], ['4910', 'Rental Refunds', 'revenue', 'debit', false],
        ] as [$code, $name, $type, $normal, $cash]) {
            Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_cash_equivalent' => $cash, 'is_active' => true]);
        }
        $this->a = Store::create(['name' => 'Store A', 'code' => 'store-a', 'is_active' => true]);
        $this->b = Store::create(['name' => 'Store B', 'code' => 'store-b', 'is_active' => true]);
        foreach ([$this->a, $this->b] as $s) {
            StoreContext::set($s->id);
            StoreCache::forever('pricing_tax_percent', 0);
            StoreCache::forever('pricing_prices_include_tax', false);
        }
        StoreContext::set($this->a->id);
        $this->mgmt = User::factory()->create(['role' => 'management']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        StoreContext::set(null);
        parent::tearDown();
    }

    /** @param list<array{0: float, 1: string, 2?: ?string}> $payments [amount, recorded_at, kind] */
    private function rent(Store $store, string $name, string $created, string $status, array $payments): Rental
    {
        $prev = [StoreContext::storeId(), StoreContext::allStores()];
        StoreContext::set($store->id);
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $store->id, 'code' => 'S'.(++$this->n), 'price' => 300000, 'stock' => 1]);
        $rental = Rental::create([
            'store_id' => $store->id, 'customer_name' => $name, 'phone' => '0811'.$this->n,
            'start_date' => '2026-09-10', 'end_date' => '2026-09-12', 'status' => $status,
            'payment_method' => 'cash', 'payment_kind' => 'down_payment',
            'amount_paid' => array_sum(array_column($payments, 0)),
        ]);
        $rental->forceFill(['created_at' => $created, 'updated_at' => $created])->saveQuietly();
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 300000, 'tax_amount' => 0]);
        foreach ($payments as $p) {
            $rec = RentalPaymentRecord::create([
                'rental_id' => $rental->id, 'shift_session_id' => null, 'amount' => $p[0], 'payment_method' => 'cash',
                'payment_kind' => array_key_exists(2, $p) ? $p[2] : 'down_payment', 'recorded_at' => $p[1],
            ]);
            $rec->forceFill(['created_at' => $p[1]])->saveQuietly();
        }
        StoreContext::set($prev[0], $prev[1]);

        return $rental->fresh();
    }

    private function dash(string $from, string $to, array $extra = [])
    {
        $r = $this->actingAs($this->mgmt)->withSession(['current_store_id' => $this->a->id])
            ->get(route('accounting.dashboard', ['date_from' => $from, 'date_to' => $to] + $extra));
        $r->assertOk();

        return $r;
    }

    private function num($resp, string $key): float
    {
        return round((float) $resp->viewData($key), 2);
    }

    /** OK: the three agreed formulas on a plain, clean data set. */
    public function test_OK_baseline_three_formulas(): void
    {
        // Old rent (Aug): DP 100k in Aug, rest 200k on 5 Sep.  New rent (Sep): DP 100k.
        $this->rent($this->a, 'Old', '2026-08-20 10:00:00', 'returned', [[100000, '2026-08-20 10:00:00'], [200000, '2026-09-05 10:00:00', 'final_payment']]);
        $this->rent($this->a, 'New', '2026-09-03 10:00:00', 'active', [[100000, '2026-09-03 10:00:00']]);
        $r = $this->dash('2026-09-01', '2026-09-30');

        $this->assertSame(300000.0, $this->num($r, 'rentalRevenue'));        // Cash Collected from Rentals
        $this->assertSame(200000.0, $this->num($r, 'pelunasanLama'));        // settlement of earlier rentals (created before the period)
        $this->assertSame(100000.0, $this->num($r, 'pendapatanDiakuiUpdate'));
        $this->assertSame(200000.0, $this->num($r, 'piutangPeriode'));       // 300k bill - 100k paid
    }

    /** OK: period edges. 23:59:59 is in, 00:00:00 of the next day is out; same for created_at. */
    public function test_OK_period_edges_are_inclusive_and_consistent(): void
    {
        $this->rent($this->a, 'Edge', '2026-08-31 23:59:59', 'active', [
            [10000, '2026-08-31 23:59:59'], [20000, '2026-09-01 00:00:00'], [40000, '2026-09-30 23:59:59'], [80000, '2026-10-01 00:00:00'],
        ]);
        $this->rent($this->a, 'LastSecond', '2026-09-30 23:59:59', 'active', [[1000, '2026-09-30 23:59:59']]);
        $this->rent($this->a, 'NextDay', '2026-10-01 00:00:00', 'active', [[2000, '2026-10-01 00:00:00']]);
        $r = $this->dash('2026-09-01', '2026-09-30');

        $this->assertSame(61000.0, $this->num($r, 'rentalRevenue')); // 20k + 40k + 1k
        $this->assertSame(60000.0, $this->num($r, 'pelunasanLama')); // Edge was created 31 Aug -> its Sept payments (20k+40k)
        $this->assertSame(1, (int) $r->viewData('orderCounts')->sum()); // only LastSecond
    }

    /** BUG (deviation): payments of kind 'overdue' / NULL on a rent booked before the period are NOT subtracted. */
    public function test_BUG_overdue_and_null_kind_payments_of_old_rent_stay_in_pendapatan_diakui(): void
    {
        $this->rent($this->a, 'OldLate', '2026-08-25 10:00:00', 'returned', [
            [300000, '2026-08-25 10:00:00', 'full'],
            [50000, '2026-09-02 10:00:00', 'overdue'],
            [20000, '2026-09-03 10:00:00', null],
            [100000, '2026-09-04 10:00:00', 'final_payment'],
        ]);
        $r = $this->dash('2026-09-01', '2026-09-30');

        $this->assertSame(170000.0, $this->num($r, 'rentalRevenue'));
        $this->assertSame(100000.0, $this->num($r, 'pelunasanLama'));   // only the final_payment
        $this->assertSame(70000.0, $this->num($r, 'pendapatanDiakuiUpdate')); // overdue 50k + NULL 20k counted as "recognised"
    }

    /** BUG (deviation): a rent booked in the period with NO payment yet is not in Receivable and not in "rent value". */
    public function test_BUG_rent_without_any_payment_is_missing_from_piutang(): void
    {
        $this->rent($this->a, 'NoDp', '2026-09-04 10:00:00', 'active', []);            // bill 300k, nothing paid
        $this->rent($this->a, 'WithDp', '2026-09-05 10:00:00', 'active', [[100000, '2026-09-05 10:00:00']]);
        $r = $this->dash('2026-09-01', '2026-09-30');

        $this->assertSame(2, (int) $r->viewData('orderCounts')->sum());   // both are orders
        $this->assertSame(200000.0, $this->num($r, 'piutangPeriode'));    // agreed formula would give 500.000
        $this->assertSame(1, (int) $r->viewData('periodeRental'));        // "N rental(s) created in this period" says 1, not 2
        $this->assertSame(300000.0, $this->num($r, 'periodeTagihan'));    // "rent value" (including what is not yet paid) says 300k, not 600k
    }

    /** INFO: status 'cancelled' (not soft-deleted) keeps its payments in Cash Collected; the controller comment says otherwise. Soft-deleted rents are excluded. */
    public function test_INFO_cancelled_rent_payments_count_deleted_rent_payments_do_not(): void
    {
        $this->rent($this->a, 'Cancelled', '2026-09-04 10:00:00', 'cancelled', [[100000, '2026-09-04 10:00:00']]);
        $del = $this->rent($this->a, 'Deleted', '2026-09-05 10:00:00', 'active', [[70000, '2026-09-05 10:00:00']]);
        $del->delete();
        $r = $this->dash('2026-09-01', '2026-09-30');

        $this->assertSame(100000.0, $this->num($r, 'rentalRevenue'));
        $this->assertSame(0.0, $this->num($r, 'piutangPeriode'));
    }

    /** BUG: deleting a rent from an earlier month silently lowers that closed month; the current period's check list does not show it. */
    public function test_BUG_late_delete_of_old_rent_rewrites_closed_month_and_is_invisible_in_current_period(): void
    {
        Carbon::setTestNow('2026-08-21 09:00:00');
        $old = $this->rent($this->a, 'Aug rent', '2026-08-20 10:00:00', 'active', [[300000, '2026-08-20 10:00:00', 'full']]);
        $this->assertSame(300000.0, $this->num($this->dash('2026-08-01', '2026-08-31'), 'rentalRevenue'));

        Carbon::setTestNow('2026-10-05 09:00:00');
        $old->delete();

        $this->assertSame(0.0, $this->num($this->dash('2026-08-01', '2026-08-31'), 'rentalRevenue'));   // August lost Rp 300.000 two months later
        StoreContext::set($this->a->id);
        $this->assertCount(0, RentalAnomalyDetector::forPeriod('2026-10-01', '2026-10-09'));            // not shown where it happened
        $aug = RentalAnomalyDetector::forPeriod('2026-08-01', '2026-08-31');
        $this->assertCount(1, $aug);                                                                      // only visible if someone opens August
        $this->assertStringContainsString('Deleted although it had already received Rp 300.000', implode(' ', $aug->first()['flags']));
    }

    /** OK: other store's rents and payments are excluded unless all_stores=1. */
    public function test_OK_store_scope(): void
    {
        $this->rent($this->a, 'A', '2026-09-03 10:00:00', 'active', [[100000, '2026-09-03 10:00:00']]);
        $this->rent($this->b, 'B', '2026-09-03 11:00:00', 'active', [[200000, '2026-09-03 11:00:00']]);

        $this->assertSame(100000.0, $this->num($this->dash('2026-09-01', '2026-09-30'), 'rentalRevenue'));
        $this->assertSame(300000.0, $this->num($this->dash('2026-09-01', '2026-09-30', ['all_stores' => 1]), 'rentalRevenue'));

        StoreContext::set($this->a->id);
        $this->assertCount(0, RentalAnomalyDetector::forPeriod('2026-09-01', '2026-09-30')->filter(fn ($r) => $r['rental']->store_id === $this->b->id));
    }

    /** OK: a tutorial (is_tutorial) rent never counts anywhere on the dashboard. */
    public function test_OK_tutorial_rent_is_excluded(): void
    {
        $t = $this->rent($this->a, 'Practice', '2026-09-03 10:00:00', 'active', [[100000, '2026-09-03 10:00:00']]);
        $t->forceFill(['is_tutorial' => true])->saveQuietly();
        $r = $this->dash('2026-09-01', '2026-09-30');
        $this->assertSame(0.0, $this->num($r, 'rentalRevenue'));
        $this->assertSame(0, (int) $r->viewData('orderCounts')->sum());
    }

    /** BUG (likely design): dashboard Recognised Rental Revenue vs the P&L's 4100 for the same month differ whenever a rent straddles a month end. */
    public function test_INFO_dashboard_diakui_vs_ledger_pendapatan_sewa_for_straddling_rent(): void
    {
        Carbon::setTestNow('2026-09-05 10:00:00');
        $rental = $this->rent($this->a, 'Straddle', '2026-09-05 10:00:00', 'active', []);
        $rental->load('outfits');
        StoreContext::set($this->a->id);
        RentalPaymentRecorder::recordIfNonZero($rental, 100000, null, null, 'cash', 'down_payment', totalDue: 300000.0);
        Carbon::setTestNow('2026-10-03 10:00:00');
        RentalPaymentRecorder::recordIfNonZero($rental, 200000, null, null, 'cash', 'final_payment', totalDue: 300000.0);

        $rev = Account::where('code', '4100')->firstOrFail();
        $sepLedger = $rev->balance(null, '2026-09-30', '2026-09-01');
        $octLedger = $rev->balance(null, '2026-10-31', '2026-10-01');
        $sep = $this->dash('2026-09-01', '2026-09-30');
        $oct = $this->dash('2026-10-01', '2026-10-31');

        $this->assertSame(100000.0, $this->num($sep, 'pendapatanDiakuiUpdate'));
        $this->assertSame(0.0, $this->num($oct, 'pendapatanDiakuiUpdate'));
        $this->assertEqualsWithDelta(0.0, $sepLedger, 0.01);      // ledger: nothing recognised in September
        $this->assertEqualsWithDelta(300000.0, $octLedger, 0.01); // ledger: the whole 300k in October
    }

    /** BUG: a payment collected 30 Sep but approved 1 Oct is dated 1 Oct (rent too, for create-approvals): wrong month on the dashboard. */
    public function test_BUG_create_approval_across_month_end_books_rent_and_cash_in_the_next_month(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $sup = User::factory()->create(['role' => 'supervisor']);
        foreach ([$admin, $sup, $this->mgmt] as $u) {
            $u->stores()->attach($this->a->id);
        }
        ShiftSession::create(['opened_by_user_id' => $admin->id, 'opened_at' => '2026-09-30 08:00:00', 'period' => ShiftSession::PERIOD_MORNING]);
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->a->id, 'code' => 'AP1', 'price' => 300000, 'stock' => 1]);

        Carbon::setTestNow('2026-09-30 18:00:00');
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $admin->id, 'source_shift_session_id' => null,
            'payload' => [
                'kind' => 'create',
                'rental' => [
                    'customer_name' => 'Edge Customer', 'phone' => '0811', 'start_date' => '2026-10-10', 'end_date' => '2026-10-12',
                    'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 100000,
                ],
                'outfit_ids' => [$outfit->id], 'item_discounts' => [], 'item_discount_types' => [],
            ],
        ]);

        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->actingAs($sup)->withSession(['current_store_id' => $this->a->id])->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();

        StoreContext::set($this->a->id);
        $rental = Rental::where('customer_name', 'Edge Customer')->firstOrFail();
        $rec = RentalPaymentRecord::where('rental_id', $rental->id)->firstOrFail();
        $this->assertSame('2026-10-01', $rental->created_at->toDateString());   // booked 30 Sep, dated 1 Oct
        $this->assertSame('2026-10-01', $rec->recorded_at->toDateString());     // cash handed over 30 Sep, dated 1 Oct
        $sep = $this->dash('2026-09-01', '2026-09-30');
        $this->assertSame(0.0, $this->num($sep, 'rentalRevenue'));
    }

    /** INFO / FN: a cancelled rent that holds (or was refunded) money is never listed in "Rentals to Review". */
    public function test_BUG_detector_ignores_cancelled_rent_with_money(): void
    {
        $c = $this->rent($this->a, 'Cancelled DP', '2026-09-04 10:00:00', 'cancelled', [[100000, '2026-09-04 10:00:00']]);
        StoreContext::set($this->a->id);
        $row = RentalAnomalyDetector::forPeriod('2026-09-01', '2026-09-30')->first(fn ($r) => $r['rental']->id === $c->id);
        // payments sit without a shift -> that flag shows up, but nothing mentions the cancellation / forfeit / refund.
        $this->assertStringNotContainsString('forfeit', strtolower(implode(' ', $row['flags'] ?? [])));
        $this->assertStringNotContainsString('cancel', strtolower(implode(' ', $row['flags'] ?? [])));
    }

    /** FP: a small (<5) ledger-sync backfill is reported as "was only entered on" (person typed it late). */
    public function test_BUG_small_sync_backfill_batch_is_reported_as_late_input(): void
    {
        $recs = [];
        foreach ([1, 2, 3] as $i) {
            $r = $this->rent($this->a, 'Sync'.$i, '2026-09-0'.$i.' 10:00:00', 'returned', [[300000, '2026-09-0'.$i.' 10:00:00', 'full']]);
            RentalPaymentRecord::where('rental_id', $r->id)->update(['created_at' => '2026-09-25 22:31:00']);
            $recs[] = $r;
        }
        StoreContext::set($this->a->id);
        $rows = RentalAnomalyDetector::forPeriod('2026-09-01', '2026-09-30');
        foreach ($recs as $r) {
            $row = $rows->first(fn ($x) => $x['rental']->id === $r->id);
            $this->assertNotNull($row);
            $this->assertStringContainsString('was only entered on 25 Sep', implode(' ', $row['flags']));
        }
    }

    /** BUG: deactivating an account that has real balance silently drops it from the Trial Balance / Neraca: totals stop matching. */
    public function test_BUG_trial_balance_drops_inactive_account_with_balance(): void
    {
        Carbon::setTestNow('2026-09-05 10:00:00');
        $rental = $this->rent($this->a, 'TB', '2026-09-05 10:00:00', 'active', []);
        $rental->load('outfits');
        StoreContext::set($this->a->id);
        RentalPaymentRecorder::recordIfNonZero($rental, 100000, null, null, 'cash', 'down_payment', totalDue: 300000.0);

        $ok = $this->actingAs($this->mgmt)->withSession(['current_store_id' => $this->a->id])->get(route('accounting.reports.trial-balance', ['as_of' => '2026-09-30']));
        $this->assertEqualsWithDelta($ok->viewData('totalDebit'), $ok->viewData('totalCredit'), 0.01);
        $this->assertEqualsWithDelta(300000.0, $ok->viewData('totalDebit'), 0.01);

        // The accounts screen allows this (no history check on is_active).
        Account::where('code', '1100')->update(['is_active' => false]);
        $bad = $this->actingAs($this->mgmt)->withSession(['current_store_id' => $this->a->id])->get(route('accounting.reports.trial-balance', ['as_of' => '2026-09-30']));
        $this->assertEqualsWithDelta(200000.0, $bad->viewData('totalDebit'), 0.01);   // Cash Rp 100.000 vanished
        $this->assertEqualsWithDelta(300000.0, $bad->viewData('totalCredit'), 0.01);
    }

    /** INFO: the dashboard issues a growing number of queries per rent in the period (N+1). Prints the numbers. */
    public function test_INFO_dashboard_query_count_grows_with_rentals(): void
    {
        $count = function (int $rents): int {
            \Illuminate\Support\Facades\DB::flushQueryLog();
            \Illuminate\Support\Facades\DB::enableQueryLog();
            $this->dash('2026-09-01', '2026-09-30');
            $n = count(\Illuminate\Support\Facades\DB::getQueryLog());
            \Illuminate\Support\Facades\DB::disableQueryLog();

            return $n;
        };
        for ($i = 0; $i < 5; $i++) {
            $this->rent($this->a, 'Q'.$i, '2026-09-03 10:00:00', 'active', [[100000, '2026-09-03 10:00:00']]);
        }
        $q5 = $count(5);
        for ($i = 5; $i < 25; $i++) {
            $this->rent($this->a, 'Q'.$i, '2026-09-03 10:00:00', 'active', [[100000, '2026-09-03 10:00:00']]);
        }
        $q25 = $count(25);
        fwrite(STDERR, "\nQUERIES dashboard: 5 rents = $q5, 25 rents = $q25, per extra rent = ".round(($q25 - $q5) / 20, 1)."\n");
        $this->assertGreaterThan($q5, $q25);
    }

    /** OK: DateInput falls back to defaults for garbage and a reversed range gives zeros, not an error. */
    public function test_OK_reversed_and_garbage_dates_do_not_crash(): void
    {
        $this->rent($this->a, 'X', '2026-09-03 10:00:00', 'active', [[100000, '2026-09-03 10:00:00']]);
        $r = $this->dash('2026-09-30', '2026-09-01');
        $this->assertSame(0.0, $this->num($r, 'rentalRevenue'));
        $this->actingAs($this->mgmt)->withSession(['current_store_id' => $this->a->id])
            ->get(route('accounting.dashboard', ['date_from' => 'abc', 'date_to' => ['x']]))->assertOk();
    }
}
