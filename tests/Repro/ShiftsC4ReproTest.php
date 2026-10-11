<?php

namespace Tests\Repro;

use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Outfit;
use App\Models\TutorialShiftSession;
use App\Models\User;
use App\Support\PaymentShiftAssignment;
use App\Support\ShiftClosedTotals;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * Throwaway repros for the C4 overnight review (docs/overnight-2026-10-09/03d-shifts-review.md).
 * Tests named test_BUG_* assert the CURRENT (suspected wrong) behaviour: a passing test = bug confirmed.
 * Tests named test_OK_* are negative controls.
 *
 * Numbers: no tax, one outfit per rent, payment records written directly (the ledger is not under test here).
 */
class ShiftsC4ReproTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    protected function tearDown(): void
    {
        StoreContext::set(null, false);
        parent::tearDown();
    }

    private function outfit(float $price, int $storeId = 1): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $storeId, 'code' => 'S'.uniqid(), 'price' => $price, 'stock' => 1]);
    }

    private function rental(string $name, float $price, ?int $shiftId, float $paid, int $storeId = 1, string $kind = 'full'): Rental
    {
        $rental = Rental::create([
            'store_id' => $storeId, 'customer_name' => $name, 'phone' => '0812'.random_int(1000000, 9999999), 'notes' => 'X',
            'start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => $kind, 'amount_paid' => $paid,
            'shift_session_id' => $shiftId,
        ]);
        $outfit = $this->outfit($price, $storeId);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $price, 'price_excl_tax' => $price, 'tax_amount' => 0]);

        return $rental;
    }

    private function record(Rental $rental, ?int $shiftId, float $amount, ?int $userId = null, string $kind = 'full'): RentalPaymentRecord
    {
        return RentalPaymentRecord::create([
            'rental_id' => $rental->id, 'shift_session_id' => $shiftId, 'received_by_user_id' => $userId,
            'amount' => $amount, 'payment_method' => 'cash', 'payment_kind' => $kind,
            'recorded_at' => now()->toDateTimeString(),
        ]);
    }

    private function closedShift(User $by, string $opened = '-1 day', int $storeId = 1, float $total = 0): ShiftSession
    {
        $openedAt = now()->modify($opened);
        $shift = ShiftSession::withoutGlobalScopes()->create([
            'store_id' => $storeId, 'opened_by_user_id' => $by->id, 'opened_at' => $openedAt, 'period' => ShiftSession::PERIOD_MORNING,
        ]);
        $shift->forceFill(['closed_at' => $openedAt->copy()->addHours(6), 'closed_by_user_id' => $by->id, 'total_revenue' => $total, 'total_transactions' => 1])->save();

        return $shift;
    }

    // ---- C4-01: a negative payment record (bill went down after payment) -------------------

    public function test_FIXED_c4_01_negative_record_report_and_stored_close_total_agree(): void
    {
        $adminA = User::factory()->create(['role' => 'admin']);
        $adminB = User::factory()->create(['role' => 'admin']);

        $shiftA = $this->closedShift($adminA, '-1 day', 1, 1000000);
        $r1 = $this->rental('Customer One', 1000000, $shiftA->id, 1000000);
        $this->record($r1, $shiftA->id, 1000000, $adminA->id);

        $this->actingAs($adminB)->post(route('shifts.open'), ['period' => 'afternoon'])->assertSessionHasNoErrors();
        $shiftB = ShiftSession::where('opened_by_user_id', $adminB->id)->whereNull('closed_at')->firstOrFail();
        $r2 = $this->rental('Customer Two', 500000, $shiftB->id, 500000);
        $this->record($r2, $shiftB->id, 500000, $adminB->id);
        // Next day an approved edit lowers Customer One's bill by 300.000 -> the recorder writes -300.000 into the
        // shift of the person who hands the money back (RentalPaymentRecorder::recordIfNonZero accepts negatives).
        $this->record($r1, $shiftB->id, -300000, $adminB->id, 'refund');

        $this->actingAs($adminB)->post(route('shifts.close'))->assertSessionHasNoErrors();
        $shiftB->refresh();

        $report = $this->actingAs($adminB)->get(route('shifts.report', $shiftB))->assertOk();
        fwrite(STDERR, sprintf("\n[C4-01] stored total_revenue=%s  report totalReceived=%s\n", $shiftB->total_revenue, $report->viewData('totalReceived')));

        // Stored figure (shift card, WhatsApp share text, dashboard "Cash Collected") includes the -300.000 ...
        $this->assertEqualsWithDelta(200000, (float) $shiftB->total_revenue, 0.01);
        // ... and the report/PDF for the very same shift now shows the same figure.
        $this->assertEqualsWithDelta(200000, (float) $report->viewData('totalReceived'), 0.01);
    }

    public function test_FIXED_c4_01_shiftless_negative_record_is_listed_for_assignment(): void
    {
        $adminA = User::factory()->create(['role' => 'admin']);
        $shiftA = $this->closedShift($adminA, '-1 day', 1, 1000000);
        $r1 = $this->rental('Customer One', 700000, $shiftA->id, 700000);
        $this->record($r1, $shiftA->id, 1000000, $adminA->id);
        // A supervisor without an open shift lowers the bill: the refund record has no shift.
        $neg = $this->record($r1, null, -300000, null, 'refund');

        $listed = PaymentShiftAssignment::unassigned();
        $this->assertCount(1, $listed, 'the shiftless correction shows in the assignment panel');
        $this->assertEqualsWithDelta(-300000, (float) $listed->first()['payment']->amount, 0.01);
        $this->assertEqualsWithDelta(1000000, ShiftClosedTotals::calculate($shiftA)['received'], 0.01, 'and no shift total reflects it until it is assigned');
        $this->assertNull($neg->fresh()->shift_session_id);
    }

    // ---- C4-02: other store's shift: report empty + stored total not refreshed --------------

    public function test_FIXED_c4_02_shift_report_of_the_other_store_is_correct_for_management(): void
    {
        $admin2 = User::factory()->create(['role' => 'admin']);
        $admin2->stores()->attach(2);
        $manager = User::factory()->create(['role' => 'management']);

        $shift2 = $this->closedShift($admin2, '-1 day', 2, 300000);
        $r = $this->rental('Demo Store Two Customer', 300000, $shift2->id, 300000, 2);
        $this->record($r, $shift2->id, 300000, $admin2->id);

        $viewing1 = $this->actingAs($manager)->withSession(['current_store_id' => 1])->get(route('shifts.report', $shift2))->assertOk();
        $viewing2 = $this->actingAs($manager)->withSession(['current_store_id' => 2])->get(route('shifts.report', $shift2))->assertOk();
        fwrite(STDERR, sprintf("\n[C4-02a] report while store 1 selected: transactions=%s received=%s | store 2 selected: transactions=%s received=%s\n",
            $viewing1->viewData('totalTransactions'), $viewing1->viewData('totalReceived'), $viewing2->viewData('totalTransactions'), $viewing2->viewData('totalReceived')));

        $this->assertSame(300000.0, (float) $viewing2->viewData('totalReceived'), 'control: right store selected -> correct');
        $this->assertSame(300000.0, (float) $viewing1->viewData('totalReceived'), 'same shift, other store selected -> same figure');
        $this->assertSame((int) $viewing2->viewData('totalTransactions'), (int) $viewing1->viewData('totalTransactions'));
    }

    public function test_FIXED_c4_02_recompute_reaches_other_store_shift_when_management_deletes_from_another_store(): void
    {
        $admin2 = User::factory()->create(['role' => 'admin']);
        $admin2->stores()->attach(2);
        $manager = User::factory()->create(['role' => 'management']);

        $shift2 = $this->closedShift($admin2, '-1 day', 2, 300000);
        $r = $this->rental('Demo Store Two Customer', 300000, $shift2->id, 300000, 2);
        $this->record($r, $shift2->id, 300000, $admin2->id);

        // Management has store 1 selected and deletes the store-2 rent as "wrong input".
        $this->actingAs($manager)->withSession(['current_store_id' => 1])
            ->deleteJson(route('rentals.destroy', $r), ['wrong_input' => true, 'reason' => 'Entered by mistake'])
            ->assertOk();

        $this->assertTrue(Rental::withoutGlobalScopes()->onlyTrashed()->whereKey($r->id)->exists(), 'rent is deleted');
        $stored = (float) ShiftSession::withoutGlobalScopes()->find($shift2->id)->total_revenue;
        StoreContext::set(2);
        $live = ShiftClosedTotals::calculate(ShiftSession::withoutGlobalScopes()->find($shift2->id))['received'];
        fwrite(STDERR, sprintf("\n[C4-02b] stored total after delete=%s, real total=%s\n", $stored, $live));
        $this->assertEqualsWithDelta(0, $stored, 0.01, 'closed shift of store 2 is refreshed to 0');
        $this->assertEqualsWithDelta(0, $live, 0.01);
    }

    public function test_OK_delete_from_the_same_store_refreshes_the_stored_total(): void
    {
        $admin2 = User::factory()->create(['role' => 'admin']);
        $admin2->stores()->attach(2);
        $manager = User::factory()->create(['role' => 'management']);
        $shift2 = $this->closedShift($admin2, '-1 day', 2, 300000);
        $r = $this->rental('Demo Store Two Customer', 300000, $shift2->id, 300000, 2);
        $this->record($r, $shift2->id, 300000, $admin2->id);

        $this->actingAs($manager)->withSession(['current_store_id' => 2])
            ->deleteJson(route('rentals.destroy', $r), ['wrong_input' => true, 'reason' => 'Entered by mistake'])
            ->assertOk();
        $this->assertEqualsWithDelta(0, (float) ShiftSession::withoutGlobalScopes()->find($shift2->id)->total_revenue, 0.01);
    }

    // ---- C4-03: practice shift id collides with a real shift id ----------------------------

    public function test_FIXED_c4_03_practice_shift_close_total_and_report_ignore_real_payments_of_the_same_id(): void
    {
        $adminA = User::factory()->create(['role' => 'admin', 'name' => 'Real Admin']);
        $adminB = User::factory()->create(['role' => 'admin', 'name' => 'Practising Admin']);

        $real = $this->closedShift($adminA, '-3 days', 1, 300000);
        $r = $this->rental('Budi Real Customer', 300000, $real->id, 300000);
        $this->record($r, $real->id, 300000, $adminA->id);

        // Practice shifts live in their own table with their own counter: same id as the real shift.
        $practice = new TutorialShiftSession;
        $practice->forceFill(['opened_by_user_id' => $adminB->id, 'opened_at' => now(), 'period' => 'morning', 'store_id' => 1]);
        $practice->id = $real->id;
        $practice->save();

        $this->actingAs($adminB)->post(route('shifts.close'), ['tutorial_practice' => 1])->assertSessionHasNoErrors();
        $practice = TutorialShiftSession::find($real->id);
        fwrite(STDERR, sprintf("\n[C4-03] practice shift #%s total_revenue after close = %s\n", $practice->id, $practice->total_revenue));
        $this->assertEqualsWithDelta(0, (float) $practice->total_revenue, 0.01, 'practice total must not show real money');

        $report = $this->actingAs($adminB)->get(route('shifts.tutorial.report', $practice))->assertOk();
        $names = collect($report->viewData('rentalReports'))->map(fn ($row) => $row['rental']->customer_name)->all();
        fwrite(STDERR, '[C4-03] practice report lists: '.json_encode($names)."\n");
        $this->assertNotContains('Budi Real Customer', $names, 'real customer of another admin must not appear in the practice report');
        $this->assertEqualsWithDelta(0, (float) $report->viewData('totalReceived'), 0.01);

        // The real shift, its payment record and its stored total are untouched.
        $this->assertEqualsWithDelta(300000, (float) ShiftSession::withoutGlobalScopes()->find($real->id)->total_revenue, 0.01);
        $this->assertSame(1, RentalPaymentRecord::where('shift_session_id', $real->id)->count());
    }

    public function test_FIXED_c4_03_practice_shift_counts_its_own_practice_rent_and_not_the_real_one_with_the_same_ids(): void
    {
        $adminA = User::factory()->create(['role' => 'admin', 'name' => 'Real Admin']);
        $adminB = User::factory()->create(['role' => 'admin', 'name' => 'Practising Admin']);

        $real = $this->closedShift($adminA, '-3 days', 1, 300000);
        $r = $this->rental('Budi Real Customer', 300000, $real->id, 300000);
        $this->record($r, $real->id, 300000, $adminA->id);

        $practice = new TutorialShiftSession;
        $practice->forceFill(['opened_by_user_id' => $adminB->id, 'opened_at' => now(), 'period' => 'morning', 'store_id' => 1]);
        $practice->id = $real->id;
        $practice->save();

        // A practice rent in the practice shift, with the SAME id as the real rent, paid 50.000 of 120.000.
        $outfit = $this->outfit(120000);
        $practiceRent = new \App\Models\TutorialRental;
        $practiceRent->forceFill([
            'customer_name' => 'Sample Customer', 'phone' => '000000000',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'active', 'handled_by_user_id' => $adminB->id, 'created_by_user_id' => $adminB->id,
            'shift_session_id' => $practice->id, 'payment_method' => 'cash', 'payment_kind' => 'dp', 'amount_paid' => 50000,
        ]);
        $practiceRent->id = $r->id;
        $practiceRent->save();
        $practiceRent->outfits()->attach($outfit->id, ['price_incl_tax' => 120000, 'price_excl_tax' => 120000, 'tax_amount' => 0]);

        $this->actingAs($adminB)->post(route('shifts.close'), ['tutorial_practice' => 1])->assertSessionHasNoErrors();
        $closed = TutorialShiftSession::find($practice->id);
        $this->assertEqualsWithDelta(50000, (float) $closed->total_revenue, 0.01, 'practice total = the practice rent only');
        $this->assertSame(1, (int) $closed->total_transactions);

        $report = $this->actingAs($adminB)->get(route('shifts.tutorial.report', $closed))->assertOk();
        $rows = collect($report->viewData('rentalReports'));
        $this->assertSame(['Sample Customer'], $rows->map(fn ($row) => $row['rental']->customer_name)->all());
        $this->assertEqualsWithDelta(50000, (float) $report->viewData('totalReceived'), 0.01);

        // The real rent, its record and the real shift stay exactly as they were.
        $this->assertEqualsWithDelta(300000, (float) Rental::withoutGlobalScopes()->find($r->id)->amount_paid, 0.01);
        $this->assertEqualsWithDelta(300000, (float) ShiftSession::withoutGlobalScopes()->find($real->id)->total_revenue, 0.01);
        $this->assertSame(1, RentalPaymentRecord::where('shift_session_id', $real->id)->count());
    }

    // ---- C4-05: assignment: reject after accept on a stale model ----------------------------

    public function test_BUG_stale_reject_after_accept_flips_the_approval_but_not_the_payment(): void
    {
        $orla = User::factory()->create(['role' => 'admin', 'name' => 'Orla']);
        $boss = User::factory()->create(['role' => 'management', 'name' => 'Boss']);
        $vera = User::factory()->create(['role' => 'supervisor', 'name' => 'Vera']);

        $shift = ShiftSession::create(['opened_by_user_id' => $orla->id, 'opened_at' => now()->subHours(2), 'period' => 'morning']);
        $r = $this->rental('Dana', 415000, null, 415000);
        $payment = $this->record($r, null, 415000, $vera->id, 'final_payment');

        $this->actingAs($boss)->post(route('payments.assign-shift', $payment->id), ['shift_session_id' => $shift->id])->assertSessionHasNoErrors();
        $approval = RentalApprovalRequest::where('payload->kind', 'payment_assign')->firstOrFail();
        $stale = RentalApprovalRequest::find($approval->id); // second tab / double click holds this

        $this->assertNull(PaymentShiftAssignment::accept($approval, $orla));
        $msg = PaymentShiftAssignment::reject($stale, $orla, 'oops');

        fwrite(STDERR, sprintf("\n[C4-05] second decision message=%s  approval status=%s  payment shift=%s\n", json_encode($msg), $approval->fresh()->status, json_encode($payment->fresh()->shift_session_id)));
        $this->assertNull($msg, 'BUG: the stale reject is accepted');
        $this->assertSame('rejected', $approval->fresh()->status);
        $this->assertSame($shift->id, $payment->fresh()->shift_session_id, 'payment is nevertheless in the shift');
    }

    public function test_OK_accept_twice_is_refused_the_second_time(): void
    {
        $orla = User::factory()->create(['role' => 'admin']);
        $boss = User::factory()->create(['role' => 'management']);
        $shift = ShiftSession::create(['opened_by_user_id' => $orla->id, 'opened_at' => now()->subHours(2), 'period' => 'morning']);
        $r = $this->rental('Dana', 415000, null, 415000);
        $payment = $this->record($r, null, 415000);
        $this->actingAs($boss)->post(route('payments.assign-shift', $payment->id), ['shift_session_id' => $shift->id])->assertSessionHasNoErrors();
        $approval = RentalApprovalRequest::where('payload->kind', 'payment_assign')->firstOrFail();
        $stale = RentalApprovalRequest::find($approval->id);
        $this->assertNull(PaymentShiftAssignment::accept($approval, $orla));
        $this->assertNotNull(PaymentShiftAssignment::accept($stale, $orla));
        $this->assertEqualsWithDelta(415000, (float) RentalPaymentRecord::where('shift_session_id', $shift->id)->sum('amount'), 0.01);
    }

    // ---- C4-06: by-admin summary in a shift report -------------------------------------------

    public function test_BUG_by_admin_summary_credits_the_rent_handler_not_the_shift_owner(): void
    {
        $adminA = User::factory()->create(['role' => 'admin', 'name' => 'Admin Alpha']);
        $adminB = User::factory()->create(['role' => 'admin', 'name' => 'Admin Beta']);
        $manager = User::factory()->create(['role' => 'management']);

        $shiftA = $this->closedShift($adminA, '-1 day', 1, 100000);
        $r = $this->rental('Customer', 500000, $shiftA->id, 100000, 1, 'down_payment');
        $r->forceFill(['handled_by_user_id' => $adminA->id, 'created_by_user_id' => $adminA->id])->save();
        $this->record($r, $shiftA->id, 100000, $adminA->id, 'down_payment');

        // Next day Admin Beta collects the remaining 400.000 in HIS shift.
        $shiftB = $this->closedShift($adminB, '-2 hours', 1, 400000);
        $this->record($r, $shiftB->id, 400000, $adminB->id, 'final_payment');

        $report = $this->actingAs($manager)->get(route('shifts.report', $shiftB))->assertOk();
        $by = collect($report->viewData('byAdmin'))->map(fn ($row) => $row['name'].'='.$row['received'])->all();
        fwrite(STDERR, "\n[C4-06] byAdmin for Beta's shift: ".json_encode($by)."\n");
        $this->assertSame(['Admin Alpha=400000'], $by, 'BUG: Beta collected the money, the summary says Alpha');
    }

    // ---- C4-07: deleting a staff account removes their shifts and orphans the payments ---------

    public function test_BUG_deleting_a_user_deletes_their_shifts_and_orphans_payments(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'name' => 'Leaver']);
        $manager = User::factory()->create(['role' => 'management']);
        $shift = $this->closedShift($admin, '-5 days', 1, 800000);
        $r = $this->rental('Customer', 800000, $shift->id, 800000);
        $this->record($r, $shift->id, 800000, $admin->id);

        $status = null;
        try {
            $status = $this->actingAs($manager)->delete(route('users.destroy', $admin))->getStatusCode();
            $err = session('error');
        } catch (\Throwable $e) {
            $status = 'exception '.get_class($e).': '.substr($e->getMessage(), 0, 160);
            $err = null;
        }
        fwrite(STDERR, "\n[C4-07] delete user -> ".json_encode($status).' flash='.json_encode($err)."\n");
        fwrite(STDERR, '[C4-07] user still exists: '.json_encode(User::whereKey($admin->id)->exists()).' shift still exists: '.json_encode(ShiftSession::withoutGlobalScopes()->whereKey($shift->id)->exists())
            .' payment shift id: '.json_encode(RentalPaymentRecord::first()?->shift_session_id)."\n");
        $this->assertTrue(true);
    }

    public function test_FIXED_c4_02_approving_a_wrong_input_delete_of_the_other_store_refreshes_its_closed_shift(): void
    {
        $admin2 = User::factory()->create(['role' => 'admin']);
        $admin2->stores()->attach(2);
        $supervisor2 = User::factory()->create(['role' => 'supervisor']);
        $supervisor2->stores()->attach(2);
        $manager = User::factory()->create(['role' => 'management']);

        $shift2 = $this->closedShift($admin2, '-1 day', 2, 300000);
        $r = $this->rental('Demo Store Two Customer', 300000, $shift2->id, 300000, 2);
        $this->record($r, $shift2->id, 300000, $admin2->id);

        $approval = RentalApprovalRequest::withoutGlobalScopes()->create([
            'store_id' => 2, 'requested_by_user_id' => $supervisor2->id, 'source_shift_session_id' => null,
            'payload' => ['kind' => 'delete', 'rental_id' => $r->id, 'customer_name' => 'Demo Store Two Customer', 'wrong_input' => true, 'reason' => 'Entered by mistake', 'paid' => 300000.0],
        ]);

        // Management works from store 1 (queue shows both stores, binding allows it).
        $this->actingAs($manager)->withSession(['current_store_id' => 1])
            ->postJson(route('approvals.rentals.approve', $approval))->assertOk();

        $this->assertTrue(Rental::withoutGlobalScopes()->onlyTrashed()->whereKey($r->id)->exists(), 'rent is deleted');
        $stored = (float) ShiftSession::withoutGlobalScopes()->find($shift2->id)->total_revenue;
        fwrite(STDERR, "\n[C4-02c] approve delete from store 1 context: stored total of store-2 shift = {$stored} (real 0)\n");
        $this->assertEqualsWithDelta(0, $stored, 0.01, 'closed shift of store 2 is refreshed to 0');
    }
}
