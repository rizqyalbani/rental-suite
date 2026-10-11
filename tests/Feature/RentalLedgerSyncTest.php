<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Models\Store;
use App\Models\Outfit;
use App\Support\RentalLedgerSync;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers the ledger side of "a rental's total changed after it was paid":
 * the live reconcile hook called by the edit flows, the refund of an
 * overpayment (by the way the customer paid), and what the bulk sync repairs
 * or only lists.
 */
class RentalLedgerSyncTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);

        foreach ([
            ['1100', 'Store Cash', 'asset', 'debit', true],
            ['1200', 'Bank', 'asset', 'debit', true],
            ['1300', 'Rental Receivable', 'asset', 'debit', false],
            ['2300', 'Customer Deposits', 'liability', 'credit', false],
            ['4100', 'Rental Revenue', 'revenue', 'credit', false],
            ['4200', 'Late Fee Revenue', 'revenue', 'credit', false],
            ['4400', 'Cancellation Revenue (Forfeited Deposits)', 'revenue', 'credit', false],
            ['4910', 'Rental Refunds', 'revenue', 'debit', false],
        ] as [$code, $name, $type, $normal, $cash]) {
            Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_cash_equivalent' => $cash, 'is_active' => true]);
        }

        $this->store = Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        StoreContext::set($this->store->id);
    }

    protected function tearDown(): void
    {
        // StoreContext is a static that outlives RefreshDatabase's rollback.
        StoreContext::set(null);
        parent::tearDown();
    }

    /** @param list<int> $prices one outfit per price; 3 calendar days = one price block */
    private function makeRental(array $prices, string $status = 'active'): Rental
    {
        $rental = Rental::create([
            'store_id' => $this->store->id,
            'customer_name' => 'Ledger Test',
            'phone' => '081234567890',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => $status,
        ]);

        foreach ($prices as $i => $price) {
            $outfit = Outfit::create(['code' => 'LS-'.$rental->id.'-'.$i, 'price' => $price, 'stock' => 1]);
            $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $price, 'price_excl_tax' => $price, 'tax_amount' => 0]);
        }

        return $rental->fresh('outfits');
    }

    private function pay(Rental $rental, int $amount, string $method, string $kind, int $totalDue): void
    {
        RentalPaymentRecorder::recordIfNonZero($rental, $amount, null, null, $method, $kind, totalDue: (float) $totalDue);
        $rental->update(['amount_paid' => (float) $rental->amount_paid + $amount, 'payment_method' => $method, 'payment_kind' => $kind]);
    }

    /** Change one item's price the way an edit does (pivot row). */
    private function reprice(Rental $rental, int $newPrice, int $index = 0): void
    {
        $outfitId = $rental->outfits()->orderBy('outfits.id')->get()->values()[$index]->id;
        $rental->outfits()->updateExistingPivot($outfitId, ['price_incl_tax' => $newPrice, 'price_excl_tax' => $newPrice]);
    }

    private function bal(string $code): float
    {
        return Account::where('code', $code)->firstOrFail()->balance();
    }

    private function assertLedgerBalanced(): void
    {
        $lines = \App\Models\JournalEntryLine::query();
        $this->assertEqualsWithDelta((float) $lines->sum('debit'), (float) \App\Models\JournalEntryLine::query()->sum('credit'), 0.005);
    }

    // ---- contract follows an edit ------------------------------------

    public function test_raising_the_total_after_a_down_payment_lifts_piutang_and_uang_muka(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        $this->assertSame(200000.0, $this->bal('1300'));
        $this->assertSame(300000.0, $this->bal('2300'));

        $this->reprice($rental, 400000);
        RentalLedgerSync::reconcileRental($rental->fresh());

        $this->assertSame(300000.0, $this->bal('1300'));
        $this->assertSame(400000.0, $this->bal('2300'));
        $this->assertSame(100000.0, $this->bal('1100'));
        $this->assertLedgerBalanced();
    }

    public function test_reconcile_is_idempotent(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        $this->reprice($rental, 350000);

        RentalLedgerSync::reconcileRental($rental->fresh());
        $entries = JournalEntry::count();
        RentalLedgerSync::reconcileRental($rental->fresh());
        RentalLedgerSync::reconcileRental($rental->fresh());

        $this->assertSame($entries, JournalEntry::count());
        $this->assertNull(RentalLedgerSync::evaluate($rental->fresh()));
    }

    public function test_lowering_the_total_of_an_unpaid_remainder_lowers_piutang(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);

        $this->reprice($rental, 250000);
        RentalLedgerSync::reconcileRental($rental->fresh());

        $this->assertSame(150000.0, $this->bal('1300'));
        $this->assertSame(250000.0, $this->bal('2300'));
        $this->assertGreaterThanOrEqual(0.0, $this->bal('1300'));
    }

    public function test_an_unchanged_rental_creates_no_entries(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        $entries = JournalEntry::count();

        RentalLedgerSync::reconcileRental($rental->fresh(), true);

        $this->assertSame($entries, JournalEntry::count());
    }

    // ---- overpayment is refunded the way it was paid ------------------

    public function test_overpayment_after_an_edit_is_refunded_from_kas_when_paid_in_cash(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 300000, 'cash', 'full', 300000);

        $this->reprice($rental, 250000);
        RentalLedgerSync::reconcileRental($rental->fresh(), refundOverpayment: true);

        $this->assertSame(250000.0, $this->bal('1100'));
        $this->assertSame(50000.0, $this->bal('4910'));
        $this->assertSame(300000.0, $this->bal('4100'));
        $this->assertSame(0.0, $this->bal('1300'));
        $this->assertLedgerBalanced();
        $this->assertNull(RentalLedgerSync::evaluate($rental->fresh()));
    }

    public function test_overpayment_paid_by_qris_is_refunded_from_bank(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 300000, 'qris', 'full', 300000);

        $this->reprice($rental, 260000);
        RentalLedgerSync::reconcileRental($rental->fresh(), refundOverpayment: true);

        $this->assertSame(0.0, $this->bal('1100'));
        $this->assertSame(260000.0, $this->bal('1200'));
        $this->assertSame(40000.0, $this->bal('4910'));
    }

    public function test_overpayment_on_a_mixed_payment_is_split_by_share(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        $this->pay($rental, 200000, 'qris', 'final_payment', 300000);

        $this->reprice($rental, 240000);
        RentalLedgerSync::reconcileRental($rental->fresh(), refundOverpayment: true);

        // 60.000 back: a third from Cash (cash was 1/3 of what came in), the rest from Bank.
        $this->assertEqualsWithDelta(80000.0, $this->bal('1100'), 0.01);
        $this->assertEqualsWithDelta(160000.0, $this->bal('1200'), 0.01);
        $this->assertSame(60000.0, $this->bal('4910'));
        $this->assertLedgerBalanced();
    }

    public function test_a_correction_payment_record_does_not_break_the_refund_split(): void
    {
        // Seen on real data (#947): a payment, then a minus record reversing
        // part of it in another method. The refund must still balance.
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        $this->pay($rental, 200000, 'qris', 'final_payment', 300000);
        RentalPaymentRecorder::recordIfNonZero($rental->fresh(), -100000, null, null, 'cash', 'down_payment', totalDue: 300000.0);
        RentalPaymentRecorder::recordIfNonZero($rental->fresh(), 100000, null, null, 'qris', 'final_payment', totalDue: 300000.0);

        $this->reprice($rental, 250000);
        RentalLedgerSync::reconcileRental($rental->fresh(), refundOverpayment: true);

        $this->assertSame(50000.0, $this->bal('4910'));
        // Cash was fully reversed by the correction, so the refund can only come out of Bank.
        $this->assertSame(0.0, $this->bal('1100'));
        $this->assertSame(250000.0, $this->bal('1200'));
        $this->assertLedgerBalanced();
        $this->assertSame(1, JournalEntry::where('reference_type', 'rental_refund')->count());
    }

    public function test_refund_is_posted_once_however_often_reconcile_runs(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 300000, 'cash', 'full', 300000);
        $this->reprice($rental, 250000);

        RentalLedgerSync::reconcileRental($rental->fresh(), true);
        RentalLedgerSync::reconcileRental($rental->fresh(), true);
        RentalLedgerSync::reconcileRental($rental->fresh(), true);

        $this->assertSame(1, JournalEntry::where('reference_type', 'rental_refund')->count());
        $this->assertSame(50000.0, $this->bal('4910'));
    }

    public function test_a_second_edit_refunds_only_the_new_difference(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 300000, 'cash', 'full', 300000);

        $this->reprice($rental, 250000);
        RentalLedgerSync::reconcileRental($rental->fresh(), true);
        $this->reprice($rental, 200000);
        RentalLedgerSync::reconcileRental($rental->fresh(), true);

        $this->assertSame(100000.0, $this->bal('4910'));
        $this->assertSame(200000.0, $this->bal('1100'));
        $this->assertSame(2, JournalEntry::where('reference_type', 'rental_refund')->count());
    }

    public function test_an_edit_that_raises_the_total_again_after_a_refund_stays_consistent(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 300000, 'cash', 'full', 300000);

        $this->reprice($rental, 250000);
        RentalLedgerSync::reconcileRental($rental->fresh(), true);
        $this->reprice($rental, 320000);
        RentalLedgerSync::reconcileRental($rental->fresh(), true);

        // Paid 300k, refunded 50k, total 320k => exactly 70k still owed.
        $this->assertSame(70000.0, $this->bal('1300'));
        $this->assertLedgerBalanced();
    }

    public function test_the_bulk_sync_lists_an_overpayment_but_never_refunds_it(): void
    {
        $rental = $this->makeRental([300000], 'returned');
        $this->pay($rental, 300000, 'cash', 'full', 300000);
        $this->reprice($rental, 250000);

        $result = RentalLedgerSync::run();

        $this->assertSame(0, JournalEntry::where('reference_type', 'rental_refund')->count());
        $this->assertSame(0.0, $this->bal('4910'));
        $this->assertNotEmpty(array_filter($result['review'], fn ($line) => str_contains($line, "#{$rental->id}") && str_contains($line, 'overpaid')));
    }

    // ---- what the bulk sync repairs ----------------------------------

    public function test_the_bulk_sync_fills_a_down_payment_missing_before_a_recorded_final_payment(): void
    {
        $rental = $this->makeRental([350000], 'returned');
        $rental->update(['amount_paid' => 350000, 'down_payment_amount' => 150000, 'payment_kind' => 'final_payment', 'payment_method' => 'cash']);
        // Only the final payment ever reached the ledger.
        $this->pay($rental->fresh(), 200000, 'cash', 'final_payment', 350000);
        $rental->update(['amount_paid' => 350000]);

        $result = RentalLedgerSync::run();

        $this->assertSame(1, $result['created']);
        $this->assertSame(350000.0, RentalPaymentRecord::where('rental_id', $rental->id)->sum('amount') + 0.0);
        $this->assertSame(350000.0, $this->bal('1100'));
        $this->assertSame(350000.0, $this->bal('4100'));
        $this->assertSame(0.0, $this->bal('1300'));
        $this->assertSame(0.0, $this->bal('2300'));
        $this->assertLedgerBalanced();

        // Second run has nothing left to do.
        $second = RentalLedgerSync::run();
        $this->assertSame(0, $second['created']);
        $this->assertSame(0, $second['adjusted']);
    }

    public function test_the_bulk_sync_adjusts_an_active_rental_edited_after_payment_and_stays_idempotent(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        $this->reprice($rental, 380000);

        $first = RentalLedgerSync::run();
        $entries = JournalEntry::count();
        $second = RentalLedgerSync::run();

        $this->assertSame(1, $first['adjusted']);
        $this->assertSame(0, $second['adjusted']);
        $this->assertSame($entries, JournalEntry::count());
        $this->assertSame(280000.0, $this->bal('1300'));
    }

    // ---- cancelled rentals that still carry a balance -----------------

    public function test_a_cancelled_rental_with_an_open_balance_is_listed_not_touched(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        // What a takeover of the last item does: flips the status, no cancel flow.
        Rental::whereKey($rental->id)->update(['status' => 'cancelled']);
        $entries = JournalEntry::count();

        $result = RentalLedgerSync::run();

        $this->assertSame($entries, JournalEntry::count());
        $this->assertNotEmpty(array_filter($result['review'], fn ($line) => str_contains($line, "#{$rental->id}") && str_contains($line, 'cancelled')));
    }

    public function test_a_cancelled_rental_settled_by_the_cancel_flow_is_not_listed(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        Rental::whereKey($rental->id)->update(['status' => 'cancelled']);
        \App\Support\RentalRevenueRecorder::forfeitForCancelledRental($rental->fresh(), null);

        $this->assertSame([], RentalLedgerSync::cancelledWithOpenBalance());
    }

    // ---- takeover: the rental that LOSES an item ---------------------

    public function test_a_rental_that_loses_an_item_to_a_takeover_gets_its_contract_lowered(): void
    {
        $source = $this->makeRental([200000, 150000]);
        $this->pay($source, 100000, 'cash', 'down_payment', 350000);
        $this->assertSame(250000.0, $this->bal('1300'));

        // The takeover detaches the item from the source rental.
        $outfitId = $source->outfits()->orderBy('outfits.id')->first()->id;
        $source->outfits()->detach($outfitId);
        RentalLedgerSync::reconcileRental($source->fresh());

        $this->assertSame(50000.0, $this->bal('1300'));
        $this->assertSame(150000.0, $this->bal('2300'));
        $this->assertLedgerBalanced();
    }

    // ---- guards ----------------------------------------------------

    public function test_reconcile_never_throws_and_skips_rentals_with_no_ledger_history(): void
    {
        $rental = $this->makeRental([300000]);

        RentalLedgerSync::reconcileRental($rental->fresh(), true);

        $this->assertSame(0, JournalEntry::count());
    }

    public function test_reconcile_skips_tutorial_rentals(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        $rental->update(['is_tutorial' => true]);
        $this->reprice($rental, 400000);
        $entries = JournalEntry::count();

        RentalLedgerSync::reconcileRental($rental->fresh(), true);

        $this->assertSame($entries, JournalEntry::count());
    }

    // ---- deleting a rental takes its adjustments and refunds with it ----

    public function test_deleting_a_rental_reverses_its_adjustment_and_refund_entries_too(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        $this->reprice($rental, 400000);
        RentalLedgerSync::reconcileRental($rental->fresh());          // adjustment entry
        $this->assertSame(300000.0, $this->bal('1300'));

        $full = $this->makeRental([300000]);
        $this->pay($full, 300000, 'cash', 'full', 300000);
        $this->reprice($full, 250000);
        RentalLedgerSync::reconcileRental($full->fresh(), true);     // refund entry

        foreach ([$rental, $full] as $r) {
            $r->delete();
            \App\Support\RentalRevenueRecorder::reverseForCancelledRental($r, null);
        }

        foreach (['1100', '1300', '2300', '4100', '4910'] as $code) {
            $this->assertEqualsWithDelta(0.0, $this->bal($code), 0.005, "account $code should be back to zero");
        }
        $this->assertLedgerBalanced();

        // Reversing twice must not reverse the reversals.
        \App\Support\RentalRevenueRecorder::reverseForCancelledRental($rental, null);
        foreach (['1100', '1300', '2300', '4100', '4910'] as $code) {
            $this->assertEqualsWithDelta(0.0, $this->bal($code), 0.005);
        }
    }

    // ---- findings from the independent review ---------------------------

    public function test_cancelling_a_rental_whose_contract_was_adjusted_leaves_nothing_behind(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        $this->reprice($rental, 500000);
        RentalLedgerSync::reconcileRental($rental->fresh());
        $this->assertSame(400000.0, $this->bal('1300'));
        $this->assertSame(500000.0, $this->bal('2300'));

        Rental::whereKey($rental->id)->update(['status' => 'cancelled']);
        \App\Support\RentalRevenueRecorder::forfeitForCancelledRental($rental->fresh(), null);

        // Customer Deposits fully released: the receivable is written off, the 100k cash kept as forfeited deposit.
        $this->assertSame(0.0, $this->bal('1300'));
        $this->assertSame(0.0, $this->bal('2300'));
        $this->assertSame(100000.0, $this->bal('4400'));
        $this->assertSame([], RentalLedgerSync::cancelledWithOpenBalance());
    }

    public function test_a_cancelled_rental_is_never_adjusted_or_refunded(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 300000, 'cash', 'full', 300000);
        Rental::whereKey($rental->id)->update(['status' => 'cancelled']);
        $this->reprice($rental, 200000);
        $entries = JournalEntry::count();

        RentalLedgerSync::reconcileRental($rental->fresh(), true);

        $this->assertSame($entries, JournalEntry::count());
    }

    public function test_a_payment_record_with_no_kind_is_counted_as_paid(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        RentalPaymentRecord::where('rental_id', $rental->id)->update(['payment_kind' => null]);

        // No edit happened: a NULL kind must not read as "nothing paid" and invent drift.
        $this->assertSame(100000.0, RentalLedgerSync::ledgerTotals($rental->fresh())['paid']);
        $this->assertNull(RentalLedgerSync::evaluate($rental->fresh()));
    }

    public function test_a_second_sync_started_while_one_is_running_does_nothing(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        $this->reprice($rental, 380000);
        $entries = JournalEntry::count();

        $lock = \Illuminate\Support\Facades\Cache::lock('rental-ledger-sync', 60);
        $this->assertTrue($lock->get());
        $blocked = RentalLedgerSync::run();
        $lock->release();

        $this->assertSame(1, $blocked['failed']);
        $this->assertSame(0, $blocked['adjusted']);
        $this->assertSame($entries, JournalEntry::count());
        // Once the other run is done, a normal run works.
        $this->assertSame(1, RentalLedgerSync::run()['adjusted']);
    }

    public function test_run_restores_the_store_context_and_clears_the_cached_list(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'cash', 'down_payment', 300000);
        $this->reprice($rental, 380000);
        $rows = RentalLedgerSync::driftRowsForDisplay();
        $this->assertCount(1, $rows);
        $this->assertSame($rental->id, $rows[0]['rental']->id);

        StoreContext::set($this->store->id);
        RentalLedgerSync::run();

        $this->assertSame($this->store->id, StoreContext::storeId());
        $this->assertSame([], RentalLedgerSync::driftRowsForDisplay());
    }

    public function test_resolving_the_refund_account_leaves_the_callers_store_context_alone(): void
    {
        $rental = $this->makeRental([300000]);
        $other = Store::create(['name' => 'Other Store', 'code' => 'other-store', 'is_active' => true]);
        StoreContext::set($other->id);

        $account = \App\Support\RentalRevenueRecorder::resolveCashAccountIdForRental($rental);

        $this->assertSame((int) Account::where('code', '1200')->value('id'), $account);
        $this->assertSame($other->id, StoreContext::storeId());
    }
}
