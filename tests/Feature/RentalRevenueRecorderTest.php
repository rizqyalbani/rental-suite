<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\AccountingTrialMode;
use App\Support\RentalPaymentRecorder;
use App\Support\RentalRevenueRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalRevenueRecorderTest extends TestCase
{
    use RefreshDatabase;

    /**
     * StoreContext is a plain static (not cache/DB-backed), so it survives
     * RefreshDatabase's rollback — a prior test file's real HTTP request
     * could leave it pointing at a store id that no longer exists once that
     * test's transaction rolls back. See AccountingRoleAccessTest for the
     * same fix.
     */
    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
    }

    protected function tearDown(): void
    {
        AccountingTrialMode::setEnabled(false);
        parent::tearDown();
    }

    private function makeStore(): Store
    {
        return Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
    }

    /** Seeds exactly the account codes RentalRevenueRecorder resolves by. */
    private function seedRevenueAccounts(): void
    {
        Account::create(['code' => '1100', 'name' => 'Store Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => true, 'is_active' => true]);
        Account::create(['code' => '1200', 'name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => true, 'is_active' => true]);
        Account::create(['code' => '4100', 'name' => 'Rental Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_cash_equivalent' => false, 'is_active' => true]);
        Account::create(['code' => '4200', 'name' => 'Late Fee Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_cash_equivalent' => false, 'is_active' => true]);
        Account::create(['code' => '2300', 'name' => 'Customer Deposits', 'type' => 'liability', 'normal_balance' => 'credit', 'is_cash_equivalent' => false, 'is_active' => true]);
        Account::create(['code' => '1300', 'name' => 'Rental Receivable', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => false, 'is_active' => true]);
        Account::create(['code' => '4400', 'name' => 'Cancellation Revenue (Forfeited Deposits)', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_cash_equivalent' => false, 'is_active' => true]);
        Account::create(['code' => '4910', 'name' => 'Rental Refunds (Removed Item Refund)', 'type' => 'revenue', 'normal_balance' => 'debit', 'is_cash_equivalent' => false, 'is_active' => true]);
    }

    private function makeRental(int $storeId): Rental
    {
        return Rental::create([
            'store_id' => $storeId,
            'customer_name' => 'Test Customer',
            'phone' => '081234567890',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
        ]);
    }

    public function test_cash_payment_posts_debit_kas_toko_credit_pendapatan_sewa(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);

        RentalPaymentRecorder::recordIfNonZero($rental, 150000, null, null, 'cash', 'full');

        $record = RentalPaymentRecord::where('rental_id', $rental->id)->firstOrFail();
        $this->assertNotNull($record->journal_entry_id);

        $kas = Account::where('code', '1100')->firstOrFail();
        $revenue = Account::where('code', '4100')->firstOrFail();
        $this->assertSame(150000.0, $kas->balance());
        $this->assertSame(150000.0, $revenue->balance());
    }

    public function test_transfer_payment_posts_to_bank_not_kas_toko(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);

        RentalPaymentRecorder::recordIfNonZero($rental, 200000, null, null, 'transfer', 'full');

        $kas = Account::where('code', '1100')->firstOrFail();
        $bank = Account::where('code', '1200')->firstOrFail();
        $this->assertSame(0.0, $kas->balance());
        $this->assertSame(200000.0, $bank->balance());
    }

    public function test_overdue_payment_posts_to_overdue_revenue_account(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);

        RentalPaymentRecorder::recordIfNonZero($rental, 50000, null, null, 'cash', 'overdue');

        $rentRevenue = Account::where('code', '4100')->firstOrFail();
        $overdueRevenue = Account::where('code', '4200')->firstOrFail();
        $this->assertSame(0.0, $rentRevenue->balance());
        $this->assertSame(50000.0, $overdueRevenue->balance());
    }

    public function test_negative_correction_posts_reversed_direction(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);

        RentalPaymentRecorder::recordIfNonZero($rental, 100000, null, null, 'cash', 'full');
        RentalPaymentRecorder::recordIfNonZero($rental, -30000, null, null, 'cash', 'full');

        $kas = Account::where('code', '1100')->firstOrFail();
        $revenue = Account::where('code', '4100')->firstOrFail();
        $this->assertSame(70000.0, $kas->balance());
        $this->assertSame(70000.0, $revenue->balance());

        $records = RentalPaymentRecord::where('rental_id', $rental->id)->get();
        $this->assertCount(2, $records);
        $this->assertTrue($records->every(fn ($r) => $r->journal_entry_id !== null));
    }

    public function test_misconfigured_revenue_account_does_not_block_rental_payment(): void
    {
        // 4100 deliberately not seeded — resolveRevenueAccountId() must fail
        // soft: RentalPaymentRecord still gets created, journal_entry_id
        // stays null, no exception escapes.
        Account::create(['code' => '1100', 'name' => 'Store Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => true, 'is_active' => true]);
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);

        RentalPaymentRecorder::recordIfNonZero($rental, 150000, null, null, 'cash', 'full');

        $record = RentalPaymentRecord::where('rental_id', $rental->id)->firstOrFail();
        $this->assertSame(150000.0, (float) $record->amount);
        $this->assertNull($record->journal_entry_id);
    }

    public function test_split_payment_across_shifts_posts_two_linked_journal_entries(): void
    {
        $this->seedRevenueAccounts();
        $this->makeStore();

        // Management, not admin — a plain admin's update() always routes
        // through the edit-approval workflow unless it's a pure-overdue
        // action (see RentalController::update()'s $isPureOverdueAction
        // gate), so a direct final-payment settlement needs a role that
        // outranks that gate. store() itself has no such restriction.
        $management = User::factory()->create(['role' => 'management']);
        $outfit = Outfit::create(['code' => 'REV-SPLIT-01', 'price' => 100000, 'stock' => 1]);

        $this->actingAs($management)->post(route('shifts.open'), ['period' => ShiftSession::PERIOD_MORNING]);

        $this->actingAs($management)->postJson(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Split Rev Customer',
            'phone' => '081234567890',
            'notes' => 'Branch A',
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => '60000',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ])->assertOk();

        $rental = Rental::latest('id')->firstOrFail();

        $this->actingAs($management)->post(route('shifts.close'));
        $this->actingAs($management)->post(route('shifts.open'), ['period' => ShiftSession::PERIOD_AFTERNOON]);

        $this->actingAs($management)->putJson(route('rentals.update', $rental), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'notes' => $rental->notes,
            'payment_method' => 'cash',
            'payment_kind' => 'final_payment',
            'payment_entry' => 'replace',
            'amount_paid' => '100000',
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
        ])->assertOk();

        $records = RentalPaymentRecord::where('rental_id', $rental->id)->orderBy('id')->get();
        $this->assertCount(2, $records);
        $this->assertSame(60000.0, (float) $records[0]->amount);
        $this->assertSame(40000.0, (float) $records[1]->amount);
        $this->assertNotNull($records[0]->journal_entry_id);
        $this->assertNotNull($records[1]->journal_entry_id);
        $this->assertNotSame($records[0]->journal_entry_id, $records[1]->journal_entry_id);

        $revenue = Account::where('code', '4100')->firstOrFail();
        $this->assertSame(100000.0, $revenue->balance());
    }

    public function test_a_paid_rental_cannot_be_deleted_and_its_ledger_is_untouched(): void
    {
        $this->seedRevenueAccounts();
        $this->makeStore();

        $admin = User::factory()->create();
        $admin->forceFill(['role' => 'admin'])->save();
        $outfit = Outfit::create(['code' => 'REV-CANCEL-01', 'price' => 120000, 'stock' => 1]);

        $this->actingAs($admin)->post(route('shifts.open'), ['period' => ShiftSession::PERIOD_MORNING]);
        $this->actingAs($admin)->postJson(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Cancel Rev Customer',
            'phone' => '081234567890',
            'notes' => 'Branch A',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '120000',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ])->assertOk();
        $rental = Rental::latest('id')->firstOrFail();

        $revenue = Account::where('code', '4100')->firstOrFail();
        $kas = Account::where('code', '1100')->firstOrFail();
        $this->assertSame(120000.0, $revenue->fresh()->balance());
        $this->assertSame(120000.0, $kas->fresh()->balance());

        // Deleting a paid rent is refused (the money would vanish from the books);
        // cancelling it is the way out. The ledger must be left exactly as it was.
        $management = User::factory()->create(['role' => 'management']);
        $res = $this->actingAs($management)->deleteJson(route('rentals.destroy', $rental));

        $res->assertStatus(422);
        $this->assertStringContainsString('cannot be deleted', (string) $res->json('message'));
        $this->assertNotNull(Rental::find($rental->id));
        $this->assertSame(120000.0, $revenue->fresh()->balance());
        $this->assertSame(120000.0, $kas->fresh()->balance());
    }

    public function test_reversing_an_already_reversed_rental_is_a_noop(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);
        RentalPaymentRecorder::recordIfNonZero($rental, 80000, null, null, 'cash', 'full');

        \App\Support\RentalRevenueRecorder::reverseForCancelledRental($rental, null);
        \App\Support\RentalRevenueRecorder::reverseForCancelledRental($rental, null);

        $revenue = Account::where('code', '4100')->firstOrFail();
        // A second reversal must not double-reverse (which would push the
        // balance negative instead of back to zero).
        $this->assertSame(0.0, $revenue->balance());
    }

    public function test_cancelling_a_down_payment_rental_forfeits_cash_kept_and_writes_off_receivable(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);

        // Deposit 50.000 against a 200.000 total — Receivable 150.000, Customer Deposits 200.000.
        RentalPaymentRecorder::recordIfNonZero($rental, 50000, null, null, 'cash', 'down_payment', null, 200000);

        $kas = Account::where('code', '1100')->firstOrFail();
        $piutang = Account::where('code', '1300')->firstOrFail();
        $uangMuka = Account::where('code', '2300')->firstOrFail();
        $forfeited = Account::where('code', '4400')->firstOrFail();
        $this->assertSame(50000.0, $kas->fresh()->balance());
        $this->assertSame(150000.0, $piutang->fresh()->balance());
        $this->assertSame(200000.0, $uangMuka->fresh()->balance());

        \App\Support\RentalRevenueRecorder::forfeitForCancelledRental($rental, null);

        $this->assertSame(50000.0, $kas->fresh()->balance(), 'Cash already collected must not move.');
        $this->assertSame(0.0, $piutang->fresh()->balance(), 'The unpaid remainder is written off, not chased.');
        $this->assertSame(0.0, $uangMuka->fresh()->balance());
        $this->assertSame(50000.0, $forfeited->fresh()->balance(), 'Only the actual cash collected (50k), not the full 200k liability, becomes revenue.');
    }

    public function test_cancelling_a_pure_full_rental_with_no_down_payment_forfeits_nothing(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);
        RentalPaymentRecorder::recordIfNonZero($rental, 120000, null, null, 'cash', 'full');

        \App\Support\RentalRevenueRecorder::forfeitForCancelledRental($rental, null);

        $revenue = Account::where('code', '4100')->firstOrFail();
        $forfeited = Account::where('code', '4400')->firstOrFail();
        $this->assertSame(120000.0, $revenue->fresh()->balance(), 'Already-recognized revenue from a plain full payment is untouched.');
        $this->assertSame(0.0, $forfeited->fresh()->balance());
    }

    public function test_cancelling_an_already_fully_recognized_rental_forfeits_nothing(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);
        RentalPaymentRecorder::recordIfNonZero($rental, 50000, null, null, 'cash', 'down_payment', null, 200000);
        RentalPaymentRecorder::recordIfNonZero($rental, 150000, null, null, 'cash', 'final_payment', null, 200000);

        $revenue = Account::where('code', '4100')->firstOrFail();
        $this->assertSame(200000.0, $revenue->fresh()->balance());

        \App\Support\RentalRevenueRecorder::forfeitForCancelledRental($rental, null);

        $forfeited = Account::where('code', '4400')->firstOrFail();
        $this->assertSame(200000.0, $revenue->fresh()->balance(), 'A rental that already completed and paid in full has nothing left to forfeit.');
        $this->assertSame(0.0, $forfeited->fresh()->balance());
    }

    public function test_forfeiting_a_cancelled_rental_twice_does_not_double_post(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);
        RentalPaymentRecorder::recordIfNonZero($rental, 50000, null, null, 'cash', 'down_payment', null, 200000);

        \App\Support\RentalRevenueRecorder::forfeitForCancelledRental($rental, null);
        \App\Support\RentalRevenueRecorder::forfeitForCancelledRental($rental, null);

        $forfeited = Account::where('code', '4400')->firstOrFail();
        $this->assertSame(50000.0, $forfeited->fresh()->balance());
    }

    public function test_deleting_a_cancelled_and_forfeited_rental_also_reverses_the_forfeiture(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);
        RentalPaymentRecorder::recordIfNonZero($rental, 50000, null, null, 'cash', 'down_payment', null, 200000);
        \App\Support\RentalRevenueRecorder::forfeitForCancelledRental($rental, null);

        \App\Support\RentalRevenueRecorder::reverseForCancelledRental($rental, null);

        $kas = Account::where('code', '1100')->firstOrFail();
        $piutang = Account::where('code', '1300')->firstOrFail();
        $uangMuka = Account::where('code', '2300')->firstOrFail();
        $forfeited = Account::where('code', '4400')->firstOrFail();
        $this->assertSame(0.0, $kas->fresh()->balance());
        $this->assertSame(0.0, $piutang->fresh()->balance());
        $this->assertSame(0.0, $uangMuka->fresh()->balance());
        $this->assertSame(0.0, $forfeited->fresh()->balance(), 'The forfeiture entry itself must also be reversed, or a stray revenue credit is left standing.');
    }

    public function test_trial_mode_tags_rental_revenue_entry(): void
    {
        $this->seedRevenueAccounts();
        AccountingTrialMode::setEnabled(true);

        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);
        // Real rental flow, not a guided tutorial's practice step — counts
        // normally while Trial Mode stays on, same as manually testing an
        // Expense (see AccountingTrialModeTest), since there's no
        // is_tutorial_practice-aware path for rental payments.
        RentalPaymentRecorder::recordIfNonZero($rental, 90000, null, null, 'cash', 'full');

        $revenue = Account::where('code', '4100')->firstOrFail();
        $this->assertSame(90000.0, $revenue->balance());

        AccountingTrialMode::setEnabled(false);
        $this->assertSame(0.0, $revenue->fresh()->balance());
    }

    public function test_refund_for_item_removal_posts_debit_retur_sewa_credit_chosen_account(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);
        $kas = Account::where('code', '1100')->firstOrFail();
        $retur = Account::where('code', '4910')->firstOrFail();

        RentalRevenueRecorder::refundForItemRemoval(
            rental: $rental,
            amount: 150000,
            paymentAccountId: $kas->id,
            approvalId: 999,
            byUserId: null,
        );

        $entry = JournalEntry::withoutGlobalScopes()->where('reference_type', 'item_removal_refund')->where('reference_id', 999)->first();
        $this->assertNotNull($entry, 'Expected a journal entry tagged reference_type=item_removal_refund.');
        $this->assertSame(150000.0, $retur->fresh()->balance(), 'Rental Refunds is debit-normal, so 150.000 debited here reads as a positive balance.');
        $this->assertSame(-150000.0, $kas->fresh()->balance(), 'Store Cash is debit-normal too — a pure credit (money leaving) reads negative with nothing else posted to it.');
    }

    public function test_refund_for_item_removal_is_idempotent_per_approval(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);
        $kas = Account::where('code', '1100')->firstOrFail();

        RentalRevenueRecorder::refundForItemRemoval($rental, 150000, $kas->id, 999, null);
        // Same approval id again — a retried/duplicated call (e.g. a resubmitted
        // form) must never post twice.
        RentalRevenueRecorder::refundForItemRemoval($rental, 150000, $kas->id, 999, null);

        $count = JournalEntry::withoutGlobalScopes()->where('reference_type', 'item_removal_refund')->where('reference_id', 999)->count();
        $this->assertSame(1, $count);
    }

    public function test_refund_for_item_removal_does_nothing_for_a_zero_or_negative_amount(): void
    {
        $this->seedRevenueAccounts();
        $store = $this->makeStore();
        $rental = $this->makeRental($store->id);
        $kas = Account::where('code', '1100')->firstOrFail();

        RentalRevenueRecorder::refundForItemRemoval($rental, 0, $kas->id, 999, null);
        RentalRevenueRecorder::refundForItemRemoval($rental, -50, $kas->id, 998, null);

        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('reference_type', 'item_removal_refund')->count());
    }
}
