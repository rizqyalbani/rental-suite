<?php

namespace Tests\Repro;

use App\Models\Account;
use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\TutorialRental;
use App\Models\User;
use App\Support\RentalFinancialSummary;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Throwaway repros for the C1 overnight review (docs/overnight-2026-10-09/03a-payments-review.md).
 * Each test asserts the CURRENT (suspected wrong) behaviour, so a passing test = bug confirmed.
 * Scenario numbers: 2 items x Rp 1.100.000 incl. PPN 10%, 9 days = 3 blocks => bill 6.600.000; DP 30% = 1.980.000.
 */
class PaymentsC1ReproTest extends TestCase
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
        $this->manager->stores()->attach($this->store->id);
        ShiftSession::create(['opened_by_user_id' => $this->admin->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        StoreContext::set($this->store->id);
        StoreCache::forever('pricing_tax_percent', 10);
        StoreCache::forever('pricing_prices_include_tax', true);
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

    /** 2 outfits @ 1.100.000 incl., 9 calendar days. */
    private function rental(string $kind = 'down_payment', float $paid = 1980000, int $startOffset = 1, array $extra = []): Rental
    {
        $start = now()->addDays($startOffset)->startOfDay();
        $rental = Rental::create(array_merge([
            'store_id' => $this->store->id, 'customer_name' => 'Repro', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(8)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => $kind, 'amount_paid' => $paid,
        ], $extra));
        foreach ([1, 2] as $i) {
            $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'R'.$i.'-'.uniqid(), 'price' => 1100000, 'stock' => 1]);
            $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 1100000, 'price_excl_tax' => 1000000, 'tax_amount' => 100000]);
        }
        $rental->load('outfits');
        RentalPaymentRecorder::recordIfNonZero($rental, $paid, null, null, 'cash', $kind, totalDue: 6600000.0);

        return $rental->fresh('outfits');
    }

    private function bill(Rental $r): float
    {
        return (float) RentalFinancialSummary::summarize($r->fresh('outfits'), 10)['net_incl_tax'];
    }

    private function recorded(Rental $r): float
    {
        return (float) RentalPaymentRecord::where('rental_id', $r->id)->sum('amount');
    }

    /** What the edit modal posts, unchanged rent. */
    private function base(Rental $r, array $override = []): array
    {
        $p = [
            'outfit_ids' => $r->outfits->pluck('id')->all(), 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => $r->payment_method, 'payment_kind' => $r->payment_kind,
            'discount' => $r->discount !== null ? (string) $r->discount : '', 'discount_type' => $r->discount_type ?? 'fixed',
            'discount_note' => $r->discount_note,
            'payment_entry' => 'increment', 'payment_amount_add' => '0',
        ];
        foreach ($r->outfits as $s) {
            $p['item_discounts'][$s->id] = '';
            $p['item_discount_types'][$s->id] = 'fixed';
        }

        return array_merge($p, $override);
    }

    // P1 (FIXED): overdue "change" is cash handed BACK, so it never counts as rent paid.
    public function test_FIXED_p1_overdue_change_is_not_rent_paid_and_return_waits_for_the_balance(): void
    {
        $r = $this->rental('down_payment', 1980000, -10); // ended 2 days ago
        $end = $r->end_date->toDateString();
        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'overdue_fee_amount' => '200000', 'overdue_fee_rate' => '100000', 'overdue_fee_days' => '2',
            'overdue_base_end_date' => $end, 'overdue_charge_through_date' => $r->end_date->copy()->addDays(2)->toDateString(),
        ]))->assertOk();
        $r = $r->fresh('outfits');
        $this->assertEqualsWithDelta(200000, (float) $r->overdue_fee_amount, 0.01);

        // Staff types the whole cash received (rent balance 4.620.000 + fine 200.000) into overdue "Amount received".
        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'payment_entry' => 'replace', 'amount_paid' => '1980000', 'payment_amount_add' => null,
            'overdue_payment_entry' => 'increment', 'overdue_payment_amount_add' => '4820000', 'overdue_payment_method' => 'cash',
        ]))->assertOk()->assertJsonPath('overdue_payment_change', 4620000);
        $r = $r->fresh('outfits');
        $this->assertEqualsWithDelta(6800000, $this->bill($r), 0.01);
        $this->assertEqualsWithDelta(2180000, $this->recorded($r), 0.01);   // only DP + fine recorded
        $this->assertEqualsWithDelta(4620000, (float) $r->overdue_change_amount, 0.01);

        // Return is refused: 4.620.000 of rent is still unpaid.
        $r->forceFill(['picked_up_at' => now()->subDays(9)])->save();
        $this->as($this->manager)->postJson(route('rentals.status', $r), ['status' => 'returned'])->assertStatus(422);
        $this->assertSame('active', $r->fresh()->status);

        // The Pay button for the real rent balance is NOT frozen any more and records the money.
        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'payment_amount_add' => '4620000', 'pay_action' => '1',
        ]))->assertOk();
        $this->assertEqualsWithDelta(6600000, (float) $r->fresh()->amount_paid, 0.01);
        $this->assertEqualsWithDelta(6800000, $this->recorded($r), 0.01);

        // Now fully paid: Return is allowed.
        $this->as($this->manager)->postJson(route('rentals.status', $r), ['status' => 'returned'])->assertOk();
        $this->assertSame('returned', $r->fresh()->status);
    }

    // Control for P1: a real small change on the fine (rent fully paid, 250.000 given for a 200.000 fine) still lets Return through.
    public function test_p1_control_small_overdue_change_with_rent_fully_paid_allows_return(): void
    {
        $r = $this->rental('full', 6600000, -10);
        $end = $r->end_date->toDateString();
        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'overdue_fee_amount' => '200000', 'overdue_fee_rate' => '100000', 'overdue_fee_days' => '2',
            'overdue_base_end_date' => $end, 'overdue_charge_through_date' => $r->end_date->copy()->addDays(2)->toDateString(),
        ]))->assertOk();
        $r = $r->fresh('outfits');
        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'overdue_payment_entry' => 'increment', 'overdue_payment_amount_add' => '250000', 'overdue_payment_method' => 'cash',
        ]))->assertOk()->assertJsonPath('overdue_payment_change', 50000);
        $r = $r->fresh('outfits');
        $this->assertEqualsWithDelta(6800000, $this->recorded($r), 0.01);

        $r->forceFill(['picked_up_at' => now()->subDays(9)])->save();
        $this->as($this->manager)->postJson(route('rentals.status', $r), ['status' => 'returned'])->assertOk();
        $this->assertSame('returned', $r->fresh()->status);
    }

    // P2 (FIXED, D3 = A): a rent keeps the price it was booked at; a catalog change never re-prices it.
    public function test_FIXED_p2a_notes_only_save_keeps_the_booked_price(): void
    {
        $r = $this->rental();
        $this->assertEqualsWithDelta(6600000, $this->bill($r), 0.01);
        Outfit::withoutGlobalScopes()->whereIn('id', $r->outfits->pluck('id'))->update(['price' => 1320000]);

        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, ['notes' => 'just a note']))->assertOk();

        $this->assertEqualsWithDelta(1100000, (float) $r->fresh('outfits')->outfits->first()->pivot->price_incl_tax, 0.01);
        $this->assertEqualsWithDelta(6600000, $this->bill($r), 0.01);
    }

    public function test_FIXED_p2b_price_drop_then_notes_save_on_paid_rent_books_no_refund(): void
    {
        $r = $this->rental('full', 6600000);
        Outfit::withoutGlobalScopes()->whereIn('id', $r->outfits->pluck('id'))->update(['price' => 990000]);

        $res = $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, ['notes' => 'phone fixed']))->assertOk();

        $this->assertEqualsWithDelta(6600000, $this->bill($r), 0.01);
        $this->assertNull($res->json('refund'));
    }

    public function test_FIXED_p2c_price_rise_does_not_block_a_save_on_paid_full_rent(): void
    {
        $r = $this->rental('full', 6600000);
        Outfit::withoutGlobalScopes()->whereIn('id', $r->outfits->pluck('id'))->update(['price' => 1320000]);

        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, ['notes' => 'phone fixed']))->assertOk();

        $this->assertEqualsWithDelta(6600000, $this->bill($r), 0.01);
    }

    // P3 (FIXED 70b12be): Pay on a cancelled rent is refused; the rent stays cancelled and nothing is recorded.
    public function test_FIXED_p3_payment_refused_on_cancelled_rent(): void
    {
        $r = $this->rental();
        $this->as($this->manager)->postJson(route('rentals.status', $r), ['status' => 'cancelled'])->assertOk();
        $r = $r->fresh('outfits');
        $this->assertSame('cancelled', $r->status);
        $recordedBefore = $this->recorded($r);

        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'payment_amount_add' => '4620000', 'pay_action' => '1',
        ]))->assertStatus(422);

        $this->assertSame('cancelled', $r->fresh()->status);
        $this->assertEqualsWithDelta($recordedBefore, $this->recorded($r), 0.01, 'no payment recorded on a cancelled rent');
    }

    // P4 (FIXED by WP1): item removal on a practice (tutorial) rent files a PRACTICE request, never a real one for rent #same-id.
    public function test_FIXED_p4_tutorial_item_removal_files_a_practice_request_only(): void
    {
        $real = $this->rental(); // real rentals.id = 1
        $t = TutorialRental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 100000,
            'created_by_user_id' => $this->manager->id, 'is_tutorial' => true,
        ]);
        $this->assertSame($real->id, $t->id);
        $ids = [];
        foreach ([1, 2] as $i) {
            $s = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'T'.$i.'-'.uniqid(), 'price' => 300000, 'stock' => 1, 'is_tutorial' => true]);
            $t->outfits()->attach($s->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 272727.27, 'tax_amount' => 27272.73]);
            $ids[] = $s->id;
        }
        $t = $t->fresh('outfits');

        $payload = $this->base($t, ['outfit_ids' => [$ids[0]]]);
        $this->as($this->manager)->putJson(route('rentals.update', ['rental' => $t->id, 'tutorial' => 1]), $payload)->assertOk();

        $this->assertNull(
            RentalApprovalRequest::query()->where('payload->kind', 'item_removal')->first(),
            'nothing landed in the REAL rental_approval_requests table'
        );
        $req = \App\Models\TutorialRentalApprovalRequest::query()->where('payload->kind', 'item_removal')->first();
        $this->assertNotNull($req, 'the request landed in the practice table');
        $this->assertSame($t->id, (int) $req->payload['rental_id']);
        $this->assertSame(2, $real->fresh('outfits')->outfits->count(), 'the real rent keeps its items');
    }

    // P4b (FIXED by WP1): "wrong input" delete of a PRACTICE rent (supervisor) never touches REAL rent #same-id.
    public function test_FIXED_p4b_tutorial_wrong_input_delete_leaves_the_real_rent(): void
    {
        $real = $this->rental(); // real rentals.id = 1, paid 1.980.000
        $sup = User::factory()->create(['role' => 'supervisor']);
        $sup->stores()->attach($this->store->id);
        $t = TutorialRental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 100000,
            'created_by_user_id' => $sup->id, 'is_tutorial' => true,
        ]);
        $this->assertSame($real->id, $t->id);

        $this->as($sup)->deleteJson(route('rentals.destroy', ['rental' => $t->id, 'tutorial' => 1]), ['wrong_input' => 1, 'reason' => 'practice run'])
            ->assertOk();

        $this->assertNull(
            RentalApprovalRequest::query()->where('payload->kind', 'delete')->first(),
            'no REAL delete request exists for a practice delete'
        );
        $this->assertNotNull(Rental::find($real->id), 'the REAL rent is still there');
    }

    // P5 (FIXED): a discount TYPE change (10% -> Rp 10) is a bill change, so a stale "balance riding along" Save is not recorded as paid.
    public function test_FIXED_p5_discount_type_only_change_does_not_record_riding_balance(): void
    {
        $r = $this->rental('down_payment', 1782000, 1, ['discount' => 10, 'discount_type' => 'percent', 'discount_note' => 'promo']);
        $this->assertEqualsWithDelta(5940000, $this->bill($r), 0.01);

        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'discount' => '10', 'discount_type' => 'fixed', 'payment_amount_add' => '4158000', // no pay_action
        ]))->assertOk();

        $this->assertEqualsWithDelta(1782000, $this->recorded($r), 0.01);
    }

    // P5 (FIXED), item level: an item discount type change (10% -> Rp 10) with a riding balance is not recorded either.
    public function test_FIXED_p5_item_discount_type_only_change_does_not_record_riding_balance(): void
    {
        $r = $this->rental('down_payment', 1980000);
        $item = $r->outfits->first();
        $r->outfits()->updateExistingPivot($item->id, ['discount' => 10, 'discount_type' => 'percent', 'discount_note' => 'promo']);
        $r = $r->fresh('outfits');

        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'item_discounts' => [$item->id => '10'], 'item_discount_types' => [$item->id => 'fixed'],
            'item_discount_notes' => [$item->id => 'promo'], 'payment_amount_add' => '4000000', // no pay_action
        ]))->assertOk();

        $this->assertEqualsWithDelta(1980000, $this->recorded($r), 0.01);
    }

    // Control for P5: nothing changed except the Pay button's own payment, so the payment IS recorded (discount type untouched).
    public function test_p5_control_pay_with_unchanged_discounts_is_recorded(): void
    {
        $r = $this->rental('down_payment', 1782000, 1, ['discount' => 10, 'discount_type' => 'percent', 'discount_note' => 'promo']);
        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'payment_amount_add' => '4158000', 'pay_action' => '1',
        ]))->assertOk();
        $this->assertEqualsWithDelta(5940000, $this->recorded($r), 0.01);
    }

    // Control for P5: changing the discount VALUE is caught.
    public function test_p5_control_discount_value_change_is_not_recorded(): void
    {
        $r = $this->rental('down_payment', 1782000, 1, ['discount' => 10, 'discount_type' => 'percent', 'discount_note' => 'promo']);
        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'discount' => '15', 'payment_amount_add' => '4158000',
        ]))->assertOk();
        $this->assertEqualsWithDelta(1782000, $this->recorded($r), 0.01);
    }

    // Guard check: two identical Pay requests in a row record the balance once.
    public function test_double_pay_sequential_records_once(): void
    {
        $r = $this->rental();
        $p = $this->base($r, ['payment_amount_add' => '4620000', 'pay_action' => '1']);
        $this->as($this->manager)->putJson(route('rentals.update', $r), $p)->assertOk();
        $this->as($this->manager)->putJson(route('rentals.update', $r), $p)->assertOk();
        $this->assertEqualsWithDelta(6600000, $this->recorded($r), 0.01);
        $this->assertEqualsWithDelta(6600000, (float) $r->fresh()->amount_paid, 0.01);
    }

    // P6 (FIXED aca69de): an outfit id from ANOTHER store used to pass `exists:outfits,id` and was priced Rp 0; it is now rejected.
    public function test_FIXED_p6_other_store_outfit_is_rejected_on_create(): void
    {
        $other = Store::create(['name' => 'Other', 'code' => 'other-store', 'is_active' => true]);
        $mine = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'M-'.uniqid(), 'price' => 1100000, 'stock' => 1]);
        $foreign = Outfit::withoutGlobalScopes()->create(['store_id' => $other->id, 'code' => 'F-'.uniqid(), 'price' => 1100000, 'stock' => 1]);
        $start = now()->addDay()->startOfDay();

        $this->as($this->admin)->postJson(route('rentals.store'), [
            'outfit_ids' => [$mine->id, $foreign->id], 'customer_name' => 'X', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(8)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '3300000',
        ])->assertStatus(422)->assertJsonValidationErrors(['outfit_ids.1']);

        $this->assertSame(0, Rental::withoutGlobalScopes()->count(), 'no rent (and no free 2nd outfit) is booked');
    }

    // P7: extending a fully paid 'full' rent by Save is refused with a "pay in full" message.
    public function test_p7_extend_paid_full_rent_by_save_is_refused(): void
    {
        $r = $this->rental('full', 6600000);
        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'end_date' => $r->end_date->copy()->addDays(3)->toDateString(),
        ]))->assertStatus(422)->assertJsonValidationErrors('amount_paid');
    }
}
