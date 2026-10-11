<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalLedgerSync;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Scenario: paid in full, picked up and returned on time, but nobody
 * clicked pickup/return — so a receipt later added a Rp 100.000 overdue fee.
 * Staff report what happened; a supervisor/management (not the requester)
 * approves; the times, who handled it, and the fee removal are in History.
 */
class RentalLateDoneTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $orla;

    private User $asti;

    private User $vera;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-05 12:00:00');
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
        $this->orla = User::factory()->create(['role' => 'admin', 'name' => 'Orlaadmin']);
        $this->asti = User::factory()->create(['role' => 'admin', 'name' => 'Ann']);
        $this->vera = User::factory()->create(['role' => 'supervisor', 'name' => 'Vera']);
        foreach ([$this->orla, $this->asti, $this->vera] as $u) {
            $u->stores()->attach($this->store->id);
        }
        StoreContext::set($this->store->id);
        $this->openShiftAs($this->orla);
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function hendra(): Rental
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'JBL'.Outfit::withoutGlobalScopes()->count(), 'price' => 315000, 'stock' => 1]);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'HARPER', 'phone' => '0853',
            'start_date' => '2026-09-29', 'end_date' => '2026-10-01', 'status' => 'active',
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => 315000,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 315000, 'price_excl_tax' => 315000, 'tax_amount' => 0]);
        RentalPaymentRecorder::recordIfNonZero($rental, 315000, null, $this->vera->id, 'cash', 'full', totalDue: 315000.0);
        // What the receipt did on 2 Oct to a still-"active" rent.
        $rental->forceFill(['overdue_base_end_date' => '2026-10-01', 'overdue_fee_rate' => 100000, 'overdue_fee_days' => 1, 'overdue_fee_amount' => 100000, 'overdue_fee_applied_at' => '2026-10-02 18:33:05'])->save();

        return $rental->fresh('outfits');
    }

    private function report(User $by, Rental $rental, array $over = [])
    {
        return $this->actingAs($by)->postJson(route('rentals.late-done-request', $rental), array_merge([
            'type' => 'return',
            'picked_up_at' => '2026-09-29 10:00',
            'picked_up_by' => $this->orla->id,
            'returned_at' => '2026-10-01 16:30',
            'returned_by' => $this->asti->id,
            'note' => 'Forgot to click pickup and return',
        ], $over));
    }

    public function test_reported_return_on_time_is_applied_after_approval_and_removes_the_fee(): void
    {
        $rental = $this->hendra();
        // Total due shown on the card: rent + the overdue fee the receipt added.
        $this->assertEqualsWithDelta(415000, \App\Support\RentalFinancialSummary::summarize($rental, 0)['net_incl_tax'], 0.01);

        $this->report($this->orla, $rental)->assertOk()->assertJson(['ok' => true]);
        $this->assertSame('active', $rental->fresh()->status, 'nothing changes before approval');
        $approval = RentalApprovalRequest::where('payload->kind', 'late_done')->firstOrFail();

        // The reporter cannot approve their own report.
        $this->actingAs($this->orla)->post(route('approvals.rentals.approve', $approval))->assertForbidden();

        $this->actingAs($this->vera)->get(route('approvals.edit-requests.index'))->assertOk()
            ->assertSee('Pickup / return report')->assertSee('by Orlaadmin')->assertSee('received by Ann')
            ->assertSee('overdue fee Rp 100.000 will be removed');
        $this->actingAs($this->vera)->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();

        $rental = $rental->fresh('outfits');
        $this->assertSame('returned', $rental->status);
        $this->assertSame('2026-09-29 10:00', $rental->picked_up_at->format('Y-m-d H:i'));
        $this->assertEqualsWithDelta(0, (float) $rental->overdue_fee_amount, 0.01);
        $this->assertEqualsWithDelta(315000, \App\Support\RentalFinancialSummary::summarize($rental, 0)['net_incl_tax'], 0.01, 'fully paid again');

        $log = RentalActivityLog::where('rental_id', $rental->id)->where('action', 'late_done_approved')->firstOrFail();
        $this->assertSame($this->vera->id, $log->user_id);
        $this->assertSame([null, 'Orlaadmin'], $log->changes['picked_up_by']);
        $this->assertSame([null, 'Ann'], $log->changes['returned_by']);
        $this->assertSame([null, '01 Oct 2026 16:30'], $log->changes['returned_at']);
        $this->assertEquals([100000, 0], $log->changes['overdue_fee_amount']);
        $this->assertTrue(RentalActivityLog::where('rental_id', $rental->id)->where('action', 'late_done_requested')->where('user_id', $this->orla->id)->exists());
    }

    public function test_late_return_keeps_the_fee(): void
    {
        $rental = $this->hendra();
        $this->report($this->orla, $rental, ['returned_at' => '2026-10-03 11:00'])->assertOk();
        $approval = RentalApprovalRequest::where('payload->kind', 'late_done')->firstOrFail();
        $this->actingAs($this->vera)->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();

        $this->assertSame('returned', $rental->fresh()->status);
        $this->assertEqualsWithDelta(100000, (float) $rental->fresh()->overdue_fee_amount, 0.01);
    }

    public function test_pickup_only_and_validation(): void
    {
        $rental = $this->hendra();
        // Return before pickup, future time, missing fields: refused.
        $this->report($this->orla, $rental, ['returned_at' => '2026-09-28 10:00'])->assertStatus(422);
        $this->report($this->orla, $rental, ['picked_up_at' => '2026-10-09 10:00'])->assertStatus(422);
        $this->report($this->orla, $rental, ['picked_up_by' => null])->assertStatus(422);

        $this->report($this->orla, $rental, ['type' => 'pickup', 'returned_at' => null, 'returned_by' => null])->assertOk();
        // One waiting report at a time.
        $this->report($this->orla, $rental)->assertStatus(422);

        $approval = RentalApprovalRequest::where('payload->kind', 'late_done')->firstOrFail();
        $this->actingAs($this->vera)->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();
        $rental = $rental->fresh();
        $this->assertSame('active', $rental->status);
        $this->assertNotNull($rental->picked_up_at);
    }

    public function test_reject_and_cancel_are_logged(): void
    {
        $rental = $this->hendra();
        $this->report($this->orla, $rental)->assertOk();
        $approval = RentalApprovalRequest::where('payload->kind', 'late_done')->firstOrFail();
        $this->actingAs($this->vera)->post(route('approvals.rentals.reject', $approval))->assertSessionHasNoErrors();
        $this->assertTrue(RentalActivityLog::where('rental_id', $rental->id)->where('action', 'late_done_rejected')->exists());
        $this->assertSame('active', $rental->fresh()->status);

        $this->report($this->orla, $rental)->assertOk();
        $second = RentalApprovalRequest::where('payload->kind', 'late_done')->where('status', 'pending')->firstOrFail();
        $this->actingAs($this->orla)->post(route('approvals.rentals.cancel', $second))->assertSessionHasNoErrors();
        $this->assertTrue(RentalActivityLog::where('rental_id', $rental->id)->where('action', 'late_done_cancelled')->exists());
    }

    public function test_practice_tour_seeds_tutorial_data_and_can_be_approved_without_touching_real_data(): void
    {
        Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'TUT-1', 'price' => 315000, 'stock' => 1, 'is_tutorial' => true]);
        // A real, already-decided approval with the same id the practice one will get
        // (separate id sequences) — the practice buttons must not hit this one.
        RentalApprovalRequest::create(['requested_by_user_id' => $this->orla->id, 'payload' => ['kind' => 'edit', 'rental_id' => 999], 'status' => 'approved']);
        $realRents = Rental::withoutGlobalScopes()->count();
        $realApprovals = RentalApprovalRequest::withoutGlobalScopes()->count();

        $this->actingAs($this->orla)->postJson(route('approvals.tutorial-seed-late-done-request'))->assertForbidden();
        $id = $this->actingAs($this->vera)->postJson(route('approvals.tutorial-seed-late-done-request'))->assertOk()->json('approval_id');
        // Idempotent.
        $this->assertSame($id, $this->actingAs($this->vera)->postJson(route('approvals.tutorial-seed-late-done-request'))->json('approval_id'));

        $this->actingAs($this->vera)->get(route('approvals.edit-requests.index', ['tutorial' => 1]))->assertOk()
            ->assertSee('Pickup / return report')->assertSee('Sample Missed Return')->assertSee('late-done-actions-'.$id, false)
            ->assertSee('will be removed');

        // The person taking the tour plays both sides on practice data.
        $this->assertSame(1, (int) $id, 'ids collide with the real approval #1');
        $this->actingAs($this->vera)->post(route('approvals.rentals.approve', ['approval' => $id, 'tutorial' => 1]))->assertSessionHasNoErrors();
        $practice = \App\Models\TutorialRental::query()->firstOrFail();
        $this->assertSame('returned', $practice->status);
        $this->assertEqualsWithDelta(0, (float) $practice->overdue_fee_amount, 0.01);
        $this->assertTrue(\App\Models\TutorialRentalActivityLog::where('rental_id', $practice->id)->where('action', 'late_done_approved')->exists());

        // Nothing real was created or changed.
        $this->assertSame($realRents, Rental::withoutGlobalScopes()->count());
        $this->assertSame($realApprovals, RentalApprovalRequest::withoutGlobalScopes()->count());
        $this->assertFalse(RentalActivityLog::withoutGlobalScopes()->where('action', 'like', 'late_done%')->exists());
    }

    public function test_help_page_lists_the_tour_for_every_role(): void
    {
        foreach ([$this->orla, $this->vera] as $u) {
            $this->actingAs($u)->get(route('help.index'))->assertOk()->assertSee('data-open-late-done-tour', false)->assertSee('Forgot to click pickup / return');
        }
    }

    public function test_admin_practice_report_through_the_real_form_stays_in_practice_tables(): void
    {
        Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'TUT-1', 'price' => 315000, 'stock' => 1, 'is_tutorial' => true]);
        // A real rent that will share the practice rent's id (separate sequences).
        $real = $this->hendra();
        $realApprovals = RentalApprovalRequest::withoutGlobalScopes()->count();

        $rentId = $this->actingAs($this->orla)->postJson(route('tutorial.late-done-rent'))->assertOk()->json('rental_id');
        $this->assertSame($real->id, (int) $rentId, 'ids collide with the real rent');

        // The tour's form posts with ?tutorial=1.
        $this->actingAs($this->orla)->postJson(route('rentals.late-done-request', ['rental' => $rentId, 'tutorial' => 1]), [
            'type' => 'return', 'picked_up_at' => now()->subDays(6)->format('Y-m-d 10:00'), 'picked_up_by' => $this->orla->id,
            'returned_at' => now()->subDays(4)->format('Y-m-d 16:00'), 'returned_by' => $this->asti->id,
        ])->assertOk();

        $this->assertSame($realApprovals, RentalApprovalRequest::withoutGlobalScopes()->count(), 'no real approval created');
        $this->assertSame(1, \App\Models\TutorialRentalApprovalRequest::where('payload->kind', 'late_done')->count());
        $this->assertFalse(RentalActivityLog::where('rental_id', $real->id)->where('action', 'late_done_requested')->exists(), 'real rent untouched');
        $this->assertSame('active', $real->fresh()->status);
    }
}
