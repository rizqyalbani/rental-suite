<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\ShiftClosedTotals;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A payment entered without a shift (the scenario) is sent by management to a
 * shift owner, who accepts it into their shift. Nobody else can decide it.
 */
class PaymentShiftAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $orla;

    private User $asti;

    private User $vera;

    private User $boss;

    private ShiftSession $orlaShift;

    private RentalPaymentRecord $payment;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
        $this->store = Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        $this->orla = User::factory()->create(['role' => 'admin', 'name' => 'Orlaadmin']);
        $this->asti = User::factory()->create(['role' => 'admin', 'name' => 'Ann']);
        $this->vera = User::factory()->create(['role' => 'supervisor', 'name' => 'Vera']);
        $this->boss = User::factory()->create(['role' => 'management', 'name' => 'Boss']);
        foreach ([$this->orla, $this->asti, $this->vera, $this->boss] as $u) {
            $u->stores()->attach($this->store->id);
        }
        StoreContext::set($this->store->id);

        // Orla's shift, already closed, was open when Vera typed the payment in.
        $this->orlaShift = ShiftSession::create(['opened_by_user_id' => $this->orla->id, 'opened_at' => '2026-09-19 08:49:00', 'period' => ShiftSession::PERIOD_MORNING]);
        $this->orlaShift->forceFill(['closed_at' => '2026-09-19 15:39:00', 'total_revenue' => 50000, 'total_transactions' => 0])->save();

        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'D1', 'price' => 615000, 'stock' => 1]);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'DANA', 'phone' => '0813',
            'start_date' => '2026-09-19', 'end_date' => '2026-09-21', 'status' => 'active',
            'payment_method' => 'qris', 'payment_kind' => 'final_payment', 'amount_paid' => 415000,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 615000, 'price_excl_tax' => 615000, 'tax_amount' => 0]);
        $this->payment = RentalPaymentRecord::create([
            'rental_id' => $rental->id, 'shift_session_id' => null, 'received_by_user_id' => $this->vera->id,
            'amount' => 415000, 'payment_method' => 'qris', 'payment_kind' => 'final_payment', 'recorded_at' => '2026-09-19 11:54:54',
        ]);
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        parent::tearDown();
    }

    private function send(User $by, ?ShiftSession $shift = null)
    {
        return $this->actingAs($by)->post(route('payments.assign-shift', $this->payment->id), ['shift_session_id' => ($shift ?? $this->orlaShift)->id, 'note' => 'Dana at Orla']);
    }

    public function test_management_sends_and_shift_owner_accepts(): void
    {
        // Supervisors do not see or hand out these payments.
        $this->actingAs($this->vera)->get(route('rentals.index'))->assertOk()->assertDontSee('Payments not in any shift');
        $this->send($this->vera)->assertForbidden();

        $this->actingAs($this->boss)->get(route('rentals.index'))->assertOk()
            ->assertSee('Payments not in any shift (1)')->assertSee('#'.$this->orlaShift->id.' Orlaadmin');

        $this->send($this->boss)->assertSessionHasNoErrors();
        $this->assertNull($this->payment->fresh()->shift_session_id, 'nothing moves before the owner accepts');
        $approval = RentalApprovalRequest::where('payload->kind', 'payment_assign')->firstOrFail();

        $this->actingAs($this->orla)->get(route('rentals.index'))->assertOk()
            ->assertSee('Payments sent to your shift (1)')->assertSee('Dana at Orla');
        // Management's own approval queue does not carry it (it is the shift owner's call).
        $this->actingAs($this->boss)->get(route('rentals.index'))->assertOk()->assertDontSee('Payments sent to your shift');

        $this->actingAs($this->orla)->post(route('payment-assign.accept', $approval))->assertSessionHasNoErrors();

        $payment = $this->payment->fresh();
        $this->assertSame($this->orlaShift->id, $payment->shift_session_id);
        $this->assertSame($this->orla->id, $payment->received_by_user_id);
        // Closed shift totals are recounted from its payment records (the 50.000 above was a stub).
        $this->assertEqualsWithDelta(415000, (float) $this->orlaShift->fresh()->total_revenue, 0.01);
        $this->assertSame('approved', $approval->fresh()->status);

        $log = RentalActivityLog::where('action', 'payment_assign_approved')->firstOrFail();
        $this->assertSame(['Vera', 'Orlaadmin'], $log->changes['received_by']);
        $this->assertSame([null, 'Boss'], $log->changes['requested_by']);
        $this->assertTrue(RentalActivityLog::where('action', 'payment_assign_requested')->where('user_id', $this->boss->id)->exists());
    }

    public function test_only_the_shift_owner_decides(): void
    {
        $this->send($this->boss)->assertSessionHasNoErrors();
        $approval = RentalApprovalRequest::where('payload->kind', 'payment_assign')->firstOrFail();

        $this->actingAs($this->asti)->post(route('payment-assign.accept', $approval))->assertSessionHasErrors('payment_assign');
        $this->actingAs($this->boss)->post(route('payment-assign.accept', $approval))->assertSessionHasErrors('payment_assign');
        $this->actingAs($this->boss)->post(route('approvals.rentals.approve', $approval))->assertSessionHasErrors('approval');
        $this->actingAs($this->boss)->post(route('approvals.rentals.reject', $approval))->assertSessionHasErrors('approval');
        $this->assertSame('pending', $approval->fresh()->status);
        $this->assertNull($this->payment->fresh()->shift_session_id);
    }

    public function test_reject_leaves_payment_unassigned_for_another_choice(): void
    {
        $this->send($this->boss)->assertSessionHasNoErrors();
        $approval = RentalApprovalRequest::where('payload->kind', 'payment_assign')->firstOrFail();
        $this->actingAs($this->orla)->post(route('payment-assign.reject', $approval))->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('rejected', $approval->fresh()->status);
        $this->assertNull($this->payment->fresh()->shift_session_id);
        $this->assertTrue(RentalActivityLog::where('action', 'payment_assign_rejected')->exists());
        // Can be sent again.
        $this->send($this->boss)->assertSessionHasNoErrors();
    }

    public function test_guards(): void
    {
        // Admins cannot send; a shift that was not open then (and is closed now) cannot be chosen.
        $this->actingAs($this->orla)->post(route('payments.assign-shift', $this->payment->id), ['shift_session_id' => $this->orlaShift->id])->assertForbidden();
        $old = ShiftSession::create(['opened_by_user_id' => $this->asti->id, 'opened_at' => '2026-09-10 08:00:00', 'period' => ShiftSession::PERIOD_MORNING]);
        $old->forceFill(['closed_at' => '2026-09-10 15:00:00'])->save();
        $this->send($this->boss, $old)->assertSessionHasErrors('payment_assign');
        // No sending to your own shift.
        $own = $this->openShiftAs($this->boss);
        $this->send($this->boss, $own)->assertSessionHasErrors('payment_assign');
        // One waiting request at a time.
        $this->send($this->boss)->assertSessionHasNoErrors();
        $this->send($this->boss)->assertSessionHasErrors('payment_assign');
    }

    public function test_requester_can_cancel(): void
    {
        $this->send($this->boss)->assertSessionHasNoErrors();
        $approval = RentalApprovalRequest::where('payload->kind', 'payment_assign')->firstOrFail();
        $this->actingAs($this->boss)->post(route('approvals.rentals.cancel', $approval))->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $approval->fresh()->status);
        $this->assertTrue(RentalActivityLog::where('action', 'payment_assign_cancelled')->exists());
    }

    public function test_accounting_can_send_from_the_accounting_dashboard(): void
    {
        $accounting = User::factory()->create(['role' => 'accounting', 'name' => 'Akunting']);
        $accounting->stores()->attach($this->store->id);

        $this->actingAs($accounting)->get(route('accounting.dashboard', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30']))
            ->assertOk()
            ->assertSee('Payments not in any shift (1)')
            ->assertSee('#'.$this->orlaShift->id.' Orlaadmin');

        $this->actingAs($accounting)->from(route('accounting.dashboard'))
            ->post(route('payments.assign-shift', $this->payment->id), ['shift_session_id' => $this->orlaShift->id])
            ->assertRedirect(route('accounting.dashboard'))
            ->assertSessionHasNoErrors();
        $this->assertTrue(RentalApprovalRequest::where('payload->kind', 'payment_assign')->where('requested_by_user_id', $accounting->id)->exists());
    }

    public function test_accounting_dashboard_filters_by_selected_dates_with_an_all_option(): void
    {
        $this->actingAs($this->boss)->get(route('accounting.dashboard', ['date_from' => '2026-09-01', 'date_to' => '2026-09-15']))
            ->assertOk()->assertSee('Payments not in any shift (0)')->assertSee('None in this date range.')->assertSee('All dates (1)');
        $this->actingAs($this->boss)->get(route('accounting.dashboard', ['date_from' => '2026-09-16', 'date_to' => '2026-09-30']))
            ->assertOk()->assertSee('Payments not in any shift (1)')->assertSee('DANA #');
        $this->actingAs($this->boss)->get(route('accounting.dashboard', ['date_from' => '2026-09-01', 'date_to' => '2026-09-15', 'pa_all' => 1]))
            ->assertOk()->assertSee('Payments not in any shift (1)');
    }
}
