<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Admin's edits normally need supervisor/management approval — but collecting
 * the remaining balance ("Pay" in the edit modal) is operational, same as the
 * existing overdue-fee exemption, so it must apply immediately. The exemption
 * must be narrow: it only fires when payment is the ONLY thing changing, and
 * only upward (a payment, never a downward correction) — any real edit
 * (items, discount, dates) bundled with it still goes to approval.
 */
class RentalDirectPaymentTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    private User $admin;

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
        $this->store = Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        $this->manager = User::factory()->create(['role' => 'management']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->admin->stores()->attach($this->store->id);
        ShiftSession::create(['opened_by_user_id' => $this->admin->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        StoreContext::set($this->store->id);
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        parent::tearDown();
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['current_store_id' => $this->store->id]);
    }

    private function bal(string $code): float
    {
        return Account::where('code', $code)->first()->balance();
    }

    private function rental(int $price = 300000, int $paid = 100000): Rental
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'P-'.uniqid(), 'price' => $price, 'stock' => 1]);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Pay Test', 'phone' => '0812',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => $paid,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $price, 'price_excl_tax' => $price, 'tax_amount' => 0]);
        RentalPaymentRecorder::recordIfNonZero($rental, $paid, null, null, 'cash', 'down_payment', totalDue: (float) $price);

        return $rental->fresh('outfits');
    }

    /**
     * Exactly what the real "Pay" button in the edit modal submits: same items,
     * same discount, same dates — only the payment fields change.
     */
    private function pay(User $u, Rental $r, $amount, string $method = 'cash', array $override = [])
    {
        return $this->as($u)->putJson(route('rentals.update', $r), array_merge([
            'outfit_ids' => [$r->outfits->first()->id], 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => $method, 'payment_kind' => $r->payment_kind === 'down_payment' ? 'final_payment' : 'full',
            // '' (not '0') when there is no discount — normalizeRentalMoneyRequest turns
            // an empty string back into null, round-tripping the saved value exactly, the
            // same way the real edit modal's own empty discount field behaves.
            'amount_paid' => (string) $amount, 'discount' => $r->discount !== null ? (string) (int) $r->discount : '', 'discount_type' => $r->discount_type ?? 'fixed',
        ], $override));
    }

    private function balanced(): void
    {
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.005);
    }

    // ---- the exemption itself ------------------------------------------------

    public function test_admin_paying_the_remaining_balance_applies_immediately_no_approval(): void
    {
        $r = $this->rental();

        $res = $this->pay($this->admin, $r, 300000, 'qris');

        $res->assertOk();
        $this->assertNotSame('edit_approval_request_sent', $res->json('mode'));
        $this->assertSame(0, RentalApprovalRequest::where('status', 'pending')->count());
        $r = $r->fresh();
        $this->assertSame(300000.0, (float) $r->amount_paid);
        $this->assertSame('final_payment', $r->payment_kind);
        $this->assertSame(0.0, $this->bal('1300'));                    // receivable cleared
        $this->assertSame(200000.0, $this->bal('1200'));               // this payment landed in Bank (QRIS)
        $this->assertSame(300000.0, $this->bal('4100'));               // revenue recognized
        $this->balanced();
    }

    public function test_a_part_payment_by_admin_also_applies_immediately(): void
    {
        $r = $this->rental();

        $res = $this->pay($this->admin, $r, 150000, override: ['payment_kind' => 'down_payment']);

        $res->assertOk();
        $this->assertNotSame('edit_approval_request_sent', $res->json('mode'));
        $this->assertSame(150000.0, (float) $r->fresh()->amount_paid);
        $this->assertSame(150000.0, $this->bal('1300'));
    }

    // ---- the boundary: anything beyond a plain payment still needs approval --------

    public function test_a_payment_bundled_with_an_item_change_still_needs_approval(): void
    {
        $r = $this->rental();
        $extra = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'P-extra', 'price' => 50000, 'stock' => 1]);

        $res = $this->pay($this->admin, $r, 300000, override: ['outfit_ids' => [$r->outfits->first()->id, $extra->id], 'payment_kind' => 'down_payment']);

        $res->assertOk()->assertJsonPath('mode', 'edit_approval_request_sent');
        $this->assertSame(1, RentalApprovalRequest::where('status', 'pending')->count());
        $this->assertSame(100000.0, (float) $r->fresh()->amount_paid);   // nothing applied yet
    }

    public function test_a_payment_bundled_with_a_discount_change_still_needs_approval(): void
    {
        $r = $this->rental();

        $res = $this->pay($this->admin, $r, 250000, override: ['discount' => '50000', 'discount_note' => 'ACC test']);

        $res->assertOk()->assertJsonPath('mode', 'edit_approval_request_sent');
        $this->assertSame(100000.0, (float) $r->fresh()->amount_paid);
    }

    public function test_a_payment_bundled_with_a_date_change_still_needs_approval(): void
    {
        $r = $this->rental();

        // Still one 3-day block (same price), so only the date makes it more than a payment.
        $res = $this->pay($this->admin, $r, 300000, override: ['end_date' => now()->addDays(2)->toDateString()]);

        $res->assertOk()->assertJsonPath('mode', 'edit_approval_request_sent');
    }

    public function test_lowering_the_paid_amount_a_correction_still_needs_approval(): void
    {
        $r = $this->rental(300000, 200000);

        $res = $this->pay($this->admin, $r, 150000, override: ['payment_kind' => 'down_payment']);

        $res->assertOk()->assertJsonPath('mode', 'edit_approval_request_sent');
        $this->assertSame(200000.0, (float) $r->fresh()->amount_paid);   // unchanged, waiting on a human
    }

    // ---- management is unaffected (unchanged behaviour) ------------------------------

    public function test_management_still_applies_everything_directly_as_before(): void
    {
        $r = $this->rental();

        $this->pay($this->manager, $r, 300000)->assertOk();

        $this->assertSame(300000.0, (float) $r->fresh()->amount_paid);
    }

    // ---- collision with a request waiting from before ---------------------------------

    public function test_a_discount_approved_after_the_customer_already_paid_in_full_refunds_the_difference(): void
    {
        $r = $this->rental();
        // Paid in full FIRST — no request pending yet, so nothing blocked it.
        $this->pay($this->admin, $r, 300000)->assertOk();

        // A discount is decided afterwards (a retroactive adjustment) — the one
        // overpayment case the pending-edit payment block below cannot prevent,
        // since the payment happened before the request even existed.
        $this->pay($this->admin, $r, 300000, override: ['discount' => '50000', 'discount_note' => 'ACC test', 'payment_kind' => 'full'])
            ->assertJsonPath('mode', 'edit_approval_request_sent');
        $approval = RentalApprovalRequest::where('status', 'pending')->firstOrFail();

        $this->as($this->manager)->postJson(route('approvals.rentals.approve', $approval))->assertOk();

        $r = $r->fresh();
        $this->assertSame(300000.0, (float) $r->amount_paid);
        $this->assertSame(50000.0, $this->bal('4910'));   // 300k paid, now owes 250k -> 50k goes back
        $this->assertSame(0.0, $this->bal('1300'));
        $this->balanced();
    }

    public function test_approving_an_overpaying_edit_tells_the_manager_a_refund_is_owed(): void
    {
        $r = $this->rental();
        $this->pay($this->admin, $r, 300000)->assertOk();
        $this->pay($this->admin, $r, 300000, override: ['discount' => '50000', 'discount_note' => 'ACC test', 'payment_kind' => 'full']);
        $approval = RentalApprovalRequest::where('status', 'pending')->firstOrFail();

        $res = $this->as($this->manager)->postJson(route('approvals.rentals.approve', $approval));

        $res->assertOk();
        $this->assertEquals(50000.0, $res->json('refund.amount'));
        $this->assertSame('1100', $res->json('refund.accounts.0.code'));   // paid by cash, so refunded from Cash
        $this->assertStringContainsString('overpaid by Rp 50.000', $res->json('refund_message'));
    }

    public function test_approving_an_edit_with_no_overpayment_reports_no_refund(): void
    {
        $r = $this->rental();
        $this->pay($this->admin, $r, 300000)->assertOk();
        $this->pay($this->admin, $r, 300000, override: ['discount' => '0', 'discount_note' => '', 'payment_kind' => 'full', 'end_date' => now()->addDays(2)->toDateString()]);
        $approval = RentalApprovalRequest::where('status', 'pending')->firstOrFail();

        $res = $this->as($this->manager)->postJson(route('approvals.rentals.approve', $approval));

        $res->assertOk();
        $this->assertNull($res->json('refund'));
        $this->assertNull($res->json('refund_message'));
    }

    public function test_a_rejected_discount_after_full_payment_needs_no_refund(): void
    {
        $r = $this->rental();
        $this->pay($this->admin, $r, 300000)->assertOk();
        $this->pay($this->admin, $r, 300000, override: ['discount' => '50000', 'discount_note' => 'ACC test', 'payment_kind' => 'full']);
        $approval = RentalApprovalRequest::where('status', 'pending')->firstOrFail();

        $this->as($this->manager)->postJson(route('approvals.rentals.reject', $approval))->assertOk();

        $this->assertSame(300000.0, (float) $r->fresh()->amount_paid);
        $this->assertSame(0.0, $this->bal('4910'));
    }

    public function test_a_waiting_edit_becomes_stale_once_a_REAL_structural_change_happened_since(): void
    {
        $r = $this->rental();
        $discountReq = $this->pay($this->admin, $r, 100000, override: [
            'discount' => '20000', 'discount_note' => 'x', 'payment_kind' => 'down_payment',
        ]);
        $discountReq->assertJsonPath('mode', 'edit_approval_request_sent');
        $approval = RentalApprovalRequest::where('status', 'pending')->firstOrFail();

        // Management directly extends the rental meanwhile — a real structural change.
        $this->as($this->manager)->putJson(route('rentals.update', $r), [
            'outfit_ids' => [$r->outfits->first()->id], 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => now()->addDays(9)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000', 'discount' => '',
        ])->assertOk();

        $res = $this->as($this->manager)->postJson(route('approvals.rentals.approve', $approval));

        $res->assertStatus(422);
        $this->assertStringContainsString('out of date', $res->json('message'));
        $this->as($this->manager)->postJson(route('approvals.rentals.reject', $approval))->assertOk();
    }

    // ---- the whole workflow: unpaid -> pay directly (admin) -> pickup -> return -----------

    public function test_the_whole_workflow_works_without_waiting_for_a_manager(): void
    {
        $r = $this->rental();
        $this->as($this->admin)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->assertStatus(422);

        $this->pay($this->admin, $r, 300000)->assertOk();

        $this->as($this->admin)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->assertOk();
        $this->as($this->admin)->postJson(route('rentals.status', $r), ['status' => 'returned'])->assertOk();
        $this->assertSame('returned', $r->fresh()->status);
        $this->balanced();
    }
    // ---- the client's exact-payment rule -----------------------------------

    public function test_paying_more_than_the_remaining_balance_is_rejected(): void
    {
        $r = $this->rental();

        $res = $this->pay($this->admin, $r, 350000);   // total due is only 300000

        $res->assertStatus(422);
        $this->assertStringContainsString('more than the total due', $res->json('message'));
        $this->assertSame(100000.0, (float) $r->fresh()->amount_paid);   // nothing saved
        $this->assertSame(200000.0, $this->bal('1300'));                 // unchanged from the initial DP
        $this->assertSame(0.0, $this->bal('4910'));   // no refund was ever needed here
    }

    public function test_paying_exactly_the_remaining_balance_still_works(): void
    {
        $r = $this->rental();

        $this->pay($this->admin, $r, 300000)->assertOk();

        $this->assertSame(300000.0, (float) $r->fresh()->amount_paid);
    }

    public function test_the_ceiling_also_applies_to_management(): void
    {
        $r = $this->rental();

        $this->pay($this->manager, $r, 350000)->assertStatus(422);
    }

    public function test_the_ceiling_also_applies_when_creating_a_rental(): void
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'NEW-1', 'price' => 200000, 'stock' => 1]);

        $res = $this->as($this->admin)->postJson(route('rentals.store'), [
            'outfit_ids' => [$outfit->id], 'customer_name' => 'New', 'phone' => '0811',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '250000',
            'discount' => null, 'discount_type' => 'fixed',
        ]);

        $res->assertStatus(422);
        $this->assertSame(0, Rental::where('customer_name', 'New')->count());
    }

    // ---- payment is blocked while an edit request is waiting -----------------------

    public function test_a_new_payment_is_blocked_while_an_edit_request_is_waiting(): void
    {
        $r = $this->rental();
        $this->pay($this->admin, $r, 100000, override: ['discount' => '50000', 'discount_note' => 'x', 'payment_kind' => 'down_payment'])
            ->assertJsonPath('mode', 'edit_approval_request_sent');

        $res = $this->pay($this->admin, $r, 300000);

        $res->assertStatus(422);
        $this->assertStringContainsString('edit request waiting', $res->json('message'));
        $this->assertSame(100000.0, (float) $r->fresh()->amount_paid);
    }

    public function test_the_pending_edit_block_also_applies_to_management(): void
    {
        $r = $this->rental();
        $this->pay($this->admin, $r, 100000, override: ['discount' => '50000', 'discount_note' => 'x', 'payment_kind' => 'down_payment'])
            ->assertJsonPath('mode', 'edit_approval_request_sent');

        $this->pay($this->manager, $r, 300000)->assertStatus(422);
    }

    public function test_payment_works_again_once_the_pending_edit_is_resolved(): void
    {
        $r = $this->rental();
        $this->pay($this->admin, $r, 100000, override: ['discount' => '50000', 'discount_note' => 'x', 'payment_kind' => 'down_payment']);
        $approval = RentalApprovalRequest::where('status', 'pending')->firstOrFail();

        $this->as($this->manager)->postJson(route('approvals.rentals.reject', $approval))->assertOk();

        // Rejected, so the total is unchanged (300000): pay exactly that.
        $this->pay($this->admin, $r, 300000)->assertOk();
        $this->assertSame(300000.0, (float) $r->fresh()->amount_paid);
    }

    public function test_recording_no_new_money_is_never_blocked_by_a_pending_edit(): void
    {
        $r = $this->rental();
        $this->pay($this->admin, $r, 100000, override: ['discount' => '50000', 'discount_note' => 'x', 'payment_kind' => 'down_payment']);

        // A management edit that does not increase the paid amount (e.g. fixing the phone
        // number) must still go through even while the discount request is pending.
        $this->as($this->manager)->putJson(route('rentals.update', $r), [
            'outfit_ids' => [$r->outfits->first()->id], 'customer_name' => $r->customer_name, 'phone' => '089999',
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000', 'discount' => '',
        ])->assertOk();

        $this->assertSame('089999', $r->fresh()->phone);
    }

    // ---- "add an item, submit, and it turned paid" (Alex #103) ------------------------

    /** What the edit form posted on Save while a balance was left: the read-only balance as payment_amount_add. */
    private function saveWithAddedItem(User $u, Rental $r, Outfit $extra, array $override = [])
    {
        return $this->as($u)->putJson(route('rentals.update', $r), array_merge([
            'outfit_ids' => [$r->outfits->first()->id, $extra->id], 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => 'qris', 'payment_kind' => 'final_payment',
            'payment_entry' => 'increment', 'payment_amount_add' => '300000',
            'discount' => '', 'discount_type' => 'fixed',
        ], $override));
    }

    public function test_save_that_adds_an_item_never_records_the_balance_as_paid(): void
    {
        $r = $this->rental(300000, 100000);
        $extra = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'X-'.uniqid(), 'price' => 100000, 'stock' => 1]);

        $this->saveWithAddedItem($this->admin, $r, $extra)->assertOk()->assertJsonPath('mode', 'edit_approval_request_sent');
        $approval = RentalApprovalRequest::where('status', 'pending')->firstOrFail();
        $this->assertEquals(100000, $approval->payload['rental']['amount_paid'], 'request carries no payment');
        $this->assertSame('down_payment', $approval->payload['rental']['payment_kind']);

        $this->as($this->manager)->postJson(route('approvals.rentals.approve', $approval))->assertOk();

        $r = $r->fresh('outfits');
        $this->assertCount(2, $r->outfits, 'the item change itself is applied');
        $this->assertEquals(100000, (float) $r->amount_paid, 'still only the DP');
        $this->assertSame(1, \App\Models\RentalPaymentRecord::where('rental_id', $r->id)->count(), 'no invented settlement');
        $this->balanced();
    }

    public function test_management_save_that_adds_an_item_does_not_settle_either(): void
    {
        $r = $this->rental(300000, 100000);
        $extra = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'X-'.uniqid(), 'price' => 100000, 'stock' => 1]);

        $this->saveWithAddedItem($this->manager, $r, $extra)->assertOk();

        $this->assertEquals(100000, (float) $r->fresh()->amount_paid);
        $this->assertSame(1, \App\Models\RentalPaymentRecord::where('rental_id', $r->id)->count());
        $this->balanced();
    }

    public function test_pay_button_still_records_the_payment(): void
    {
        $r = $this->rental(300000, 100000);

        $this->as($this->admin)->putJson(route('rentals.update', $r), [
            'outfit_ids' => [$r->outfits->first()->id], 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'final_payment',
            'payment_entry' => 'increment', 'payment_amount_add' => '200000', 'pay_action' => '1',
            'discount' => '', 'discount_type' => 'fixed',
        ])->assertOk();

        $this->assertEquals(300000, (float) $r->fresh()->amount_paid);
        $this->balanced();
    }
}
