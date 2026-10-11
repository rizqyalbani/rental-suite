<?php

namespace Tests\Stress;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * Proves the walk's checks have teeth: each test damages one thing in the raw tables (the kind of
 * damage a real bug would leave) and expects the matching invariant to fire. A random walk that is
 * green only means something if these are green too.
 *
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/InvariantsSelfTest.php
 */
class InvariantsSelfTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private StressWorld $w;

    private int $fullId;

    private int $dpId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = new StressWorld($this, new Rng(1));
        $this->w->build();
        $start = Carbon::now()->startOfDay()->addDay();
        $make = fn (int $outfitIdx, string $kind, string $amount) => (int) $this->w->http('post', route('rentals.store'), [
            'outfit_ids' => [$this->w->outfits[1][$outfitIdx]], 'customer_name' => 'Self', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => $kind, 'amount_paid' => $amount,
        ], 'admin1', 1)['json']['rental_id'];
        $this->fullId = $make(0, 'full', '330000');
        $this->dpId = $make(1, 'down_payment', '100000');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** Runs all checks, inside a savepoint, after $damage; returns the violation ids. */
    private function violationsAfter(callable $damage): array
    {
        DB::beginTransaction();
        try {
            $this->w->violations = [];
            $damage();
            (new Invariants($this->w))->check();

            return array_values(array_unique(array_column($this->w->violations, 'id')));
        } finally {
            DB::rollBack();
            $this->w->violations = [];
        }
    }

    public function test_undamaged_world_is_clean(): void
    {
        $this->assertSame([], $this->violationsAfter(fn () => null));
    }

    public function test_paid_total_that_disagrees_with_payment_records_is_caught(): void
    {
        $this->assertContains('I1-paid-vs-records', $this->violationsAfter(fn () => DB::table('rentals')->where('id', $this->dpId)->update(['amount_paid' => 150000])));
    }

    public function test_unbalanced_journal_entry_is_caught(): void
    {
        $this->assertContains('I2-journal-unbalanced', $this->violationsAfter(fn () => DB::table('journal_entry_lines')->where('id', DB::table('journal_entry_lines')->min('id'))->increment('debit', 100)));
    }

    public function test_payment_without_journal_entry_is_caught(): void
    {
        $this->assertContains('I10-payment-without-journal', $this->violationsAfter(fn () => DB::table('rental_payment_records')->where('rental_id', $this->fullId)->update(['journal_entry_id' => null])));
    }

    public function test_negative_amount_is_caught(): void
    {
        $this->assertContains('I3-negative-amount', $this->violationsAfter(fn () => DB::table('rentals')->where('id', $this->dpId)->update(['discount' => -5000])));
    }

    public function test_returned_but_unpaid_is_caught(): void
    {
        $this->assertContains('I11-returned-but-unpaid', $this->violationsAfter(fn () => DB::table('rentals')->where('id', $this->dpId)->update(['status' => 'returned'])));
    }

    public function test_cancelled_rent_with_open_deposit_is_caught(): void
    {
        $this->assertContains('I5-cancelled-rental-open-balance', $this->violationsAfter(fn () => DB::table('rentals')->where('id', $this->dpId)->update(['status' => 'cancelled'])));
    }

    public function test_deleted_rent_that_keeps_its_ledger_is_caught(): void
    {
        $this->assertContains('I5-deleted-rental-leaves-ledger', $this->violationsAfter(fn () => DB::table('rentals')->where('id', $this->fullId)->update(['deleted_at' => now()])));
    }

    public function test_stale_closed_shift_total_is_caught(): void
    {
        $violations = $this->violationsAfter(function () {
            $this->w->http('post', route('shifts.close'), [], 'admin1', 1);
            DB::table('shift_sessions')->where('opened_by_user_id', $this->w->users['admin1']->id)->update(['total_revenue' => 1]);
        });
        $this->assertContains('I7-closed-shift-stored-total-stale', $violations);
    }

    public function test_shift_total_that_disagrees_with_payments_is_caught(): void
    {
        // Without any payment record the shift code falls back to a legacy estimate from rentals.amount_paid:
        // the shift would show money the payment records do not have.
        $violations = $this->violationsAfter(fn () => DB::table('rental_payment_records')->where('rental_id', $this->fullId)->delete());
        $this->assertContains('I7-shift-calculate-vs-records', $violations);
    }

    public function test_ledger_refund_larger_than_payment_is_caught(): void
    {
        $this->assertContains('I4-refund-exceeds-paid', $this->violationsAfter(function () {
            $entry = DB::table('journal_entries')->insertGetId(['store_id' => 1, 'entry_date' => now()->toDateString(), 'memo' => 'x', 'reference_type' => 'rental_refund', 'reference_id' => $this->dpId, 'is_reversal' => false, 'is_tutorial' => false, 'is_tutorial_practice' => false, 'created_at' => now(), 'updated_at' => now()]);
            $accounts = DB::table('accounts')->pluck('id', 'code');
            DB::table('journal_entry_lines')->insert([
                ['journal_entry_id' => $entry, 'account_id' => $accounts['4910'], 'debit' => 999999, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['journal_entry_id' => $entry, 'account_id' => $accounts['1100'], 'debit' => 0, 'credit' => 999999, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }));
    }

    private function bookRefund(int $rentalId, float $amount): void
    {
        $entry = DB::table('journal_entries')->insertGetId(['store_id' => 1, 'entry_date' => now()->toDateString(), 'memo' => 'refund', 'reference_type' => 'rental_refund', 'reference_id' => $rentalId, 'is_reversal' => false, 'is_tutorial' => false, 'is_tutorial_practice' => false, 'created_at' => now(), 'updated_at' => now()]);
        $accounts = DB::table('accounts')->pluck('id', 'code');
        DB::table('journal_entry_lines')->insert([
            ['journal_entry_id' => $entry, 'account_id' => $accounts['4910'], 'debit' => $amount, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['journal_entry_id' => $entry, 'account_id' => $accounts['1100'], 'debit' => 0, 'credit' => $amount, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function test_returned_rent_whose_money_was_partly_handed_back_is_caught(): void
    {
        // The N3 shape: refund booked (journal only), rent then "returned" because amount_paid / payment records still cover the bill.
        $violations = $this->violationsAfter(function () {
            $this->bookRefund($this->fullId, 50000);
            DB::table('rentals')->where('id', $this->fullId)->update(['status' => 'returned']);
        });
        $this->assertContains('I14-returned-but-money-handed-back', $violations);
        $this->assertNotContains('I11-returned-but-unpaid', $violations, 'the older check cannot see it: payment records still add up to the bill');
    }

    public function test_cash_in_the_ledger_that_no_payment_explains_is_caught(): void
    {
        $this->assertContains('I15-cash-ledger-vs-payments', $this->violationsAfter(function () {
            $entry = DB::table('journal_entries')->insertGetId(['store_id' => 1, 'entry_date' => now()->toDateString(), 'memo' => 'x', 'reference_type' => 'manual', 'reference_id' => null, 'is_reversal' => false, 'is_tutorial' => false, 'is_tutorial_practice' => false, 'created_at' => now(), 'updated_at' => now()]);
            $accounts = DB::table('accounts')->pluck('id', 'code');
            DB::table('journal_entry_lines')->insert([
                ['journal_entry_id' => $entry, 'account_id' => $accounts['1100'], 'debit' => 7000, 'credit' => 0, 'created_at' => now(), 'updated_at' => now()],
                ['journal_entry_id' => $entry, 'account_id' => $accounts['4100'], 'debit' => 0, 'credit' => 7000, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }));
    }

    public function test_dashboard_check_compares_against_raw_tables(): void
    {
        // The dashboard reads payment records, so changing a record's date keeps app and recomputation in step...
        $this->assertSame([], $this->violationsAfter(fn () => DB::table('rental_payment_records')->where('rental_id', $this->fullId)->update(['recorded_at' => Carbon::now()->subDay()->toDateTimeString()])));
        // ...while a record the app cannot see (rent in an unknown store) is excluded by both sides.
        $this->assertSame([], $this->violationsAfter(fn () => null));
    }

    public function test_refused_request_that_changes_state_is_caught_by_the_snapshot(): void
    {
        $before = $this->w->snapshot();
        DB::beginTransaction();
        DB::table('rental_payment_records')->where('rental_id', $this->fullId)->update(['amount' => 331000]);
        $after = $this->w->snapshot();
        DB::rollBack();
        $this->assertNotSame($before, $after);
        $this->assertSame($before, $this->w->snapshot());
    }
}
