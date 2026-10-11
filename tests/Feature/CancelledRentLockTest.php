<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * A cancelled rent is closed like a returned one: no payment, no edit, until management reactivates it.
 * (Overnight review 2026-10-09, finding P3: Pay on a cancelled DP rent recorded the money on a rent the books
 * treat as forfeited.)
 */
class CancelledRentLockTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $manager;

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
        $this->manager->stores()->attach($this->store->id);
        ShiftSession::create(['opened_by_user_id' => $this->manager->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
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

    /** DP rent: 300.000 bill, 100.000 paid. */
    private function dpRental(): Rental
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'C-'.uniqid(), 'price' => 300000, 'stock' => 1]);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Cancel Test', 'phone' => '0812',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 100000,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 300000, 'tax_amount' => 0]);
        RentalPaymentRecorder::recordIfNonZero($rental, 100000, null, null, 'cash', 'down_payment', totalDue: 300000.0);

        return $rental->fresh('outfits');
    }

    /** What the Pay button of the edit modal submits. */
    private function payPayload(Rental $r): array
    {
        return [
            'outfit_ids' => [$r->outfits->first()->id], 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'final_payment',
            'payment_entry' => 'increment', 'payment_amount_add' => '200000', 'pay_action' => '1',
            'discount' => '', 'discount_type' => 'fixed',
        ];
    }

    private function cancel(Rental $r): void
    {
        $this->as($this->manager)->postJson(route('rentals.status', $r), ['status' => 'cancelled'])->assertOk();
    }

    public function test_pay_on_a_cancelled_rent_is_refused_and_records_nothing(): void
    {
        $r = $this->dpRental();
        $this->cancel($r);
        $records = RentalPaymentRecord::where('rental_id', $r->id)->count();

        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->payPayload($r))->assertStatus(422);

        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertSame($records, RentalPaymentRecord::where('rental_id', $r->id)->count(), 'no payment record on a cancelled rent');
        $this->assertEquals(100000, (float) $r->fresh()->amount_paid);
    }

    public function test_saving_changes_on_a_cancelled_rent_is_refused(): void
    {
        $r = $this->dpRental();
        $this->cancel($r);

        $this->as($this->manager)->putJson(route('rentals.update', $r), array_merge($this->payPayload($r), [
            'pay_action' => null, 'payment_kind' => 'down_payment', 'payment_amount_add' => '0', 'notes' => 'changed after cancel',
        ]))->assertStatus(422);

        $this->assertNull($r->fresh()->notes);
    }

    public function test_pay_works_again_after_management_reactivates_the_rent(): void
    {
        $r = $this->dpRental();
        $this->cancel($r);
        $this->as($this->manager)->postJson(route('rentals.status', $r), ['status' => 'active'])->assertOk();

        $this->as($this->manager)->putJson(route('rentals.update', $r->fresh('outfits')), $this->payPayload($r))->assertOk();

        $this->assertEquals(300000, (float) $r->fresh()->amount_paid);
        $this->assertSame(2, RentalPaymentRecord::where('rental_id', $r->id)->count());
    }
}
