<?php

namespace Tests\Repro;

use App\Models\Account;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\AccountingTrialMode;
use App\Support\ExpenseRecorder;
use App\Support\JournalPostingService;
use App\Support\RentalLedgerSync;
use App\Support\RentalPaymentRecorder;
use App\Support\RentalRevenueRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Throwaway repros for the C3 overnight review (docs/overnight-2026-10-09/03c-ledger-review.md).
 * Each test asserts the CURRENT (suspected wrong) behaviour, so a passing test = bug confirmed.
 * Run: php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Repro/LedgerC3ReproTest.php
 * Rentals use price_incl_tax == price_excl_tax and tax 0 so the numbers are easy to read.
 */
class LedgerC3ReproTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
        foreach ([
            ['1100', 'Store Cash', 'asset', 'debit', true], ['1200', 'Bank', 'asset', 'debit', true],
            ['1300', 'Rental Receivable', 'asset', 'debit', false], ['2300', 'Customer Deposits', 'liability', 'credit', false],
            ['4100', 'Rental Revenue', 'revenue', 'credit', false], ['4200', 'Late Fee Revenue', 'revenue', 'credit', false],
            ['4400', 'Forfeited Deposits', 'revenue', 'credit', false], ['4910', 'Rental Refunds', 'revenue', 'debit', false],
            ['5100', 'General Expense', 'expense', 'debit', false],
        ] as [$code, $name, $type, $normal, $cash]) {
            Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_cash_equivalent' => $cash, 'is_active' => true]);
        }
        $this->store = Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        StoreContext::set($this->store->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        StoreContext::set(null);
        parent::tearDown();
    }

    private function bal(string $code, ?string $asOf = null, ?string $from = null): float
    {
        return Account::where('code', $code)->firstOrFail()->balance(null, $asOf, $from);
    }

    private function acc(string $code): int
    {
        return (int) Account::where('code', $code)->value('id');
    }

    private function makeRental(array $prices, string $kind = 'down_payment'): Rental
    {
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Ledger Repro', 'phone' => '0812',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => $kind, 'amount_paid' => 0,
        ]);
        foreach ($prices as $i => $price) {
            $outfit = Outfit::create(['code' => 'C3-'.$rental->id.'-'.$i, 'price' => $price, 'stock' => 1]);
            $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $price, 'price_excl_tax' => $price, 'tax_amount' => 0]);
        }

        return $rental->fresh('outfits');
    }

    private function pay(Rental $rental, int $amount, string $kind, int $totalDue, string $method = 'cash'): void
    {
        RentalPaymentRecorder::recordIfNonZero($rental, $amount, null, null, $method, $kind, totalDue: (float) $totalDue);
        $rental->update(['amount_paid' => (float) $rental->amount_paid + $amount, 'payment_method' => $method, 'payment_kind' => $kind]);
    }

    private function reprice(Rental $rental, int $newPrice): void
    {
        $outfitId = $rental->outfits()->orderBy('outfits.id')->get()->values()[0]->id;
        $rental->outfits()->updateExistingPivot($outfitId, ['price_incl_tax' => $newPrice, 'price_excl_tax' => $newPrice]);
    }

    // ---- L1: a DP that covers the whole bill never turns into revenue ----

    public function test_L1a_down_payment_equal_to_total_leaves_everything_in_uang_muka_forever(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 300000, 'down_payment', 300000);

        $this->assertSame(300000.0, $this->bal('1100'));   // cash really received
        $this->assertSame(300000.0, $this->bal('2300'));   // still a liability
        $this->assertSame(0.0, $this->bal('4100'));        // no revenue, ever
        $this->assertNull(RentalLedgerSync::evaluate($rental->fresh())); // the sync sees nothing wrong
    }

    public function test_L1b_edit_that_lowers_the_bill_below_the_dp_never_recognises_revenue(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 200000, 'down_payment', 300000);
        $this->reprice($rental, 150000);
        RentalLedgerSync::reconcileRental($rental->fresh(), refundOverpayment: true);

        // Customer net paid 150.000 = bill 150.000, rental is fully settled ...
        $this->assertSame(150000.0, $this->bal('1100'));
        $this->assertSame(50000.0, $this->bal('4910'));
        $this->assertSame(0.0, $this->bal('1300'));
        // ... yet nothing is revenue and the liability is bigger than the bill.
        $this->assertSame(0.0, $this->bal('4100'));
        $this->assertSame(200000.0, $this->bal('2300'));
        $this->assertNull(RentalLedgerSync::evaluate($rental->fresh()));
    }

    // ---- L2: cancel WITH refund after an earlier refund hands back the refunded part again ----

    public function test_L2_cancel_with_refund_after_an_overpay_refund_returns_more_than_is_held(): void
    {
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 200000, 'down_payment', 300000);
        $this->reprice($rental, 150000);
        RentalLedgerSync::reconcileRental($rental->fresh(), refundOverpayment: true); // 50.000 already handed back

        $rental->update(['status' => 'cancelled']);
        RentalRevenueRecorder::refundForCancelledRental($rental->fresh(), null);

        // 200.000 in, 50.000 out (edit refund), 200.000 out (cancel refund) = -50.000 in the till.
        $this->assertSame(-50000.0, $this->bal('1100'));
        $this->assertSame(0.0, $this->bal('2300'));
    }

    // ---- L4: correction of an old expense double counts the OLD period ----

    public function test_L4_expense_correction_made_next_month_double_counts_the_old_month(): void
    {
        Carbon::setTestNow('2026-09-05 10:00:00');
        $expense = ExpenseRecorder::record($this->store->id, '2026-09-05', $this->acc('5100'), $this->acc('1100'), 100000, 'Electricity', null);

        Carbon::setTestNow('2026-10-09 10:00:00');
        // Only the description changes; amount and date stay the same.
        ExpenseRecorder::update($expense->fresh(), '2026-09-05', $this->acc('5100'), $this->acc('1100'), 100000, 'Electricity (fix typo)', null);

        $this->assertSame(200000.0, $this->bal('5100', '2026-09-30', '2026-09-01'));  // September shows 2x
        $this->assertSame(-100000.0, $this->bal('5100', '2026-10-31', '2026-10-01')); // October shows a negative expense
        $this->assertSame(100000.0, $this->bal('5100'));                              // all-time total is right
    }

    // ---- L7: reverse() has no "already reversed" guard; two overlapping edits reverse the same entry twice ----

    public function test_L7_two_overlapping_expense_edits_leave_the_ledger_off_from_the_expense_row(): void
    {
        $expense = ExpenseRecorder::record($this->store->id, now()->toDateString(), $this->acc('5100'), $this->acc('1100'), 100000, 'Electricity', null);

        // Simulates two overlapping requests (double click on Save): both load the expense + its current entry first.
        $a = Expense::find($expense->id);
        $a->load('journalEntry');
        $b = Expense::find($expense->id);
        $b->load('journalEntry');

        ExpenseRecorder::update($a, now()->toDateString(), $this->acc('5100'), $this->acc('1100'), 80000, 'edit A', null);
        ExpenseRecorder::update($b, now()->toDateString(), $this->acc('5100'), $this->acc('1100'), 150000, 'edit B', null);

        $this->assertSame(2, JournalEntry::withoutGlobalScopes()->where('reverses_journal_entry_id', $expense->journal_entry_id)->count());
        $this->assertSame(150000.0, (float) $expense->fresh()->amount);   // what the Expenses page shows
        $this->assertSame(130000.0, $this->bal('5100'));                   // what the books say (off by 20.000)
    }

    // ---- helpers for the HTTP based repros ----

    private function userFor(string $role): User
    {
        $u = User::factory()->create(['role' => $role]);
        $u->stores()->attach($this->store->id);

        return $u;
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['current_store_id' => $this->store->id]);
    }

    // ---- L3: FREE (or any refund) on a rental that is still only a down payment ----

    public function test_L3_free_on_a_dp_only_rental_leaves_the_refunded_dp_as_a_liability_and_as_a_loss(): void
    {
        $admin = $this->userFor('admin');
        $supervisor = $this->userFor('supervisor');
        ShiftSession::create(['opened_by_user_id' => $admin->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'down_payment', 300000);
        $this->assertSame(300000.0, $this->bal('2300'));
        $this->assertSame(200000.0, $this->bal('1300'));

        $this->as($admin)->postJson(route('rentals.free-request', $rental), ['reason' => 'Endorse IG'])->assertOk();
        $approval = RentalApprovalRequest::where('payload->kind', 'free')->firstOrFail();
        $this->as($supervisor)->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();

        // Customer got the 100.000 back, rental is Rp 0, nothing is owed either way ...
        $this->assertSame(0.0, $this->bal('1100'));
        $this->assertSame(0.0, $this->bal('1300'));
        // ... but the books still show a 100.000 liability and a 100.000 "Rental Refunds" loss against 0 revenue.
        $this->assertSame(100000.0, $this->bal('2300'));
        $this->assertSame(100000.0, $this->bal('4910'));
        $this->assertSame(0.0, $this->bal('4100'));
        $this->assertNull(RentalLedgerSync::evaluate($rental->fresh()));   // sync considers it "balanced"
    }

    // ---- L5: Trial Mode ON tags REAL rental journals as trial ----

    public function test_L5a_rental_journals_posted_in_trial_mode_vanish_at_go_live_and_split_rentals_go_negative(): void
    {
        AccountingTrialMode::setEnabled(true);
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'down_payment', 300000);
        $this->assertTrue((bool) JournalEntry::withoutGlobalScopes()->where('reference_type', 'rental_payment')->value('is_tutorial'));
        $this->assertSame(100000.0, $this->bal('1100'));

        AccountingTrialMode::setEnabled(false);   // "go live"
        $this->assertSame(0.0, $this->bal('1100'));  // the DP cash has disappeared from the books
        $this->assertSame(0.0, $this->bal('2300'));
        $this->assertSame(0, RentalLedgerSync::pendingQuery()->count()); // and the sync does not see a gap (raw table check)

        $this->pay($rental, 200000, 'final_payment', 300000);   // customer pays the rest after go-live
        $this->assertSame(200000.0, $this->bal('1100'));   // should be 300.000 in total
        $this->assertSame(-300000.0, $this->bal('2300'));  // liability with a DEBIT balance
        $this->assertSame(-200000.0, $this->bal('1300'));  // receivable with a CREDIT balance
        $this->assertSame(300000.0, $this->bal('4100'));
    }

    public function test_L5b_clear_trial_data_then_sync_cannot_rebuild_a_refund(): void
    {
        AccountingTrialMode::setEnabled(true);
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 200000, 'down_payment', 300000);
        $this->reprice($rental, 150000);
        RentalLedgerSync::reconcileRental($rental->fresh(), refundOverpayment: true);   // 50.000 handed back to the customer
        $this->assertSame(150000.0, $this->bal('1100'));
        $this->assertSame(50000.0, $this->bal('4910'));

        AccountingTrialMode::setEnabled(false);
        $this->as($this->userFor('super_admin'))->post(route('settings.accounting-trial-data.clear'))->assertRedirect();
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());   // refund entry deleted with everything else
        $this->assertNull(RentalPaymentRecord::first()->journal_entry_id);

        $result = RentalLedgerSync::run();
        $this->assertSame(1, $result['fixed']);    // the DP is re-posted from the payment record ...
        $this->assertSame(0.0, $this->bal('4910')); // ... the 50.000 refund is not
        $this->assertSame(200000.0, $this->bal('1100')); // cash overstated by the refunded 50.000
        $this->assertSame(0, $result['adjusted']);
        $this->assertCount(1, $result['review']);   // only a "check manually" line
    }

    // ---- L8: server side does not check account types on Expense ----

    public function test_L8_expense_form_accepts_a_revenue_account_as_the_payment_side(): void
    {
        $manager = $this->userFor('management');
        $this->as($manager)->post(route('expenses.store'), [
            'expense_date' => now()->toDateString(),
            'account_id' => $this->acc('1100'),          // "category" = the cash account
            'payment_account_id' => $this->acc('4100'),  // "paid from" = the revenue account
            'amount' => '500000',
        ])->assertSessionHasNoErrors();

        $this->assertSame(500000.0, $this->bal('4100'));   // 500.000 of revenue created from the Expense screen
        $this->assertSame(500000.0, $this->bal('1100'));
        $this->assertSame(1, Expense::count());
    }

    public function test_L8b_expense_form_accepts_the_same_account_on_both_sides(): void
    {
        $manager = $this->userFor('management');
        $this->as($manager)->post(route('expenses.store'), [
            'expense_date' => now()->toDateString(),
            'account_id' => $this->acc('1100'), 'payment_account_id' => $this->acc('1100'), 'amount' => '250000',
        ])->assertSessionHasNoErrors();

        $this->assertSame(1, Expense::count());            // appears in the Expenses list / totals
        $this->assertSame(0.0, $this->bal('1100'));         // no effect at all in the books
    }

    // ---- L9: re-parenting or deactivating a posting account silently stops rental journals ----

    public function test_L9_adding_a_child_under_kas_toko_silently_stops_journaling_payments(): void
    {
        $manager = $this->userFor('management');
        $this->as($manager)->post(route('accounts.store'), [
            'code' => '1101', 'name' => 'Petty Cash', 'type' => 'asset', 'normal_balance' => 'debit',
            'parent_id' => $this->acc('1100'), 'is_cash_equivalent' => '1', 'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'down_payment', 300000);   // cashier sees nothing wrong

        $this->assertSame(1, RentalPaymentRecord::count());
        $this->assertNull(RentalPaymentRecord::first()->journal_entry_id);  // not in the books
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());
        $this->assertSame(1, RentalLedgerSync::pendingQuery()->count());   // only trace: the Settings "sync" counter
    }

    // ---- L10: cancel-with-refund is not wrapped; a ledger problem leaves a cancelled rent with no refund entry ----

    public function test_L10_cancel_with_refund_fails_with_500_after_the_status_is_already_saved(): void
    {
        $manager = $this->userFor('management');
        ShiftSession::create(['opened_by_user_id' => $manager->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        $rental = $this->makeRental([300000], 'full');
        $this->pay($rental, 300000, 'full', 300000);
        Account::where('code', '4910')->update(['is_active' => false]);   // e.g. someone deactivated "Rental Refunds"

        $response = $this->as($manager)->postJson(route('rentals.status', $rental), ['status' => 'cancelled', 'refund' => 1]);

        $response->assertStatus(500);                                          // user sees an error ...
        $this->assertSame('cancelled', $rental->fresh()->status);               // ... but the rent IS cancelled
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('reference_type', 'rental_cancellation')->count()); // refund never booked
        $this->assertSame(300000.0, $this->bal('1100'));                        // books still say the cash is here
    }

    // ---- L11: Receivables report only follows payment entries, not edits / write-offs ----

    public function test_L11_piutang_report_still_lists_a_cancelled_rental_whose_receivable_was_written_off(): void
    {
        $manager = $this->userFor('management');
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'down_payment', 300000);
        $this->assertSame(200000.0, $this->bal('1300'));

        $rental->update(['status' => 'cancelled']);
        RentalRevenueRecorder::forfeitForCancelledRental($rental->fresh(), null);   // default cancel: deposit kept, Receivable written off
        $this->assertSame(0.0, $this->bal('1300'));                                  // the account says nobody owes anything

        $html = $this->as($manager)->get(route('accounting.reports.piutang'))->assertOk()->getContent();
        $this->assertStringContainsString('Ledger Repro', $html);                    // the report still lists the customer
        $this->assertStringContainsString('200.000', $html);                          // with the old 200.000
    }

    public function test_L11b_piutang_report_misses_the_edit_adjustment(): void
    {
        $manager = $this->userFor('management');
        $rental = $this->makeRental([300000]);
        $this->pay($rental, 100000, 'down_payment', 300000);
        $this->reprice($rental, 400000);
        RentalLedgerSync::reconcileRental($rental->fresh());
        $this->assertSame(300000.0, $this->bal('1300'));                              // account: 300.000 owed

        $html = $this->as($manager)->get(route('accounting.reports.piutang'))->assertOk()->getContent();
        $this->assertStringContainsString('200.000', $html);                          // report: still 200.000
        $this->assertStringNotContainsString('300.000', $html);
    }

    // ---- Controls (expected to PASS = behaviour is fine) ----

    public function test_C1_service_rejects_unbalanced_negative_both_sided_inactive_and_parent_lines(): void
    {
        $cash = $this->acc('1100');
        $rev = $this->acc('4100');
        $bad = [
            'unbalanced' => [['account_id' => $cash, 'debit' => 100], ['account_id' => $rev, 'credit' => 99.98]],
            'negative debit' => [['account_id' => $cash, 'debit' => -100], ['account_id' => $rev, 'credit' => -100]],
            'both sides on one line' => [['account_id' => $cash, 'debit' => 100, 'credit' => 100], ['account_id' => $rev, 'credit' => 0]],
            'zero amounts' => [['account_id' => $cash, 'debit' => 0], ['account_id' => $rev, 'credit' => 0]],
            'one line' => [['account_id' => $cash, 'debit' => 100]],
            'split that loses a cent' => [['account_id' => $cash, 'debit' => 100], ['account_id' => $rev, 'credit' => 33.33], ['account_id' => $rev, 'credit' => 33.33], ['account_id' => $rev, 'credit' => 33.33]],
        ];
        foreach ($bad as $label => $lines) {
            try {
                JournalPostingService::post($this->store->id, '2026-10-01', 'x', $lines, null);
                $this->fail("accepted: $label");
            } catch (\App\Support\JournalEntryValidationException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->count());   // and nothing half written

        // 0.1 + 0.2 style float noise is fine.
        JournalPostingService::post($this->store->id, '2026-10-01', 'x', [
            ['account_id' => $cash, 'debit' => 0.1], ['account_id' => $cash, 'debit' => 0.2], ['account_id' => $rev, 'credit' => 0.3],
        ], null);
        $this->assertSame(1, JournalEntry::withoutGlobalScopes()->count());

        // Posting to a parent account and to an inactive account is refused.
        $child = Account::create(['code' => '1101', 'name' => 'Petty Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'parent_id' => $cash, 'is_active' => true]);
        $this->expectException(\App\Support\JournalEntryValidationException::class);
        JournalPostingService::postTwoLine($this->store->id, '2026-10-01', 'x', $cash, $rev, 10, null);
    }

    public function test_C2_every_posted_entry_in_a_messy_history_balances_and_sync_is_idempotent(): void
    {
        // R1 edited after DP; R2 journal link lost; R3 payment record never created; R4 cancelled (DP kept); R5 deleted.
        $r1 = $this->makeRental([300000]);
        $this->pay($r1, 100000, 'down_payment', 300000);
        $this->reprice($r1, 350000);

        $r2 = $this->makeRental([200000]);
        $this->pay($r2, 50000, 'down_payment', 200000);
        JournalEntry::withoutGlobalScopes()->where('reference_type', 'rental_payment')->where('reference_id', RentalPaymentRecord::where('rental_id', $r2->id)->value('id'))->delete();

        $r3 = $this->makeRental([200000]);
        $r3->update(['amount_paid' => 100000, 'payment_kind' => 'down_payment', 'payment_method' => 'cash']);

        $r4 = $this->makeRental([300000]);
        $this->pay($r4, 100000, 'down_payment', 300000);
        $r4->update(['status' => 'cancelled']);
        RentalRevenueRecorder::forfeitForCancelledRental($r4->fresh(), null);

        $r5 = $this->makeRental([300000]);
        $this->pay($r5, 100000, 'down_payment', 300000);
        $r5->delete();
        RentalRevenueRecorder::reverseForCancelledRental($r5, null);

        $first = RentalLedgerSync::run();
        $entriesAfterFirst = JournalEntry::withoutGlobalScopes()->count();
        $second = RentalLedgerSync::run();
        $third = RentalLedgerSync::run();

        $this->assertSame(1, $first['created']);
        $this->assertSame(2, $first['fixed']);
        $this->assertSame(1, $first['adjusted']);
        $this->assertSame([0, 0, 0, 0], [$second['created'], $second['fixed'], $second['adjusted'], $second['failed']]);
        $this->assertSame([0, 0, 0, 0], [$third['created'], $third['fixed'], $third['adjusted'], $third['failed']]);
        $this->assertSame($entriesAfterFirst, JournalEntry::withoutGlobalScopes()->count());
        $this->assertEqualsWithDelta(
            (float) JournalEntryLine::query()->sum('debit'),
            (float) JournalEntryLine::query()->sum('credit'),
            0.005
        );
        $this->assertSame([], $first['review']);
    }

    // ---- L12: payments pick the cash account from the ACTING user's store, refunds from the rental's store ----

    public function test_L12_payment_for_store_a_rental_posted_from_store_b_context_lands_in_store_bs_cash_account(): void
    {
        $storeB = Store::create(['name' => 'Store B', 'code' => 'store-b', 'is_active' => true]);
        $kasA = $this->acc('1100');
        $kasB = Account::create(['code' => '1105', 'name' => 'Cash Demo Store Two', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => true, 'is_active' => true]);
        \App\Support\StoreCache::forever('rental_revenue_cash_account_id', $kasA, false);   // store A (current context)
        StoreContext::set($storeB->id);
        \App\Support\StoreCache::forever('rental_revenue_cash_account_id', $kasB->id, false);  // store B
        StoreContext::set($this->store->id);

        $rental = $this->makeRental([300000]);   // a store A rental

        // A management user whose active store is B (or "all stores") approves / records the payment.
        StoreContext::set($storeB->id);
        RentalPaymentRecorder::recordIfNonZero($rental, 100000, null, null, 'cash', 'down_payment', totalDue: 300000.0);
        StoreContext::set($this->store->id);

        $entry = JournalEntry::withoutGlobalScopes()->where('reference_type', 'rental_payment')->firstOrFail();
        $this->assertSame($this->store->id, (int) $entry->store_id);                       // entry belongs to store A ...
        $this->assertSame(100000.0, (float) $entry->lines()->where('account_id', $kasB->id)->sum('debit'));  // ... but cash went to B's account
        $this->assertSame(0.0, (float) $entry->lines()->where('account_id', $kasA)->sum('debit'));
        // The refund helper does it the other way round (rental's own store):
        StoreContext::set($storeB->id);
        $this->assertSame($kasA, RentalRevenueRecorder::resolveCashAccountIdForRental($rental));
    }
}
