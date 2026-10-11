<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalFinancialSummary;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decision D3 = A (finding P2): a rent keeps the unit price each item was booked at.
 * A catalog price change must not re-price an existing rent on Save, Pay or an approved
 * edit; only an item added later is priced from the catalog at that moment.
 * Scenario: 2 items x Rp 1.100.000 incl. PPN 10%, 9 days = 3 blocks => bill 6.600.000.
 */
class RentalPriceLockTest extends TestCase
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

    private function outfit(int $price = 1100000): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'PL-'.uniqid(), 'price' => $price, 'stock' => 1]);
    }

    /** 2 outfits booked at 1.100.000 incl.; 9 calendar days = 3 blocks unless $days says otherwise. */
    private function rental(string $kind = 'down_payment', float $paid = 1980000, int $days = 9): Rental
    {
        $start = now()->addDays(1)->startOfDay();
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Lock', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => $kind, 'amount_paid' => $paid,
        ]);
        foreach ([1, 2] as $i) {
            $rental->outfits()->attach($this->outfit()->id, ['price_incl_tax' => 1100000, 'price_excl_tax' => 1000000, 'tax_amount' => 100000]);
        }
        $rental->load('outfits');
        RentalPaymentRecorder::recordIfNonZero($rental, $paid, null, null, 'cash', $kind, totalDue: 2200000.0 * ($days / 3));

        return $rental->fresh('outfits');
    }

    private function setCatalogPrice(Rental $r, int $price): void
    {
        Outfit::withoutGlobalScopes()->whereIn('id', $r->outfits->pluck('id'))->update(['price' => $price]);
    }

    private function bill(Rental $r): float
    {
        return (float) RentalFinancialSummary::summarize($r->fresh('outfits'), 10)['net_incl_tax'];
    }

    private function recorded(Rental $r): float
    {
        return (float) RentalPaymentRecord::where('rental_id', $r->id)->sum('amount');
    }

    private function pivotPrice(Rental $r, int $outfitId): float
    {
        return (float) $r->fresh('outfits')->outfits->firstWhere('id', $outfitId)->pivot->price_incl_tax;
    }

    /** What the edit modal posts for an unchanged rent. */
    private function base(Rental $r, array $override = []): array
    {
        $p = [
            'outfit_ids' => $r->outfits->pluck('id')->all(), 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => $r->payment_method, 'payment_kind' => $r->payment_kind,
            'discount' => '', 'discount_type' => 'fixed',
            'payment_entry' => 'increment', 'payment_amount_add' => '0',
        ];
        foreach ($r->outfits as $s) {
            $p['item_discounts'][$s->id] = '';
            $p['item_discount_types'][$s->id] = 'fixed';
        }

        return array_merge($p, $override);
    }

    /** An edit request as the admin flow stores it, decided by management. */
    private function approveEdit(Rental $r, array $outfitIds, array $rentalOverrides = []): \Illuminate\Testing\TestResponse
    {
        $approval = RentalApprovalRequest::create([
            'store_id' => $this->store->id,
            'requested_by_user_id' => $this->admin->id,
            'payload' => [
                'kind' => 'edit',
                'rental_id' => $r->id,
                'rental' => array_merge([
                    'customer_name' => $r->customer_name, 'phone' => $r->phone,
                    'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
                    'status' => 'active', 'payment_method' => $r->payment_method, 'payment_kind' => $r->payment_kind,
                    'amount_paid' => (float) $r->amount_paid, 'discount' => null, 'discount_type' => 'fixed',
                ], $rentalOverrides),
                'outfit_ids' => $outfitIds,
                'item_discounts' => [], 'item_discount_types' => [], 'item_discount_notes' => [], 'takeover_from' => [],
            ],
            'status' => 'pending',
            'is_tutorial' => false,
        ]);

        return $this->as($this->manager)->postJson(route('approvals.rentals.approve', $approval));
    }

    // --- Save (RentalController::update) ---------------------------------------------------

    public function test_notes_only_save_after_a_catalog_rise_keeps_the_booked_price(): void
    {
        $r = $this->rental();
        $this->setCatalogPrice($r, 1320000);

        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, ['notes' => 'just a note']))->assertOk();

        $this->assertEqualsWithDelta(6600000, $this->bill($r), 0.01);
        $this->assertEqualsWithDelta(1100000, $this->pivotPrice($r, $r->outfits->first()->id), 0.01);
        $this->assertEqualsWithDelta(1000000, (float) $r->fresh('outfits')->outfits->first()->pivot->price_excl_tax, 0.01);
    }

    public function test_save_after_a_catalog_drop_on_a_paid_rent_books_no_refund(): void
    {
        $r = $this->rental('full', 6600000);
        $this->setCatalogPrice($r, 990000);

        $res = $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, ['notes' => 'phone fixed']))->assertOk();

        $this->assertNull($res->json('refund'));
        $this->assertEqualsWithDelta(6600000, $this->bill($r), 0.01);
        $this->assertEqualsWithDelta(6600000, $this->recorded($r), 0.01);
    }

    public function test_save_after_a_catalog_rise_is_not_blocked_on_a_paid_full_rent(): void
    {
        $r = $this->rental('full', 6600000);
        $this->setCatalogPrice($r, 1320000);

        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, ['notes' => 'phone fixed']))->assertOk();

        $this->assertEqualsWithDelta(6600000, $this->bill($r), 0.01);
    }

    public function test_pay_button_after_a_catalog_rise_collects_the_booked_balance_only(): void
    {
        $r = $this->rental();
        $this->setCatalogPrice($r, 1320000);

        // Balance on the booked bill is 6.600.000 - 1.980.000 = 4.620.000 (not 5.940.000).
        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'payment_amount_add' => '4620000', 'pay_action' => '1',
        ]))->assertOk();

        $this->assertEqualsWithDelta(6600000, $this->bill($r), 0.01);
        $this->assertEqualsWithDelta(6600000, (float) $r->fresh()->amount_paid, 0.01);
        $this->assertEqualsWithDelta(6600000, $this->recorded($r), 0.01);
    }

    public function test_an_item_added_later_is_priced_at_the_catalog_and_old_items_keep_their_price(): void
    {
        $r = $this->rental();
        $this->setCatalogPrice($r, 1320000);
        $new = $this->outfit(1320000);

        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r, [
            'outfit_ids' => array_merge($r->outfits->pluck('id')->all(), [$new->id]),
            'item_discounts' => [], 'item_discount_types' => [],
        ]))->assertOk();

        $r = $r->fresh('outfits');
        foreach ($r->outfits as $s) {
            $expected = $s->id === $new->id ? 1320000 : 1100000;
            $this->assertEqualsWithDelta($expected, (float) $s->pivot->price_incl_tax, 0.01, 'outfit '.$s->id);
        }
        $this->assertEqualsWithDelta((1100000 + 1100000 + 1320000) * 3, $this->bill($r), 0.01);
    }

    public function test_an_approved_item_removal_keeps_the_booked_price_of_the_rest(): void
    {
        $r = $this->rental();
        $this->setCatalogPrice($r, 1320000);
        [$keep, $drop] = [$r->outfits[0], $r->outfits[1]];

        $approval = RentalApprovalRequest::create([
            'store_id' => $this->store->id,
            'requested_by_user_id' => $this->admin->id,
            'payload' => ['kind' => 'item_removal', 'rental_id' => $r->id, 'removed_outfit_ids' => [$drop->id]],
            'status' => 'pending',
            'is_tutorial' => false,
        ]);
        $this->as($this->manager)->postJson(route('approvals.item-removal.approve', $approval))->assertOk();

        $r = $r->fresh('outfits');
        $this->assertSame([$keep->id], $r->outfits->pluck('id')->all());
        $this->assertEqualsWithDelta(1100000, $this->pivotPrice($r, $keep->id), 0.01);
        $this->assertEqualsWithDelta(1100000 * 3, $this->bill($r), 0.01);
    }

    public function test_a_legacy_row_without_a_stored_price_falls_back_to_the_catalog(): void
    {
        $r = $this->rental();
        $legacy = $r->outfits->first();
        $r->outfits()->updateExistingPivot($legacy->id, ['price_incl_tax' => null, 'price_excl_tax' => null, 'tax_amount' => null]);
        $this->setCatalogPrice($r, 1320000);

        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->base($r->fresh('outfits'), ['notes' => 'x']))->assertOk();

        $r = $r->fresh('outfits');
        $this->assertEqualsWithDelta(1320000, (float) $r->outfits->firstWhere('id', $legacy->id)->pivot->price_incl_tax, 0.01);
        $this->assertEqualsWithDelta(1100000, (float) $r->outfits->where('id', '!=', $legacy->id)->first()->pivot->price_incl_tax, 0.01);
    }

    public function test_a_new_rent_is_still_priced_from_the_catalog(): void
    {
        $r = $this->rental();
        $this->setCatalogPrice($r, 1320000);
        $fresh = $this->outfit(1320000);
        $start = now()->addDays(30)->toDateString();

        $this->as($this->manager)->postJson(route('rentals.store'), [
            'customer_name' => 'New', 'phone' => '0813', 'start_date' => $start, 'end_date' => now()->addDays(32)->toDateString(),
            'outfit_ids' => [$fresh->id], 'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '1320000',
            'discount' => '', 'discount_type' => 'fixed',
        ])->assertStatus(200);

        $created = Rental::where('customer_name', 'New')->firstOrFail();
        $this->assertEqualsWithDelta(1320000, (float) $created->outfits->first()->pivot->price_incl_tax, 0.01);
    }

    // --- Edit request from the date/items panel (RentalController::storeCooldownApprovalRequest) ---

    public function test_edit_request_total_checks_use_the_booked_price_after_a_catalog_rise(): void
    {
        // One block (3 days): this panel's check prices the rent as one block, so keep the bill small.
        $r = $this->rental('full', 2200000, 3);
        $this->setCatalogPrice($r, 1320000);
        // This panel only opens a request when the change hits a cool-down conflict: another rent
        // of the same outfit returns the day before this one starts.
        \Illuminate\Support\Facades\Cache::forever('rental_return_buffer_days', 1);
        $other = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Previous', 'phone' => '0899', 'status' => 'active',
            'start_date' => $r->start_date->copy()->subDays(3)->toDateString(), 'end_date' => $r->start_date->copy()->subDay()->toDateString(),
        ]);
        $other->outfits()->attach($r->outfits->first()->id, ['price_incl_tax' => 1100000, 'price_excl_tax' => 1000000, 'tax_amount' => 100000]);

        // Full payment of the booked bill (2.200.000) must pass the "paid >= total due" floor;
        // at today's catalog price the floor would be 2.640.000 and this would be refused (422).
        $this->as($this->admin)->postJson(route('rentals.cooldown-approval-request'), [
            'rental_id' => $r->id,
            'outfit_ids' => $r->outfits->pluck('id')->all(),
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '2200000',
            'payment_entry' => 'replace',
        ])->assertOk()->assertJsonPath('mode', 'approval_request_sent');
    }

    // --- Approved edit (RentalApprovalController::approve) ---------------------------------

    public function test_approved_notes_edit_after_a_catalog_rise_keeps_the_booked_price(): void
    {
        $r = $this->rental();
        $this->setCatalogPrice($r, 1320000);

        $this->approveEdit($r, $r->outfits->pluck('id')->all(), ['notes' => 'approved note'])->assertOk();

        $this->assertEqualsWithDelta(6600000, $this->bill($r), 0.01);
        $this->assertEqualsWithDelta(1100000, $this->pivotPrice($r, $r->outfits->first()->id), 0.01);
        $this->assertEqualsWithDelta(1980000, $this->recorded($r), 0.01);
    }

    public function test_approved_edit_on_a_paid_rent_after_a_catalog_drop_books_no_refund(): void
    {
        $r = $this->rental('full', 6600000);
        $this->setCatalogPrice($r, 990000);

        $this->approveEdit($r, $r->outfits->pluck('id')->all(), ['notes' => 'approved note'])->assertOk();

        $this->assertEqualsWithDelta(6600000, $this->bill($r), 0.01);
        $this->assertEqualsWithDelta(6600000, $this->recorded($r), 0.01);
        $this->assertEqualsWithDelta(0, Account::where('code', '4910')->firstOrFail()->balance(), 0.01, 'no Rental Refunds booked');
    }

    public function test_approved_edit_prices_only_the_added_item_at_the_catalog(): void
    {
        $r = $this->rental();
        $this->setCatalogPrice($r, 1320000);
        $new = $this->outfit(1320000);

        $this->approveEdit($r, array_merge($r->outfits->pluck('id')->all(), [$new->id]))->assertOk();

        $r = $r->fresh('outfits');
        foreach ($r->outfits as $s) {
            $this->assertEqualsWithDelta($s->id === $new->id ? 1320000 : 1100000, (float) $s->pivot->price_incl_tax, 0.01, 'outfit '.$s->id);
        }
        $this->assertEqualsWithDelta((1100000 + 1100000 + 1320000) * 3, $this->bill($r), 0.01);
    }
}
