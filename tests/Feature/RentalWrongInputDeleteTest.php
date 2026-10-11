<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalPaymentRecorder;
use App\Support\ShiftClosedTotals;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Scenario: a paid rent typed in by mistake. Cancel-with-refund kept it
 * in the shift report, delete was blocked. Now it is deleted as "wrong input":
 * management directly, a supervisor through management, never admin — and
 * every payment and cancellation entry is reversed, even after repeated
 * cancel/reopen.
 */
class RentalWrongInputDeleteTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $asti;

    private User $vera;

    private User $boss;

    private User $boss2;

    private ShiftSession $shift;

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
        $this->asti = User::factory()->create(['role' => 'admin', 'name' => 'Ann']);
        $this->vera = User::factory()->create(['role' => 'supervisor', 'name' => 'Vera']);
        $this->boss = User::factory()->create(['role' => 'management', 'name' => 'Demo Store Twogrup']);
        $this->boss2 = User::factory()->create(['role' => 'management', 'name' => 'Owner2']);
        foreach ([$this->asti, $this->vera, $this->boss, $this->boss2] as $u) {
            $u->stores()->attach($this->store->id);
        }
        StoreContext::set($this->store->id);
        $this->shift = $this->openShiftAs($this->asti);
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        parent::tearDown();
    }

    private function paidRent(): Rental
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'JBL'.Outfit::withoutGlobalScopes()->count(), 'price' => 300000, 'stock' => 1]);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'SAMPLE', 'phone' => '0878',
            'start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'final_payment', 'amount_paid' => 300000,
            'shift_session_id' => $this->shift->id,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 300000, 'tax_amount' => 0]);
        RentalPaymentRecorder::recordIfNonZero($rental, 75000, $this->shift->id, $this->asti->id, 'cash', 'down_payment', totalDue: 300000.0);
        RentalPaymentRecorder::recordIfNonZero($rental, 225000, $this->shift->id, $this->asti->id, 'cash', 'final_payment', totalDue: 300000.0);

        return $rental->fresh('outfits');
    }

    private function del(User $who, Rental $rental, ?string $reason = null)
    {
        return $this->actingAs($who)->deleteJson(route('rentals.destroy', $rental), $reason === null ? [] : ['wrong_input' => 1, 'reason' => $reason]);
    }

    /** Cash (1100) for one rent across every journal that names it or its payments. */
    private function kasFor(Rental $rental): float
    {
        $kas = Account::where('code', '1100')->value('id');
        $entryIds = JournalEntry::withoutGlobalScopes()->where(function ($q) use ($rental) {
            $q->where(fn ($w) => $w->where('reference_type', 'rental_payment')->whereIn('reference_id', \App\Models\RentalPaymentRecord::where('rental_id', $rental->id)->pluck('id')))
                ->orWhere(fn ($w) => $w->whereIn('reference_type', ['rental_cancellation', 'rental_adjustment', 'rental_refund'])->where('reference_id', $rental->id));
        })->pluck('id');
        $reversalIds = JournalEntry::withoutGlobalScopes()->whereIn('reverses_journal_entry_id', $entryIds)->pluck('id');

        return (float) JournalEntryLine::whereIn('journal_entry_id', $entryIds->merge($reversalIds)->unique())
            ->where('account_id', $kas)->sum(\DB::raw('debit - credit'));
    }

    public function test_paid_rent_delete_is_refused_with_a_wrong_input_hint(): void
    {
        $rental = $this->paidRent();
        $this->del($this->boss, $rental)->assertStatus(422)->assertJson(['needs_wrong_input' => true, 'paid' => 300000]);
        $this->del($this->asti, $rental)->assertStatus(422)->assertJson(['needs_wrong_input' => false]);
        // Admin cannot force it either.
        $this->del($this->asti, $rental, 'wrong input')->assertStatus(422);
        $this->del($this->boss, $rental, '')->assertStatus(422);
        $this->assertNotSoftDeleted($rental);
    }

    public function test_management_deletes_wrong_input_after_repeated_cancel_refund_and_everything_reverses(): void
    {
        $rental = $this->paidRent();
        // Like #101: cancelled with refund, reopened, cancelled with refund again.
        foreach ([['cancelled', true], ['active', false], ['cancelled', true]] as [$status, $refund]) {
            $this->actingAs($this->boss)->postJson(route('rentals.status', $rental), array_filter(['status' => $status, 'refund' => $refund ? 1 : null]))->assertOk();
        }
        $this->assertEqualsWithDelta(300000, ShiftClosedTotals::calculate($this->shift->fresh())['received'], 0.01, 'the bug: refunded money still counted');

        $this->del($this->boss, $rental, 'Entered twice, duplicate of #102')->assertOk()->assertJson(['ok' => true]);

        $this->assertSoftDeleted($rental);
        $this->assertEqualsWithDelta(0, ShiftClosedTotals::calculate($this->shift->fresh())['received'], 0.01);
        $this->assertEqualsWithDelta(0, $this->kasFor($rental), 0.01);
        $log = RentalActivityLog::where('rental_id', $rental->id)->where('action', 'deleted')->firstOrFail();
        $this->assertSame([null, 'Wrong input: Entered twice, duplicate of #102'], $log->changes['delete_reason']);
        $this->assertSame($this->boss->id, $log->user_id);
    }

    public function test_supervisor_requests_and_only_another_manager_approves(): void
    {
        $rental = $this->paidRent();
        $this->del($this->vera, $rental, 'Entered twice')->assertOk()->assertJson(['mode' => 'delete_approval_request_sent']);
        $this->assertNotSoftDeleted($rental);
        $approval = RentalApprovalRequest::where('payload->kind', 'delete')->firstOrFail();
        $this->assertTrue((bool) $approval->payload['wrong_input']);

        // A supervisor (even another one) cannot approve it.
        $this->actingAs($this->vera)->post(route('approvals.rentals.approve', $approval))->assertSessionHasErrors('approval');
        $this->assertNotSoftDeleted($rental);

        $this->actingAs($this->boss)->get(route('approvals.edit-requests.index'))->assertOk()->assertSee('Wrong input')->assertSee('Entered twice');
        $this->actingAs($this->boss)->post(route('approvals.rentals.approve', $approval))->assertSessionHasNoErrors();

        $this->assertSoftDeleted($rental);
        $this->assertEqualsWithDelta(0, ShiftClosedTotals::calculate($this->shift->fresh())['received'], 0.01);
        $this->assertEqualsWithDelta(0, $this->kasFor($rental), 0.01);
        $log = RentalActivityLog::where('rental_id', $rental->id)->where('action', 'delete_approved')->firstOrFail();
        $this->assertSame([null, 'Vera'], $log->changes['requested_by']);
        $this->assertSame([null, 'Demo Store Twogrup'], $log->changes['approved_by']);
    }

    public function test_unpaid_rent_delete_is_unchanged(): void
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'U1', 'price' => 100000, 'stock' => 1]);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'UNPAID', 'phone' => '0879',
            'start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(7)->toDateString(), 'status' => 'active',
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 100000, 'price_excl_tax' => 100000, 'tax_amount' => 0]);
        $this->del($this->boss, $rental)->assertOk();
        $this->assertSoftDeleted($rental);
    }
}
