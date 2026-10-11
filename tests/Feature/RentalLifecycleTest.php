<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Models\Rental;
use App\Models\RentalActivityLog;
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
 * The rental workflow is: booked -> (paid) -> picked up -> returned, with
 * cancel as the other way out. These tests pin what each status change needs
 * and who may make it, and that cancelling / reopening keeps the ledger right.
 * (Found by probing: the endpoint used to accept ANY move, e.g. returning an
 * unpaid rental — after which the balance could never be collected.)
 */
class RentalLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    private User $operator;

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
        $this->operator = User::factory()->create(['role' => 'admin']);
        $this->operator->stores()->attach($this->store->id);
        ShiftSession::create(['opened_by_user_id' => $this->operator->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
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

    private function rental(int $price, int $paid, string $kind, int $startInDays = -3, bool $pickedUp = true, string $method = 'cash'): Rental
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'L-'.uniqid(), 'price' => $price, 'stock' => 1]);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Lifecycle', 'phone' => '0812',
            'start_date' => now()->addDays($startInDays)->toDateString(), 'end_date' => now()->addDays($startInDays + 2)->toDateString(),
            'status' => 'active', 'payment_method' => $method, 'payment_kind' => $kind, 'amount_paid' => $paid,
            'picked_up_at' => $pickedUp ? now() : null,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $price, 'price_excl_tax' => $price, 'tax_amount' => 0]);
        RentalPaymentRecorder::recordIfNonZero($rental, min($paid, $price), null, null, $method, $kind, totalDue: (float) $price);

        return $rental->fresh('outfits');
    }

    private function change(User $u, Rental $r, string $to, array $extra = [])
    {
        return $this->as($u)->postJson(route('rentals.status', $r), ['status' => $to] + $extra);
    }

    private function assertBalanced(): void
    {
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.005);
    }

    // ---- returning -------------------------------------------------------

    public function test_an_unpaid_rental_cannot_be_returned(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment');

        $res = $this->change($this->operator, $r, 'returned');

        $res->assertStatus(422);
        $this->assertStringContainsString('not fully paid', $res->json('message'));
        $this->assertStringContainsString('200.000', $res->json('message'));
        $this->assertSame('active', $r->fresh()->status);
    }

    public function test_a_rental_that_was_never_picked_up_cannot_be_returned(): void
    {
        $r = $this->rental(300000, 300000, 'full', pickedUp: false);

        $this->change($this->operator, $r, 'returned')->assertStatus(422)->assertJsonPath('message', 'Cannot return: mark the rental as picked up first.');
        $this->assertSame('active', $r->fresh()->status);
    }

    public function test_a_booking_made_weeks_ahead_can_still_be_closed(): void
    {
        // Bookings are made weeks ahead, and a wrongly typed date must never stop anyone
        // from picking up / returning — so the start date is not a gate.
        $r = $this->rental(300000, 300000, 'full', startInDays: 45);

        $this->change($this->operator, $r, 'returned')->assertOk();
        $this->assertSame('returned', $r->fresh()->status);
    }

    // ---- pickup: the outfit leaves only when paid in full ---------------------

    public function test_a_rental_that_is_not_fully_paid_cannot_be_marked_picked_up(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 1, pickedUp: false);

        $res = $this->as($this->operator)->postJson(route('rentals.pickup', $r), ['picked_up' => true]);

        $res->assertStatus(422);
        $this->assertStringContainsString('200.000', $res->json('message'));
        $this->assertNull($r->fresh()->picked_up_at);
    }

    public function test_taking_a_pickup_back_is_always_allowed(): void
    {
        $r = $this->rental(300000, 300000, 'full');

        $this->as($this->operator)->postJson(route('rentals.pickup', $r), ['picked_up' => false])->assertOk();
        $this->assertNull($r->fresh()->picked_up_at);
    }

    public function test_there_is_always_a_way_through_unpaid_then_pay_then_pickup_then_return(): void
    {
        // The block on pickup must never leave someone stuck: the way out is the ordinary edit.
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 0, pickedUp: false);
        $this->as($this->operator)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->assertStatus(422);

        $this->as($this->manager)->putJson(route('rentals.update', $r), [
            'outfit_ids' => [$r->outfits->first()->id], 'customer_name' => 'X', 'phone' => '1',
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'final_payment', 'amount_paid' => '300000',
        ])->assertOk();

        $this->as($this->operator)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->assertOk();
        $this->change($this->operator, $r, 'returned')->assertOk();
        $this->assertSame('returned', $r->fresh()->status);
        $this->assertSame(0.0, $this->bal('1300'));
    }

    public function test_an_unpaid_rental_can_always_be_cancelled_instead(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 2, pickedUp: false);

        $this->change($this->operator, $r, 'cancelled')->assertOk();
        $this->assertSame('cancelled', $r->fresh()->status);
    }

    public function test_an_unpaid_overdue_fee_blocks_the_return(): void
    {
        $r = $this->rental(100000, 100000, 'full', startInDays: -10);
        Rental::whereKey($r->id)->update(['overdue_fee_amount' => 50000, 'overdue_amount_paid' => 20000]);

        $res = $this->change($this->operator, $r, 'returned');

        $res->assertStatus(422);
        $this->assertStringContainsString('30.000', $res->json('message'));
    }

    public function test_a_paid_started_and_picked_up_rental_can_be_returned_and_it_is_logged(): void
    {
        $r = $this->rental(300000, 300000, 'full');

        $this->change($this->operator, $r, 'returned')->assertOk();

        $this->assertSame('returned', $r->fresh()->status);
        $this->assertTrue(RentalActivityLog::withoutGlobalScopes()->where('rental_id', $r->id)->where('action', 'status_changed')->exists());
    }

    public function test_asking_for_the_status_it_already_has_changes_nothing(): void
    {
        $r = $this->rental(300000, 300000, 'full');
        $logs = RentalActivityLog::withoutGlobalScopes()->count();

        $this->change($this->operator, $r, 'active')->assertOk();

        $this->assertSame($logs, RentalActivityLog::withoutGlobalScopes()->count());
    }

    // ---- end states -------------------------------------------------------

    public function test_a_misclicked_return_can_be_undone_by_the_same_person_for_a_while(): void
    {
        $r = $this->rental(300000, 300000, 'full');
        $this->change($this->operator, $r, 'returned')->assertOk();

        // Undone right away (this happens in real data, within seconds) — and it goes back as picked up.
        $this->change($this->operator, $r, 'active')->assertOk();
        $this->assertSame('active', $r->fresh()->status);
        $this->assertNotNull($r->fresh()->picked_up_at);
    }

    public function test_someone_else_or_a_later_undo_of_a_return_needs_management(): void
    {
        $r = $this->rental(300000, 300000, 'full');
        $this->change($this->operator, $r, 'returned')->assertOk();

        $other = User::factory()->create(['role' => 'admin']);
        $other->stores()->attach($this->store->id);
        ShiftSession::create(['opened_by_user_id' => $other->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_AFTERNOON]);
        $this->change($other, $r, 'active')->assertStatus(403);

        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(31));
        $this->change($this->operator, $r, 'active')->assertStatus(403);
        $this->assertSame('returned', $r->fresh()->status);

        $this->change($this->manager, $r, 'active')->assertOk();
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_only_management_can_reopen_a_cancelled_rental(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false);
        $this->change($this->operator, $r, 'cancelled')->assertOk();

        $this->change($this->operator, $r, 'active')->assertStatus(403);
        $this->assertSame('cancelled', $r->fresh()->status);
        $this->change($this->manager, $r, 'active')->assertOk();
    }

    public function test_a_returned_rental_cannot_be_cancelled_and_a_cancelled_one_cannot_be_returned(): void
    {
        $r = $this->rental(300000, 300000, 'full');
        $this->change($this->operator, $r, 'returned')->assertOk();
        $this->change($this->manager, $r, 'cancelled')->assertStatus(422);
        $this->assertSame('returned', $r->fresh()->status);

        $c = $this->rental(300000, 300000, 'full');
        $this->change($this->manager, $c, 'cancelled')->assertOk();
        $this->change($this->manager, $c, 'returned')->assertStatus(422);
        $this->assertSame('cancelled', $c->fresh()->status);
    }

    // ---- cancelling: keep the money (default) or refund it -------------------

    public function test_cancelling_a_dp_rental_keeps_the_deposit_by_default(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false);

        $this->change($this->operator, $r, 'cancelled')->assertOk();

        $this->assertSame(100000.0, $this->bal('4400'));   // deposit forfeited
        $this->assertSame(100000.0, $this->bal('1100'));   // cash stays
        $this->assertSame(0.0, $this->bal('1300'));
        $this->assertSame(0.0, $this->bal('2300'));
        $this->assertBalanced();
    }

    public function test_management_can_cancel_a_dp_rental_and_refund_the_deposit(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false, method: 'qris');

        $this->change($this->manager, $r, 'cancelled', ['refund' => true])->assertOk();

        $this->assertSame(0.0, $this->bal('4400'));        // nothing forfeited
        $this->assertSame(0.0, $this->bal('1200'));        // 100k in, 100k back out of Bank
        $this->assertSame(0.0, $this->bal('1300'));
        $this->assertSame(0.0, $this->bal('2300'));
        $this->assertBalanced();
        $log = RentalActivityLog::withoutGlobalScopes()->where('rental_id', $r->id)->where('action', 'status_changed')->first();
        $this->assertSame('refund', $log->changes['cancel_policy'][1]);
    }

    public function test_only_management_may_choose_a_refund_when_cancelling(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false);

        $this->change($this->operator, $r, 'cancelled', ['refund' => true])->assertStatus(403);

        $this->assertSame('active', $r->fresh()->status);
        $this->assertSame(0.0, $this->bal('4400'));
    }

    public function test_cancelling_a_fully_paid_rental_keeps_the_money_unless_refunded(): void
    {
        $kept = $this->rental(300000, 300000, 'full', startInDays: 5, pickedUp: false);
        $this->change($this->manager, $kept, 'cancelled')->assertOk();
        $this->assertSame(0.0, $this->bal('4910'));
        $this->assertSame(300000.0, $this->bal('1100'));

        $refunded = $this->rental(200000, 200000, 'full', startInDays: 5, pickedUp: false);
        $this->change($this->manager, $refunded, 'cancelled', ['refund' => true])->assertOk();
        $this->assertSame(200000.0, $this->bal('4910'));   // Rental Refunds
        $this->assertSame(300000.0, $this->bal('1100'));   // the refunded 200k left the till again
        $this->assertBalanced();
    }

    // ---- reopening ----------------------------------------------------------

    public function test_reopening_a_cancelled_rental_reverses_the_cancellation_and_it_can_be_cancelled_again(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false);
        $this->change($this->operator, $r, 'cancelled')->assertOk();
        $this->assertSame(100000.0, $this->bal('4400'));

        $this->change($this->manager, $r, 'active')->assertOk();

        // As if it was never cancelled: contract and receivable are back, nothing left in "Forfeited Deposits".
        $this->assertSame(0.0, $this->bal('4400'));
        $this->assertSame(200000.0, $this->bal('1300'));
        $this->assertSame(300000.0, $this->bal('2300'));
        $this->assertBalanced();

        $this->change($this->operator, $r, 'cancelled')->assertOk();
        $this->assertSame(100000.0, $this->bal('4400'));
        $this->assertSame(0.0, $this->bal('2300'));
        $this->assertBalanced();
    }

    public function test_reopening_a_refunded_cancellation_puts_the_money_back(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false);
        $this->change($this->manager, $r, 'cancelled', ['refund' => true])->assertOk();
        $this->assertSame(0.0, $this->bal('1100'));

        $this->change($this->manager, $r, 'active')->assertOk();

        $this->assertSame(100000.0, $this->bal('1100'));
        $this->assertSame(300000.0, $this->bal('2300'));
        $this->assertBalanced();
    }
    // ---- return, cancel, pickup and delete are blocked while an edit is pending ----

    private function pendingEditRequestFor(Rental $r): RentalApprovalRequest
    {
        return RentalApprovalRequest::create(['store_id' => $this->store->id, 'requested_by_user_id' => $this->operator->id, 'status' => 'pending', 'is_tutorial' => false, 'payload' => [
            'kind' => 'edit', 'rental_id' => $r->id, 'base_amount_paid' => (float) $r->amount_paid,
            'rental' => ['customer_name' => $r->customer_name, 'phone' => $r->phone, 'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
                'status' => 'active', 'payment_method' => $r->payment_method ?? 'cash', 'payment_kind' => $r->payment_kind ?? 'down_payment', 'amount_paid' => (float) $r->amount_paid,
                'discount' => 50000, 'discount_type' => 'fixed', 'discount_note' => 'x'],
            'outfit_ids' => [$r->outfits->first()->id], 'item_discounts' => [], 'item_discount_types' => [], 'item_discount_notes' => []]]);
    }

    public function test_returning_is_blocked_while_an_edit_is_pending(): void
    {
        $r = $this->rental(300000, 300000, 'full');
        $this->pendingEditRequestFor($r);

        $res = $this->change($this->operator, $r, 'returned');

        $res->assertStatus(422);
        $this->assertStringContainsString('edit request waiting', $res->json('message'));
        $this->assertSame('active', $r->fresh()->status);
    }

    public function test_cancelling_is_blocked_while_an_edit_is_pending(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false);
        $this->pendingEditRequestFor($r);

        $res = $this->change($this->operator, $r, 'cancelled');

        $res->assertStatus(422);
        $this->assertStringContainsString('edit request waiting', $res->json('message'));
        $this->assertSame('active', $r->fresh()->status);
    }

    public function test_pickup_is_blocked_while_an_edit_is_pending(): void
    {
        $r = $this->rental(300000, 300000, 'full', pickedUp: false);
        $this->pendingEditRequestFor($r);

        $res = $this->as($this->operator)->postJson(route('rentals.pickup', $r), ['picked_up' => true]);

        $res->assertStatus(422);
        $this->assertStringContainsString('edit request waiting', $res->json('message'));
        $this->assertNull($r->fresh()->picked_up_at);
    }

    public function test_delete_is_blocked_while_an_edit_is_pending(): void
    {
        $r = $this->rental(300000, 300000, 'full');
        $this->pendingEditRequestFor($r);

        $res = $this->as($this->manager)->deleteJson(route('rentals.destroy', $r));

        $res->assertStatus(422);
        $this->assertStringContainsString('edit request waiting', $res->json('message'));
        $this->assertNotNull(Rental::find($r->id));
    }

    public function test_undoing_a_pickup_is_never_blocked_by_a_pending_edit(): void
    {
        $r = $this->rental(300000, 300000, 'full');
        $this->as($this->operator)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->assertOk();
        $this->pendingEditRequestFor($r);

        $this->as($this->operator)->postJson(route('rentals.pickup', $r), ['picked_up' => false])->assertOk();

        $this->assertNull($r->fresh()->picked_up_at);
    }

    public function test_reopening_is_never_blocked_by_a_pending_edit(): void
    {
        $r = $this->rental(300000, 300000, 'full');
        $this->change($this->manager, $r, 'returned')->assertOk();
        $this->pendingEditRequestFor($r);

        $this->change($this->manager, $r, 'active')->assertOk();

        $this->assertSame('active', $r->fresh()->status);
    }

    public function test_everything_works_again_once_the_pending_edit_is_resolved(): void
    {
        $r = $this->rental(300000, 300000, 'full', pickedUp: false);
        $approval = $this->pendingEditRequestFor($r);

        $this->as($this->manager)->postJson(route('approvals.rentals.reject', $approval))->assertOk();

        $this->as($this->operator)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->assertOk();
        $this->change($this->operator, $r, 'returned')->assertOk();
        $this->assertSame('returned', $r->fresh()->status);
    }

    // ---- an admin cannot submit a second edit request while one is pending ----

    public function test_submitting_a_second_edit_request_while_one_is_pending_is_rejected(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false);
        $this->pendingEditRequestFor($r);

        $res = $this->as($this->operator)->putJson(route('rentals.update', $r), [
            'outfit_ids' => [$r->outfits->first()->id], 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000',
            'discount' => '20000', 'discount_type' => 'fixed', 'discount_note' => 'test discount',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('already has an edit request waiting', $res->json('message'));
        $this->assertSame(1, RentalApprovalRequest::withoutGlobalScopes()->where('status', 'pending')->where('payload->rental_id', $r->id)->count());
    }

    public function test_submitting_an_edit_request_works_again_once_the_first_is_resolved(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false);
        $approval = $this->pendingEditRequestFor($r);
        $this->as($this->manager)->postJson(route('approvals.rentals.reject', $approval))->assertOk();

        $res = $this->as($this->operator)->putJson(route('rentals.update', $r), [
            'outfit_ids' => [$r->outfits->first()->id], 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000',
            'discount' => '20000', 'discount_type' => 'fixed', 'discount_note' => 'test discount',
        ]);

        $res->assertOk();
        $this->assertSame('edit_approval_request_sent', $res->json('mode'));
    }

    // ---- a paid rent is cancelled, never deleted ----------------------------------

    public function test_a_paid_rent_cannot_be_deleted_by_management_and_can_be_cancelled_instead(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false);

        $res = $this->as($this->manager)->deleteJson(route('rentals.destroy', $r));

        $res->assertStatus(422);
        $this->assertStringContainsString('Rp 100.000', $res->json('message'));
        $this->assertNotNull(Rental::find($r->id));
        $this->assertSame(100000.0, $this->bal('1100'));

        $this->change($this->manager, $r, 'cancelled')->assertOk();
        $this->assertSame(100000.0, $this->bal('4400'), 'the deposit stays on the books as forfeited');
        $this->assertBalanced();
    }

    public function test_an_admin_cannot_even_request_deleting_a_paid_rent(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false);

        $this->as($this->operator)->deleteJson(route('rentals.destroy', $r))->assertStatus(422);

        $this->assertSame(0, RentalApprovalRequest::where('status', 'pending')->count());
    }

    public function test_an_old_delete_request_for_a_now_paid_rent_is_refused_on_approval(): void
    {
        $r = $this->rental(300000, 100000, 'down_payment', startInDays: 5, pickedUp: false);
        $approval = RentalApprovalRequest::create(['store_id' => $this->store->id, 'requested_by_user_id' => $this->operator->id, 'status' => 'pending', 'is_tutorial' => false,
            'payload' => ['kind' => 'delete', 'rental_id' => $r->id]]);

        $this->as($this->manager)->postJson(route('approvals.rentals.approve', $approval))->assertStatus(422);

        $this->assertNotNull(Rental::find($r->id));
        $this->assertSame(100000.0, $this->bal('1100'));
    }

    public function test_an_unpaid_rent_can_still_be_deleted(): void
    {
        $r = $this->rental(300000, 0, 'down_payment', startInDays: 5, pickedUp: false);

        $this->as($this->manager)->deleteJson(route('rentals.destroy', $r))->assertOk();

        $this->assertNull(Rental::find($r->id));
    }
}
