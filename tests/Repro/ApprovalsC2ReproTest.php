<?php

namespace Tests\Repro;

use App\Http\Controllers\RentalApprovalController;
use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalFreeRequest;
use App\Support\RentalPaymentRecorder;
use App\Support\ShiftClosedTotals;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * Throwaway repros for the C2 overnight review (docs/overnight-2026-10-09/03b-approvals-review.md).
 * Tests named test_BUG_* assert the CURRENT (suspected wrong) behaviour: a passing test = bug confirmed.
 * Tests named test_OK_* are negative controls: they pass when the guard works.
 *
 * "Stale model" = the same controller method called with an approval model that was loaded BEFORE the first
 * decision committed. That is exactly what a second approver (or a double click) holds during the race window
 * between the `status !== 'pending'` check at the top of approve()/reject() and the write.
 *
 * Numbers: no tax, 3-day rent (1 block), outfits at Rp 300.000.
 */
class ApprovalsC2ReproTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    private User $manager;

    private User $supervisor;

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
        $this->admin = User::factory()->create(['role' => 'admin', 'name' => 'Admin Orla']);
        $this->manager = User::factory()->create(['role' => 'management', 'name' => 'Boss Rental Suite']);
        $this->supervisor = User::factory()->create(['role' => 'supervisor', 'name' => 'Sup Vera']);
        foreach ([$this->admin, $this->manager, $this->supervisor] as $u) {
            $u->stores()->attach($this->store->id);
        }
        ShiftSession::create(['opened_by_user_id' => $this->admin->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        ShiftSession::create(['opened_by_user_id' => $this->manager->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        StoreContext::set($this->store->id);
        StoreCache::forever('pricing_tax_percent', 0);
        StoreCache::forever('pricing_prices_include_tax', false);
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

    private function outfit(string $tag = 'S'): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => $tag.'-'.uniqid(), 'price' => 300000, 'stock' => 1]);
    }

    /** @param  list<Outfit>|null  $outfits */
    private function rental(string $kind = 'down_payment', float $paid = 100000, ?array $outfits = null, ?int $shiftId = null): Rental
    {
        $outfits ??= [$this->outfit()];
        $start = now()->addDays(10)->startOfDay();
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Repro', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => $kind, 'amount_paid' => $paid,
        ]);
        foreach ($outfits as $s) {
            $rental->outfits()->attach($s->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 300000, 'tax_amount' => 0]);
        }
        $rental->load('outfits');
        RentalPaymentRecorder::recordIfNonZero($rental, $paid, $shiftId, null, 'cash', $kind, totalDue: 300000.0 * count($outfits));

        return $rental->fresh('outfits');
    }

    private function editRequest(Rental $r, User $by, array $rentalOverride = [], ?int $shiftId = null): RentalApprovalRequest
    {
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $by->id,
            'source_shift_session_id' => $shiftId,
            'payload' => [
                'kind' => 'edit', 'rental_id' => $r->id, 'base_amount_paid' => (float) $r->amount_paid,
                'rental' => array_merge([
                    'customer_name' => $r->customer_name, 'phone' => $r->phone, 'instagram' => null, 'notes' => '',
                    'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
                    'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment',
                    'amount_paid' => (float) $r->amount_paid, 'discount' => null, 'discount_type' => 'fixed',
                    'extra_charge' => null, 'discount_note' => null,
                ], $rentalOverride),
                'outfit_ids' => $r->outfits->pluck('id')->all(),
                'item_discounts' => [], 'item_discount_types' => [], 'item_discount_notes' => [],
            ],
        ]);
        RentalActivityLog::record($r, 'edit_requested', [], note: 'Approval #'.$approval->id, userId: $by->id);

        return $approval;
    }

    /** Calls a controller method directly with a (possibly stale) approval model. */
    private function direct(string $method, User $as, RentalApprovalRequest $approval)
    {
        $req = Request::create('/x', 'POST');
        $req->headers->set('Accept', 'application/json');
        $req->setUserResolver(fn () => $as);

        return app(RentalApprovalController::class)->{$method}($req, $approval);
    }

    private function retur(): float
    {
        $acc = Account::where('code', '4910')->value('id');

        return (float) JournalEntryLine::where('account_id', $acc)->sum('debit');
    }

    // ---------------------------------------------------------------- BUGS

    /**
     * C2-1: kind=create has no lock / in-transaction status re-check (kind=edit has both). A STALE second approve is
     * still stopped here only because the first created rental now occupies the same outfits (hardConflictsOnly);
     * a true simultaneous double click (both pass that check before either inserts) is NOT simulated.
     */
    public function test_OK_create_kind_stale_second_approve_is_stopped_only_by_the_availability_check(): void
    {
        $outfit = $this->outfit();
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $this->admin->id,
            'source_shift_session_id' => null,
            'payload' => [
                'kind' => 'create',
                'rental' => [
                    'customer_name' => 'Dup Customer', 'phone' => '0811', 'start_date' => now()->addDays(10)->toDateString(),
                    'end_date' => now()->addDays(12)->toDateString(), 'status' => 'active',
                    'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 100000,
                ],
                'outfit_ids' => [$outfit->id], 'item_discounts' => [], 'item_discount_types' => [],
            ],
        ]);
        $stale = RentalApprovalRequest::find($approval->id);

        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();
        $this->assertSame(1, Rental::where('customer_name', 'Dup Customer')->count());

        // Second approver / double click that read the row while it was still pending.
        $resp = $this->direct('approve', $this->manager, $stale);

        $this->assertSame(422, $resp->getStatusCode());
        $this->assertStringContainsString('no longer available', (string) $resp->getContent());
        $this->assertSame(1, Rental::where('customer_name', 'Dup Customer')->count());
        $this->assertEqualsWithDelta(100000, (float) RentalPaymentRecord::sum('amount'), 0.01);
    }

    /** C2-1/C2-2 (FIXED): cancel-with-refund is only blocked by a pending EDIT, but a pending item removal can no longer be approved afterwards (it would refund a second time). */
    public function test_FIXED_item_removal_cannot_be_approved_on_a_cancelled_rent_so_no_second_refund(): void
    {
        $a = $this->outfit('A');
        $b = $this->outfit('B');
        $rental = $this->rental('full', 600000, [$a, $b]);
        $removal = RentalApprovalRequest::create([
            'requested_by_user_id' => $this->manager->id, 'source_shift_session_id' => null,
            'payload' => ['kind' => 'item_removal', 'rental_id' => $rental->id, 'removed_outfit_ids' => [$b->id]],
        ]);

        $this->as($this->manager)->post(route('rentals.status', $rental), ['status' => 'cancelled', 'refund' => 1])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $rental->fresh()->status);
        $afterCancel = $this->retur();
        $this->assertEqualsWithDelta(600000, $afterCancel, 0.01, 'cancel refunded everything that was paid');

        $this->as($this->supervisor)->post(route('approvals.item-removal.approve', $removal))->assertSessionHasErrors('approval');

        $this->assertSame('pending', $removal->fresh()->status, 'the request stays open for a reject');
        $this->assertEqualsWithDelta(600000, $this->retur(), 0.01, 'no second refund on a rent already refunded in full');
        $this->assertSame(2, $rental->fresh('outfits')->outfits->count(), 'both items stay on the cancelled rent');
    }

    /** B9 control: an explicit cash/bank account is honoured, a revenue/unknown/inactive one is refused, empty follows the payment. */
    public function test_item_removal_refund_goes_to_the_picked_cash_account_and_refuses_other_accounts(): void
    {
        $bank = Account::where('code', '1200')->firstOrFail();
        $revenue = Account::where('code', '4100')->firstOrFail();
        $cases = [
            ['id' => $revenue->id, 'status' => 422], ['id' => 999999, 'status' => 422], ['id' => $bank->id, 'status' => 200],
        ];
        foreach ($cases as $i => $case) {
            $a = $this->outfit('A'.$i);
            $b = $this->outfit('B'.$i);
            $rental = $this->rental('full', 600000, [$a, $b]);
            $removal = RentalApprovalRequest::create([
                'requested_by_user_id' => $this->manager->id, 'source_shift_session_id' => null,
                'payload' => ['kind' => 'item_removal', 'rental_id' => $rental->id, 'removed_outfit_ids' => [$b->id]],
            ]);
            $this->as($this->supervisor)->postJson(route('approvals.item-removal.approve', $removal), ['refund_payment_account_id' => $case['id']])
                ->assertStatus($case['status']);
            $this->assertSame($case['status'] === 200 ? 'approved' : 'pending', $removal->fresh()->status);
        }
        $this->assertEqualsWithDelta(300000, (float) JournalEntryLine::where('account_id', $bank->id)->sum('credit'), 0.01, 'refund credited to the picked bank account');
    }

    /** C2-3: edit-approval stale check ignores free_approved, so an older edit snapshot silently un-frees a rent whose money was already refunded. */
    public function test_BUG_pending_edit_snapshot_undoes_an_approved_free_rent(): void
    {
        $rental = $this->rental('down_payment', 100000);
        $free = RentalFreeRequest::submit($rental, $this->admin, 'Endorse IG', null);
        // Admin edits while the free request waits (nothing blocks this: only a pending EDIT is checked).
        $edit = $this->editRequest($rental, $this->admin, ['customer_name' => 'Repro Renamed']);

        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $free))->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(0, RentalFreeRequest::rentTotal($rental->fresh()), 0.01, 'free applied');
        $this->assertEqualsWithDelta(100000, $this->retur(), 0.01, 'Rp 100.000 refund booked');

        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $edit))->assertSessionHasNoErrors();

        $r = $rental->fresh('outfits');
        $this->assertSame('approved', $edit->fresh()->status, 'edit was NOT detected as stale');
        $this->assertEqualsWithDelta(300000, RentalFreeRequest::rentTotal($r), 0.01, 'rent is billed again although it was approved as FREE');
        $this->assertNull($r->discount, 'free discount wiped by the old snapshot');
        $this->assertEqualsWithDelta(100000, $this->retur(), 0.01, 'refund already handed to the customer stays booked');
    }

    /** C2-4: approving a create/edit that records money attaches it to the requester's shift even when that shift closed meanwhile; stored totals are not refreshed. */
    public function test_BUG_edit_approval_payment_lands_in_closed_shift_without_refreshing_its_total(): void
    {
        $shift = ShiftSession::where('opened_by_user_id', $this->admin->id)->firstOrFail();
        $rental = $this->rental('down_payment', 100000, null, $shift->id);
        $edit = $this->editRequest($rental, $this->admin, ['amount_paid' => 200000], $shift->id);

        // Shift is closed with the figures known at that moment.
        $totals = ShiftClosedTotals::calculate($shift);
        $shift->update(['closed_at' => now(), 'total_revenue' => $totals['received'], 'total_transactions' => $totals['transactions']]);
        $this->assertEqualsWithDelta(100000, (float) $shift->fresh()->total_revenue, 0.01);

        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $edit))->assertSessionHasNoErrors();

        $live = ShiftClosedTotals::calculate($shift->fresh())['received'];
        $this->assertEqualsWithDelta(200000, $live, 0.01, 'the new Rp 100.000 payment belongs to the closed shift');
        $this->assertEqualsWithDelta(100000, (float) $shift->fresh()->total_revenue, 0.01, 'stored closed-shift total still shows the old figure');
    }

    /** C2-5: reject()/cancel() write the status unconditionally; reject after a concurrent approve flips an applied edit to "rejected". */
    public function test_BUG_reject_with_stale_model_overwrites_an_applied_approval(): void
    {
        $rental = $this->rental('down_payment', 100000);
        $edit = $this->editRequest($rental, $this->admin, ['customer_name' => 'Edited By Approval']);
        $stale = RentalApprovalRequest::find($edit->id);

        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $edit))->assertSessionHasNoErrors();
        $this->assertSame('Edited By Approval', $rental->fresh()->customer_name);

        $this->direct('reject', $this->manager, $stale);

        $this->assertSame('rejected', $edit->fresh()->status, 'status flipped although the edit stays applied');
        $this->assertSame('Edited By Approval', $rental->fresh()->customer_name);
        $this->assertSame(1, RentalActivityLog::where('rental_id', $rental->id)->where('action', 'edit_approved')->count());
        $this->assertSame(1, RentalActivityLog::where('rental_id', $rental->id)->where('action', 'edit_rejected')->count());
    }

    /** C2-6: generic approve() has no requester != decider check for create/edit/delete (only item_removal/free/late_done/wrong-input have one). */
    public function test_BUG_generic_approve_has_no_self_approval_gate(): void
    {
        $outfit = $this->outfit();
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $this->supervisor->id, 'source_shift_session_id' => null,
            'payload' => [
                'kind' => 'create',
                'rental' => ['customer_name' => 'Self', 'phone' => '0811', 'start_date' => now()->addDays(10)->toDateString(),
                    'end_date' => now()->addDays(12)->toDateString(), 'status' => 'active'],
                'outfit_ids' => [$outfit->id], 'item_discounts' => [], 'item_discount_types' => [],
            ],
        ]);

        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();

        $this->assertSame('approved', $approval->fresh()->status, 'the requester approved their own request');
    }

    /** C2-7 (FIXED by WP1): admin's PLAIN delete of a practice rent no longer files a REAL delete request. */
    public function test_FIXED_practice_rent_plain_delete_by_admin_never_touches_the_real_rent(): void
    {
        $real = $this->rental('down_payment', 0);
        \App\Models\RentalPaymentRecord::where('rental_id', $real->id)->delete();
        $practice = \App\Models\TutorialRental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 0,
            'created_by_user_id' => $this->admin->id, 'is_tutorial' => true,
        ]);
        $this->assertSame($real->id, $practice->id, 'ids collide');

        $this->as($this->admin)->deleteJson('/rentals/'.$practice->id.'?tutorial=1')->assertOk();

        $this->assertNull(
            RentalApprovalRequest::where('payload->kind', 'delete')->where('payload->rental_id', $real->id)->first(),
            'no REAL delete request is filed for a practice rent'
        );
        $this->assertNull(Rental::withTrashed()->find($real->id)->deleted_at, 'the real rent with the same id is still there');
    }

    /** C2-8: edit approval can LOWER amount_paid (snapshot lower than base) and posts a negative payment record. */
    public function test_INFO_edit_approval_with_lower_amount_paid_posts_negative_correction(): void
    {
        $rental = $this->rental('down_payment', 100000);
        $edit = $this->editRequest($rental, $this->admin, ['amount_paid' => 40000]);

        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $edit))->assertSessionHasNoErrors();

        $this->assertEqualsWithDelta(40000, (float) $rental->fresh()->amount_paid, 0.01);
        $this->assertEqualsWithDelta(-60000, (float) RentalPaymentRecord::where('rental_id', $rental->id)->orderByDesc('id')->value('amount'), 0.01);
    }

    /** C2-9: an approved late "return" report skips the gates the normal Return button has (fully paid, no pending edit). */
    public function test_INFO_late_done_return_marks_returned_with_unpaid_balance_and_pending_edit(): void
    {
        $rental = $this->rental('down_payment', 100000); // total 300.000, 100.000 paid
        $edit = $this->editRequest($rental, $this->admin, ['customer_name' => 'Pending Rename']);
        $ld = \App\Support\RentalLateDone::submit($rental, $this->admin, [
            'type' => 'return', 'picked_up_at' => now()->subHours(3)->toDateTimeString(), 'picked_up_by' => $this->admin->id,
            'returned_at' => now()->subHour()->toDateTimeString(), 'returned_by' => $this->admin->id, 'note' => '',
        ], null);

        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $ld))->assertSessionHasNoErrors();

        $this->assertSame('returned', $rental->fresh()->status);
        // the waiting edit (which could carry a payment) can no longer be applied
        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $edit))->assertSessionHasErrors('approval');
        $this->assertSame('pending', $edit->fresh()->status);
        // and the normal Return button would have refused this rent:
        $other = $this->rental('down_payment', 100000);
        $other->update(['picked_up_at' => now()]);
        $this->as($this->manager)->post(route('rentals.status', $other), ['status' => 'returned'])->assertSessionHasErrors('status');
    }

    /** C2-10 (check, not bug): wrong-input delete of a paid rent leaves the ledger netted to zero for that rent. */
    public function test_OK_wrong_input_delete_reverses_the_ledger_and_closed_shift_total(): void
    {
        $shift = ShiftSession::where('opened_by_user_id', $this->admin->id)->firstOrFail();
        $rental = $this->rental('down_payment', 100000, null, $shift->id);
        $del = RentalApprovalRequest::create([
            'requested_by_user_id' => $this->supervisor->id, 'source_shift_session_id' => null,
            'payload' => ['kind' => 'delete', 'rental_id' => $rental->id, 'wrong_input' => true, 'reason' => 'typo', 'paid' => 100000],
        ]);
        $this->as($this->manager)->post(route('approvals.rentals.approve', $del))->assertSessionHasNoErrors();

        $this->assertSame('approved', $del->fresh()->status);
        $this->assertNotNull(Rental::withTrashed()->find($rental->id)->deleted_at);
        foreach (['1100', '2300', '1300', '4100'] as $code) {
            $id = Account::where('code', $code)->value('id');
            $net = (float) JournalEntryLine::where('account_id', $id)->sum('debit') - (float) JournalEntryLine::where('account_id', $id)->sum('credit');
            $this->assertEqualsWithDelta(0, $net, 0.01, "account $code nets to zero");
        }
        $log = RentalActivityLog::where('rental_id', $rental->id)->where('action', 'delete_approved')->firstOrFail();
        $this->assertSame($this->manager->id, $log->user_id);
        $this->assertEqualsWithDelta(0, ShiftClosedTotals::calculate($shift)['received'], 0.01, 'deleted rent no longer counted in the shift');
    }

    /** C2-11: "free" zeroes the items via a 100% order discount, but extra_charge is part of net_incl_tax and stays. */
    public function test_BUG_free_rent_with_extra_charge_is_not_rp_0_but_everything_paid_is_refunded(): void
    {
        $rental = $this->rental('down_payment', 100000);
        $rental->update(['extra_charge' => 50000]);
        $this->assertEqualsWithDelta(350000, RentalFreeRequest::rentTotal($rental->fresh()), 0.01);
        $free = RentalFreeRequest::submit($rental->fresh(), $this->admin, 'Endorse IG', null);

        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $free))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Rent is now free (Rp 0)'));

        $this->assertEqualsWithDelta(50000, RentalFreeRequest::rentTotal($rental->fresh('outfits')), 0.01, 'bill is not Rp 0');
        $this->assertEqualsWithDelta(100000, $this->retur(), 0.01, 'all rent money paid was handed back');
    }

    // ------------------------------------------------------ NEGATIVE CONTROLS

    /**
     * C2-12: `{approval}` binds BEFORE the `set.store` route middleware runs (StoreContext is still empty in a fresh
     * request), so the store scope is not applied for a supervisor, and late_done / delete / item_removal / reject then
     * load their rental withoutGlobalScope('store_scope'). A supervisor of store 2 decides a store 1 request.
     * (StoreContext is reset to "empty" before the call to mimic a fresh PHP request.)
     * The FREE kind happens to be refused only because the store-1 items are invisible under store 2 (total reads Rp 0).
     */
    public function test_FIXED_supervisor_of_another_store_cannot_decide_this_stores_requests(): void
    {
        $store2 = Store::create(['name' => 'Other', 'code' => 'other', 'is_active' => true]);
        $sup2 = User::factory()->create(['role' => 'supervisor', 'name' => 'Other Store Sup']);
        $sup2->stores()->attach($store2->id);
        $rental = $this->rental('down_payment', 100000);
        $ld = \App\Support\RentalLateDone::submit($rental, $this->admin, [
            'type' => 'pickup', 'picked_up_at' => now()->subHour()->toDateTimeString(), 'picked_up_by' => $this->admin->id, 'note' => '',
        ], null);
        $edit = $this->editRequest($rental, $this->admin);

        StoreContext::set(null, false); // fresh request: nothing has set the store yet at binding time
        $this->actingAs($sup2)->withSession(['current_store_id' => $store2->id])
            ->post(route('approvals.rentals.approve', $ld))->assertSessionHasErrors('approval');
        StoreContext::set(null, false);
        $this->actingAs($sup2)->withSession(['current_store_id' => $store2->id])
            ->post(route('approvals.rentals.reject', $edit))->assertSessionHasErrors('approval');
        StoreContext::set($this->store->id);

        $this->assertSame('pending', $ld->fresh()->status);
        $this->assertNull($rental->fresh()->picked_up_at, 'store 1 rent is not touched by a store 2 supervisor');
        $this->assertSame('pending', $edit->fresh()->status, 'store 1 edit request stays pending');
        $this->assertNull($edit->fresh()->decided_by_user_id);

        // control: a supervisor of the request's own store still decides it
        StoreContext::set(null, false);
        $this->actingAs($this->supervisor)->withSession(['current_store_id' => $this->store->id])
            ->post(route('approvals.rentals.approve', $ld))->assertSessionHasNoErrors();
        StoreContext::set($this->store->id);
        $this->assertSame('approved', $ld->fresh()->status);
    }

    /** Same hole, kind=create (FIXED): a store 2 supervisor can no longer create the rental in the wrong store. */
    public function test_FIXED_cross_store_create_approval_is_refused_and_creates_nothing(): void
    {
        $store2 = Store::create(['name' => 'Other', 'code' => 'other', 'is_active' => true]);
        $sup2 = User::factory()->create(['role' => 'supervisor']);
        $sup2->stores()->attach($store2->id);
        $outfit = $this->outfit();
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $this->admin->id, 'source_shift_session_id' => null,
            'payload' => [
                'kind' => 'create',
                'rental' => ['customer_name' => 'Store1 Customer', 'phone' => '0811', 'start_date' => now()->addDays(10)->toDateString(),
                    'end_date' => now()->addDays(12)->toDateString(), 'status' => 'active'],
                'outfit_ids' => [$outfit->id], 'item_discounts' => [], 'item_discount_types' => [],
            ],
        ]);
        $this->assertSame($this->store->id, (int) $approval->store_id);

        StoreContext::set(null, false);
        $this->actingAs($sup2)->withSession(['current_store_id' => $store2->id])
            ->post(route('approvals.rentals.approve', $approval));
        StoreContext::set(null, false);

        $this->assertNull(Rental::withoutGlobalScopes()->where('customer_name', 'Store1 Customer')->first(), 'no rental is created');
        $this->assertSame('pending', $approval->fresh()->status);
    }

    /** Same guard on the item-removal route: a store 2 supervisor can neither approve nor reject a store 1 removal. */
    public function test_FIXED_cross_store_item_removal_cannot_be_decided_by_another_stores_supervisor(): void
    {
        $store2 = Store::create(['name' => 'Other', 'code' => 'other', 'is_active' => true]);
        $sup2 = User::factory()->create(['role' => 'supervisor']);
        $sup2->stores()->attach($store2->id);
        $a = $this->outfit('A');
        $b = $this->outfit('B');
        $rental = $this->rental('full', 600000, [$a, $b]);
        $removal = RentalApprovalRequest::create([
            'requested_by_user_id' => $this->manager->id, 'source_shift_session_id' => null,
            'payload' => ['kind' => 'item_removal', 'rental_id' => $rental->id, 'removed_outfit_ids' => [$b->id]],
        ]);

        StoreContext::set(null, false);
        $this->actingAs($sup2)->withSession(['current_store_id' => $store2->id])
            ->post(route('approvals.item-removal.approve', $removal))->assertSessionHasErrors('approval');
        StoreContext::set(null, false);
        $this->actingAs($sup2)->withSession(['current_store_id' => $store2->id])
            ->post(route('approvals.item-removal.reject', $removal))->assertSessionHasErrors('approval');
        StoreContext::set($this->store->id);

        $this->assertSame('pending', $removal->fresh()->status);
        $this->assertSame(2, $rental->fresh('outfits')->outfits->count(), 'the rent keeps both items');
    }

    public function test_OK_after_free_approval_a_later_reconcile_does_not_refund_again(): void
    {
        $rental = $this->rental('down_payment', 100000);
        $free = RentalFreeRequest::submit($rental, $this->admin, 'Endorse', null);
        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $free))->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(100000, $this->retur(), 0.01);

        $out = \App\Support\RentalLedgerSync::reconcileRental($rental->fresh('outfits'), refundOverpayment: true, byUserId: $this->manager->id);

        $this->assertNull($out);
        $this->assertEqualsWithDelta(100000, $this->retur(), 0.01, 'no second refund');
        $row = \App\Support\RentalLedgerSync::evaluate($rental->fresh('outfits'));
        $this->assertTrue($row === null || $row['overpaid'] < 1);
    }

    public function test_OK_admin_cannot_reach_approve_or_reject(): void
    {
        $rental = $this->rental();
        $edit = $this->editRequest($rental, $this->admin);
        $this->as($this->admin)->post(route('approvals.rentals.approve', $edit))->assertForbidden();
        $this->as($this->admin)->post(route('approvals.rentals.reject', $edit))->assertForbidden();
        $this->assertSame('pending', $edit->fresh()->status);
    }

    public function test_OK_requester_cannot_approve_own_item_removal_free_or_wrong_input_delete(): void
    {
        $a = $this->outfit('A');
        $b = $this->outfit('B');
        $rental = $this->rental('full', 600000, [$a, $b]);
        $removal = RentalApprovalRequest::create([
            'requested_by_user_id' => $this->manager->id, 'source_shift_session_id' => null,
            'payload' => ['kind' => 'item_removal', 'rental_id' => $rental->id, 'removed_outfit_ids' => [$b->id]],
        ]);
        $this->as($this->manager)->post(route('approvals.item-removal.approve', $removal))->assertSessionHasErrors('approval');
        // item_removal through the GENERIC route: payload has no 'rental' -> refused, not applied as a "create".
        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $removal))->assertSessionHasErrors('approval');
        $this->assertSame('pending', $removal->fresh()->status);

        $free = RentalFreeRequest::submit($rental, $this->manager, 'Endorse', null);
        $this->as($this->manager)->post(route('approvals.rentals.approve', $free))->assertSessionHasErrors('approval');
        $this->assertSame('pending', $free->fresh()->status);

        $del = RentalApprovalRequest::create([
            'requested_by_user_id' => $this->manager->id, 'source_shift_session_id' => null,
            'payload' => ['kind' => 'delete', 'rental_id' => $rental->id, 'wrong_input' => true, 'reason' => 'typo', 'paid' => 600000],
        ]);
        $this->as($this->manager)->post(route('approvals.rentals.approve', $del))->assertSessionHasErrors('approval');
        // A supervisor may not decide a wrong-input delete either (management only).
        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $del))->assertSessionHasErrors('approval');
        $this->assertSame('pending', $del->fresh()->status);
        $this->assertNull(Rental::find($rental->id)?->deleted_at);
    }

    public function test_OK_edit_kind_stale_second_approve_is_refused(): void
    {
        $rental = $this->rental('down_payment', 100000);
        $edit = $this->editRequest($rental, $this->admin, ['amount_paid' => 200000]);
        $stale = RentalApprovalRequest::find($edit->id);
        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $edit))->assertSessionHasNoErrors();
        $records = RentalPaymentRecord::count();

        $resp = $this->direct('approve', $this->manager, $stale);
        $this->assertSame(422, $resp->getStatusCode(), 'stopped by the stale-snapshot check (its own edit_approved log entry)');
        $this->assertSame($records, RentalPaymentRecord::count(), 'no second payment');
    }

    public function test_OK_free_kind_stale_second_approve_does_not_refund_twice(): void
    {
        $rental = $this->rental('down_payment', 100000);
        $free = RentalFreeRequest::submit($rental, $this->admin, 'Endorse', null);
        $stale = RentalApprovalRequest::find($free->id);
        $this->as($this->supervisor)->post(route('approvals.rentals.approve', $free))->assertSessionHasNoErrors();
        $this->direct('approve', $this->manager, $stale);
        $this->assertEqualsWithDelta(100000, $this->retur(), 0.01);
    }

    public function test_OK_item_removal_stale_second_approve_does_not_refund_twice_but_double_logs(): void
    {
        $a = $this->outfit('A');
        $b = $this->outfit('B');
        $rental = $this->rental('full', 600000, [$a, $b]);
        $removal = RentalApprovalRequest::create([
            'requested_by_user_id' => $this->manager->id, 'source_shift_session_id' => null,
            'payload' => ['kind' => 'item_removal', 'rental_id' => $rental->id, 'removed_outfit_ids' => [$b->id]],
        ]);
        $stale = RentalApprovalRequest::find($removal->id);
        $this->as($this->supervisor)->post(route('approvals.item-removal.approve', $removal))->assertSessionHasNoErrors();
        $this->assertEqualsWithDelta(300000, $this->retur(), 0.01);
        $this->direct('approveItemRemoval', $this->supervisor, $stale);
        $this->assertEqualsWithDelta(300000, $this->retur(), 0.01, 'no second refund');
        $this->assertSame(2, RentalActivityLog::where('rental_id', $rental->id)->where('action', 'item_removal_approved')->count(), 'but History shows the approval twice');
    }
}
