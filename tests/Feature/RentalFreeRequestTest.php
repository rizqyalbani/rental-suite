<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalFreeRequest;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Free rent: anyone who runs rents may ask, only a supervisor/management other
 * than the requester may approve, only while nothing was paid, and every step
 * (who asked, who decided, why, the old total) lands in the activity log.
 */
class RentalFreeRequestTest extends TestCase
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
        StoreContext::set($this->store->id);
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        parent::tearDown();
    }

    private function rent(int $paid = 0): Rental
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'FR'.Outfit::withoutGlobalScopes()->count(), 'price' => 300000, 'stock' => 1]);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'ENDORSE', 'phone' => '0812',
            'start_date' => now()->addDays(3)->toDateString(), 'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => $paid,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 300000, 'tax_amount' => 0]);
        if ($paid > 0) {
            RentalPaymentRecorder::recordIfNonZero($rental, $paid, null, null, 'cash', 'down_payment', totalDue: 300000.0);
        }

        return $rental->fresh('outfits');
    }

    private function ask(User $who, Rental $rental, string $reason = 'Endorse IG')
    {
        return $this->actingAs($who)->postJson(route('rentals.free-request', $rental), ['reason' => $reason]);
    }

    public function test_admin_request_then_supervisor_approves_and_everything_is_logged(): void
    {
        $rental = $this->rent();
        $this->ask($this->admin, $rental)->assertOk()->assertJson(['ok' => true]);

        // Nothing changes until approved.
        $this->assertEqualsWithDelta(300000, RentalFreeRequest::rentTotal($rental->fresh()), 0.01);
        $approval = RentalApprovalRequest::where('payload->kind', 'free')->firstOrFail();
        $this->assertSame('Endorse IG', $approval->payload['reason']);

        $this->actingAs($this->supervisor)->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();

        $rental = $rental->fresh('outfits');
        $this->assertEqualsWithDelta(0, RentalFreeRequest::rentTotal($rental), 0.01);
        $this->assertSame('FREE: Endorse IG', $rental->discount_note);
        $approval->refresh();
        $this->assertSame('approved', $approval->status);
        $this->assertSame($this->supervisor->id, $approval->decided_by_user_id);

        $requested = RentalActivityLog::where('rental_id', $rental->id)->where('action', 'free_requested')->firstOrFail();
        $this->assertSame($this->admin->id, $requested->user_id);
        $approved = RentalActivityLog::where('rental_id', $rental->id)->where('action', 'free_approved')->firstOrFail();
        $this->assertSame($this->supervisor->id, $approved->user_id);
        $this->assertSame([null, 'Admin Orla'], $approved->changes['requested_by']);
        $this->assertSame([null, 'Sup Vera'], $approved->changes['approved_by']);
        $this->assertEquals([300000, 0], $approved->changes['rent_total']);
    }

    public function test_requester_cannot_approve_own_request_even_as_management(): void
    {
        $rental = $this->rent();
        $this->ask($this->manager, $rental)->assertOk();
        $approval = RentalApprovalRequest::where('payload->kind', 'free')->firstOrFail();

        $this->actingAs($this->manager)->post(route('approvals.rentals.approve', $approval))->assertSessionHasErrors('approval');
        $this->assertSame('pending', $approval->fresh()->status);

        $this->actingAs($this->supervisor)->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();
        $this->assertSame('approved', $approval->fresh()->status);
    }

    public function test_paid_rent_is_refunded_and_the_refund_is_marked_free(): void
    {
        $rental = $this->rent(100000);
        $this->ask($this->admin, $rental)->assertOk();
        // More money arrives while the request waits: the refund covers it too.
        RentalPaymentRecorder::recordIfNonZero($rental, 50000, null, null, 'transfer', 'down_payment', totalDue: 300000.0);
        $approval = RentalApprovalRequest::where('payload->kind', 'free')->firstOrFail();

        $this->actingAs($this->manager)->post(route('approvals.rentals.approve', $approval))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Hand Rp 150.000 back'));

        $approval->refresh();
        $this->assertSame('approved', $approval->status);
        $this->assertEquals(150000, $approval->payload['refund_amount']);
        $entry = JournalEntry::withoutGlobalScopes()->findOrFail($approval->payload['refund_journal_entry_id']);
        $this->assertSame('rental_refund', $entry->reference_type);
        $this->assertStringStartsWith('FREE', $entry->memo);
        // Dr Rental Refunds 150.000 / Cr Cash 100.000 + Bank 50.000 (as it was paid).
        $lines = $entry->lines()->with('account')->get();
        $this->assertEqualsWithDelta(150000, $lines->firstWhere('account.code', '4910')->debit, 0.01);
        $this->assertEqualsWithDelta(100000, $lines->firstWhere('account.code', '1100')->credit, 0.01);
        $this->assertEqualsWithDelta(50000, $lines->firstWhere('account.code', '1200')->credit, 0.01);

        $log = RentalActivityLog::where('rental_id', $rental->id)->where('action', 'free_approved')->firstOrFail();
        $this->assertEquals([null, 150000], $log->changes['free_refund_amount']);
        $this->assertStringContainsString('refund FREE journal #'.$entry->id, $log->note);
        $this->assertSame(0.0, RentalFreeRequest::refundDue($rental->fresh()));
        // Contract side follows: nothing left owed or held for this rent.
        $this->assertNull(\App\Support\RentalLedgerSync::evaluate($rental->fresh()));

        $today = now()->toDateString();
        $this->actingAs($this->manager)->get(route('accounting.dashboard', ['date_from' => $today, 'date_to' => $today]))
            ->assertOk()
            ->assertSee('refunded because of FREE (1 rental) Rp 150.000', false)
            ->assertSee('refunded Rp 150.000 to the customer', false);
    }

    public function test_reason_is_required_and_duplicate_request_is_refused(): void
    {
        $rental = $this->rent();
        $this->ask($this->admin, $rental, '')->assertStatus(422);
        $this->ask($this->admin, $rental)->assertOk();
        $this->ask($this->admin, $rental)->assertStatus(422);
    }

    public function test_reject_and_cancel_are_logged(): void
    {
        $rental = $this->rent();
        $this->ask($this->admin, $rental)->assertOk();
        $approval = RentalApprovalRequest::where('payload->kind', 'free')->firstOrFail();
        $this->actingAs($this->manager)->post(route('approvals.rentals.reject', $approval), ['decision_note' => 'Not endorsed'])->assertSessionHasNoErrors();
        $rejected = RentalActivityLog::where('rental_id', $rental->id)->where('action', 'free_rejected')->firstOrFail();
        $this->assertSame([null, 'Boss Rental Suite'], $rejected->changes['rejected_by']);
        $this->assertStringContainsString('Not endorsed', $rejected->note);
        $this->assertEqualsWithDelta(300000, RentalFreeRequest::rentTotal($rental->fresh()), 0.01);

        $this->ask($this->admin, $rental)->assertOk();
        $second = RentalApprovalRequest::where('payload->kind', 'free')->where('status', 'pending')->firstOrFail();
        $this->actingAs($this->admin)->post(route('approvals.rentals.cancel', $second))->assertSessionHasNoErrors();
        $this->assertTrue(RentalActivityLog::where('rental_id', $rental->id)->where('action', 'free_cancelled')->exists());
    }

    public function test_pending_card_shows_on_edit_requests_page(): void
    {
        $rental = $this->rent();
        $this->ask($this->admin, $rental)->assertOk();

        $this->actingAs($this->supervisor)->get(route('approvals.edit-requests.index'))
            ->assertOk()
            ->assertSee('Free rent request')
            ->assertSee('Endorse IG')
            ->assertSee('Approve free');
        $this->actingAs($this->admin)->get(route('my-requests.index'))->assertOk()->assertSee('Free rent');
    }

    public function test_admin_keeps_operating_a_rent_while_free_is_pending_and_after_it_is_free(): void
    {
        $rental = $this->rent(100000);
        $this->ask($this->admin, $rental)->assertOk();

        // While waiting the rent is still unpaid, so the usual "pay before pickup" rule holds.
        $this->actingAs($this->admin)->postJson(route('rentals.pickup', $rental), ['picked_up' => true])->assertStatus(422);

        $approval = RentalApprovalRequest::where('payload->kind', 'free')->firstOrFail();
        $this->actingAs($this->supervisor)->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();

        // After free: nothing is owed, so admin can hand it over and take it back normally.
        $rental = $rental->fresh('outfits');
        $this->assertSame(0.0, \App\Support\RentalLedgerSync::balanceAsOf($rental, now()->toDateString())['remaining']);
        $this->actingAs($this->admin)->postJson(route('rentals.pickup', $rental), ['picked_up' => true])->assertOk();
        $this->assertNotNull($rental->fresh()->picked_up_at);
        $this->actingAs($this->admin)->postJson(route('rentals.status', $rental), ['status' => 'returned'])->assertOk();
        $this->assertSame('returned', $rental->fresh()->status);

        // The rents list still loads for admin with this rent in it.
        $this->actingAs($this->admin)->getJson(route('rentals.search', ['id' => $rental->id]))
            ->assertOk()
            ->assertJsonPath('data.0.id', $rental->id);
    }

    public function test_normal_rent_is_untouched_by_the_free_feature(): void
    {
        // The free feature must not touch the ordinary rents: a normal unpaid rent keeps its price.
        $rental = $this->rent();
        $this->actingAs($this->admin)->postJson(route('rentals.pickup', $rental), ['picked_up' => true])
            ->assertStatus(422)->assertJsonFragment(['message' => 'Cannot mark as picked up: not fully paid yet — Rp 300.000 still to collect (edit the rent and record the payment).']);
        $this->assertEqualsWithDelta(300000, RentalFreeRequest::rentTotal($rental->fresh()), 0.01);
        $this->assertFalse(RentalApprovalRequest::where('payload->kind', 'free')->exists());
    }

    public function test_free_rent_shows_a_free_badge_with_who_and_why_in_the_rents_list(): void
    {
        $rental = $this->rent();
        $normal = $this->rent();
        $this->ask($this->admin, $rental)->assertOk();
        $approval = RentalApprovalRequest::where('payload->kind', 'free')->firstOrFail();
        $this->actingAs($this->supervisor)->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();

        $json = $this->actingAs($this->admin)->getJson(route('rentals.search'))->assertOk()->json('data');
        $row = collect($json)->firstWhere('id', $rental->id);
        $this->assertSame('Sup Vera', $row['free']['approved_by']);
        $this->assertSame('Admin Orla', $row['free']['requested_by']);
        $this->assertStringContainsString('>FREE</span>', $row['card_html']);
        $this->assertStringContainsString('approved by <span class="font-medium">Sup Vera</span>', $row['card_html']);
        $this->assertStringContainsString('Reason: Endorse IG', $row['card_html']);

        $plain = collect($json)->firstWhere('id', $normal->id);
        $this->assertNull($plain['free']);
        $this->assertStringNotContainsString('>FREE</span>', $plain['card_html']);
    }
}
