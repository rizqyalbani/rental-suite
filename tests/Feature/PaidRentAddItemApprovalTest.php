<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\TutorialRental;
use App\Models\TutorialRentalApprovalRequest;
use App\Models\User;
use App\Support\RentalFinancialSummary;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decision D11 (all roles): adding an item to a rent whose payments already cover its bill is
 * never saved directly and never takes money up front. It becomes an edit request; once another
 * approver accepts it the extra amount is an open bill (Receivable) that is collected with Pay.
 * Scenario: one item Rp 330.000 (tax 0, one 3-day block) paid in full, then an item Rp 55.000 is added.
 */
class PaidRentAddItemApprovalTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $admin;

    private User $supervisor;

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
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->supervisor = User::factory()->create(['role' => 'supervisor']);
        $this->manager = User::factory()->create(['role' => 'management']);
        foreach ([$this->admin, $this->supervisor, $this->manager] as $u) {
            $u->stores()->attach($this->store->id);
        }
        ShiftSession::create(['opened_by_user_id' => $this->admin->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        StoreContext::set($this->store->id);
        StoreCache::forever('pricing_tax_percent', 0);
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

    private function outfit(int $price, ?string $code = null): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => $code ?? 'AI-'.uniqid(), 'price' => $price, 'stock' => 1]);
    }

    /** One item of $price booked for $days days, paid $paid in one recorded payment of $kind. */
    private function rental(int $price = 330000, int $paid = 330000, string $kind = 'full', int $days = 3, ?Outfit $outfit = null): Rental
    {
        $start = now()->addDays(1)->startOfDay();
        $outfit ??= $this->outfit($price);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Paid Customer', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => $kind, 'amount_paid' => $paid,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $price, 'price_excl_tax' => $price, 'tax_amount' => 0]);
        $rental->load('outfits');
        $blocks = max(1, intdiv($days + 2, 3));
        RentalPaymentRecorder::recordIfNonZero($rental, $paid, null, null, 'cash', $kind, totalDue: (float) ($price * $blocks));

        return $rental->fresh('outfits');
    }

    /** What the edit modal posts for an unchanged rent, plus optional overrides. */
    private function body(Rental $r, array $outfitIds, array $override = []): array
    {
        $p = [
            'outfit_ids' => $outfitIds, 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => $r->payment_method, 'payment_kind' => $r->payment_kind,
            'discount' => '', 'discount_type' => 'fixed',
            'payment_entry' => 'increment', 'payment_amount_add' => '0',
        ];
        foreach ($outfitIds as $id) {
            $p['item_discounts'][$id] = '';
            $p['item_discount_types'][$id] = 'fixed';
        }

        return array_merge($p, $override);
    }

    private function save(User $who, Rental $r, array $outfitIds, array $override = []): \Illuminate\Testing\TestResponse
    {
        return $this->as($who)->putJson(route('rentals.update', $r), $this->body($r, $outfitIds, $override));
    }

    private function pending(Rental $r): ?RentalApprovalRequest
    {
        return RentalApprovalRequest::query()->where('status', 'pending')->where('payload->kind', 'edit')->where('payload->rental_id', $r->id)->first();
    }

    private function approve(User $who, RentalApprovalRequest $a): \Illuminate\Testing\TestResponse
    {
        return $this->as($who)->postJson(route('approvals.rentals.approve', $a));
    }

    private function bal(string $code): float
    {
        return Account::where('code', $code)->firstOrFail()->balance();
    }

    private function bill(Rental $r): float
    {
        return (float) RentalFinancialSummary::summarize($r->fresh('outfits'), 0)['net_incl_tax'];
    }

    private function recorded(Rental $r): float
    {
        return (float) RentalPaymentRecord::where('rental_id', $r->id)->sum('amount');
    }

    private function assertNothingSaved(Rental $r, int $journals, float $paid = 330000.0): void
    {
        $r = $r->fresh('outfits');
        $this->assertCount(1, $r->outfits);
        $this->assertEqualsWithDelta($paid, (float) $r->amount_paid, 0.01);
        $this->assertEqualsWithDelta($paid, $this->recorded($r), 0.01);
        $this->assertSame($journals, JournalEntry::count());
    }

    // --- the request is created, for every role -------------------------------------------

    public function test_admin_save_adding_an_item_to_a_paid_rent_creates_a_request_and_saves_nothing(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $journals = JournalEntry::count();

        $res = $this->save($this->admin, $r, [$r->outfits->first()->id, $extra->id])->assertOk();

        $this->assertSame('edit_approval_request_sent', $res->json('mode'));
        $a = $this->pending($r);
        $this->assertNotNull($a);
        $this->assertEqualsWithDelta(330000, (float) $a->payload['rental']['amount_paid'], 0.01);
        $this->assertSame('down_payment', $a->payload['rental']['payment_kind']);
        $this->assertSame([$r->outfits->first()->id, $extra->id], array_map('intval', $a->payload['outfit_ids']));
        $this->assertNothingSaved($r, $journals);
    }

    // --- d15(a): the popup says what really follows (collect, refund or nothing) ----------------

    public function test_popup_after_adding_an_item_to_a_paid_rent_says_to_collect_with_pay(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);

        $msg = $this->save($this->admin, $r, [$r->outfits->first()->id, $extra->id])->assertOk()->json('message');

        $this->assertStringContainsString('collect the remaining amount with Pay', $msg);
    }

    public function test_popup_after_swapping_to_a_cheaper_item_on_a_paid_rent_talks_about_a_refund_not_pay(): void
    {
        $r = $this->rental();
        $cheaper = $this->outfit(77000);
        $journals = JournalEntry::count();

        $res = $this->save($this->supervisor, $r, [$cheaper->id])->assertOk();

        $this->assertSame('edit_approval_request_sent', $res->json('mode'));
        $this->assertStringContainsString('refunded to the customer', $res->json('message'));
        $this->assertStringNotContainsString('Pay', $res->json('message'));
        $this->assertNotNull($this->pending($r));
        $this->assertSame($journals, JournalEntry::count());
    }

    public function test_popup_after_swapping_to_an_item_of_the_same_price_says_nothing_more_is_due(): void
    {
        $r = $this->rental();
        $same = $this->outfit(330000);

        $msg = $this->save($this->supervisor, $r, [$same->id])->assertOk()->json('message');

        $this->assertStringContainsString('nothing more is due', $msg);
        $this->assertStringNotContainsString('Pay', $msg);
    }

    public function test_popup_after_swapping_to_a_pricier_item_on_a_paid_rent_still_says_to_collect_with_pay(): void
    {
        $r = $this->rental();
        $pricier = $this->outfit(385000);

        $msg = $this->save($this->supervisor, $r, [$pricier->id])->assertOk()->json('message');

        $this->assertStringContainsString('collect the remaining amount with Pay', $msg);
    }

    // --- d15(c): Save alone on a longer paid rent is refused and the message names Pay ---------

    public function test_save_alone_after_extending_the_dates_of_a_paid_rent_is_refused_and_points_to_pay(): void
    {
        $r = $this->rental();
        $journals = JournalEntry::count();

        $res = $this->save($this->supervisor, $r, [$r->outfits->first()->id], [
            'end_date' => $r->end_date->copy()->addDays(3)->toDateString(),
        ])->assertStatus(422);

        $this->assertStringContainsString('amount paid must be at least the total due (Rp 660.000', $res->json('errors.amount_paid.0'));
        $this->assertStringContainsString('use the Pay button', $res->json('errors.amount_paid.0'));
        $this->assertNothingSaved($r, $journals);
    }

    public function test_a_new_rent_with_too_little_money_is_refused_without_the_pay_button_hint(): void
    {
        $outfit = $this->outfit(330000);
        $start = now()->addDays(1)->startOfDay();

        $res = $this->as($this->admin)->postJson(route('rentals.store'), [
            'outfit_ids' => [$outfit->id], 'customer_name' => 'New Customer', 'phone' => '0813',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '50000',
        ])->assertStatus(422);

        $this->assertStringContainsString('amount paid must be at least the total due', $res->json('errors.amount_paid.0'));
        $this->assertStringNotContainsString('Pay button', $res->json('errors.amount_paid.0'));
    }

    public function test_management_save_adding_an_item_to_a_paid_rent_also_needs_permission(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $journals = JournalEntry::count();

        $res = $this->save($this->manager, $r, [$r->outfits->first()->id, $extra->id])->assertOk();

        $this->assertSame('edit_approval_request_sent', $res->json('mode'));
        $this->assertNotNull($this->pending($r));
        $this->assertSame($this->manager->id, (int) $this->pending($r)->requested_by_user_id);
        $this->assertNothingSaved($r, $journals);
    }

    public function test_supervisor_save_adding_an_item_to_a_paid_rent_also_needs_permission(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $journals = JournalEntry::count();

        $res = $this->save($this->supervisor, $r, [$r->outfits->first()->id, $extra->id])->assertOk();

        $this->assertSame('edit_approval_request_sent', $res->json('mode'));
        $this->assertNotNull($this->pending($r));
        $this->assertNothingSaved($r, $journals);
    }

    public function test_a_down_payment_rent_that_is_not_settled_keeps_todays_behaviour(): void
    {
        $r = $this->rental(330000, 100000, 'down_payment');
        $extra = $this->outfit(55000);

        $this->save($this->manager, $r, [$r->outfits->first()->id, $extra->id])->assertOk();

        $this->assertNull($this->pending($r));
        $this->assertCount(2, $r->fresh('outfits')->outfits);
        $this->assertEqualsWithDelta(100000, (float) $r->fresh()->amount_paid, 0.01);
    }

    public function test_a_notes_only_save_on_a_paid_rent_is_not_a_request(): void
    {
        $r = $this->rental();

        $this->save($this->manager, $r, [$r->outfits->first()->id], ['notes' => 'phone fixed'])->assertOk();

        $this->assertNull($this->pending($r));
        $this->assertSame('phone fixed', $r->fresh()->notes);
    }

    // --- Pay with a new item is refused, nothing recorded ----------------------------------

    public function test_pay_with_an_added_item_on_a_paid_rent_is_refused_without_recording_money(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $journals = JournalEntry::count();

        $res = $this->save($this->manager, $r, [$r->outfits->first()->id, $extra->id], ['pay_action' => '1', 'payment_amount_add' => '55000']);

        $res->assertStatus(422);
        $this->assertStringContainsString('needs approval first', (string) $res->json('message'));
        $this->assertNull($this->pending($r));
        $this->assertNothingSaved($r, $journals);
    }

    // --- approval ---------------------------------------------------------------------------

    public function test_approval_adds_the_item_leaves_the_balance_open_and_posts_only_the_adjustment(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $this->save($this->admin, $r, [$r->outfits->first()->id, $extra->id])->assertOk();
        $a = $this->pending($r);
        $this->assertEqualsWithDelta(330000, $this->bal('1100'), 0.01);

        $this->approve($this->manager, $a)->assertOk();

        $r = $r->fresh('outfits');
        $this->assertCount(2, $r->outfits);
        $this->assertEqualsWithDelta(385000, $this->bill($r), 0.01);
        $this->assertEqualsWithDelta(330000, (float) $r->amount_paid, 0.01);
        $this->assertEqualsWithDelta(330000, $this->recorded($r), 0.01);
        $this->assertSame('down_payment', $r->payment_kind);
        // cash unchanged, the extra 55.000 is an open bill and recognised revenue
        $this->assertEqualsWithDelta(330000, $this->bal('1100'), 0.01);
        $this->assertEqualsWithDelta(55000, $this->bal('1300'), 0.01);
        $this->assertEqualsWithDelta(385000, $this->bal('4100'), 0.01);
        $this->assertEqualsWithDelta(0, $this->bal('2300'), 0.01);
        $this->assertEqualsWithDelta(
            (float) JournalEntry::with('lines')->get()->flatMap->lines->sum('debit'),
            (float) JournalEntry::with('lines')->get()->flatMap->lines->sum('credit'),
            0.01
        );
    }

    public function test_after_approval_pay_collects_the_balance_directly(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $this->save($this->admin, $r, [$r->outfits->first()->id, $extra->id])->assertOk();
        $this->approve($this->manager, $this->pending($r))->assertOk();
        $r = $r->fresh('outfits');

        $res = $this->save($this->admin, $r, $r->outfits->pluck('id')->all(), ['payment_amount_add' => '55000', 'pay_action' => '1']);

        $res->assertOk();
        $this->assertNotSame('edit_approval_request_sent', $res->json('mode'));
        $this->assertNull($this->pending($r));
        $this->assertEqualsWithDelta(385000, (float) $r->fresh()->amount_paid, 0.01);
        $this->assertEqualsWithDelta(385000, $this->bal('1100'), 0.01);
        $this->assertEqualsWithDelta(0, $this->bal('1300'), 0.01);
        $this->assertEqualsWithDelta(385000, $this->bal('4100'), 0.01);
    }

    public function test_a_later_notes_save_on_the_rent_with_an_open_balance_is_not_blocked(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $this->save($this->admin, $r, [$r->outfits->first()->id, $extra->id])->assertOk();
        $this->approve($this->manager, $this->pending($r))->assertOk();
        $r = $r->fresh('outfits');

        $this->save($this->manager, $r, $r->outfits->pluck('id')->all(), ['notes' => 'call before pickup'])->assertOk();

        $this->assertSame('call before pickup', $r->fresh()->notes);
        $this->assertEqualsWithDelta(330000, (float) $r->fresh()->amount_paid, 0.01);
    }

    public function test_a_requester_cannot_approve_their_own_request(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $this->save($this->manager, $r, [$r->outfits->first()->id, $extra->id])->assertOk();

        $this->approve($this->manager, $this->pending($r))->assertStatus(403);

        $this->assertCount(1, $r->fresh('outfits')->outfits);
    }

    public function test_rejecting_the_request_changes_nothing(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $this->save($this->admin, $r, [$r->outfits->first()->id, $extra->id])->assertOk();
        $journals = JournalEntry::count();

        $this->as($this->manager)->postJson(route('approvals.rentals.reject', $this->pending($r)), ['reason' => 'no'])->assertOk();

        $this->assertNothingSaved($r, $journals);
    }

    public function test_a_second_request_is_refused_while_one_is_pending(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $other = $this->outfit(77000);
        $this->save($this->admin, $r, [$r->outfits->first()->id, $extra->id])->assertOk();

        $res = $this->save($this->manager, $r, [$r->outfits->first()->id, $other->id]);

        $res->assertStatus(422);
        $this->assertSame(1, RentalApprovalRequest::where('status', 'pending')->count());
    }

    // --- price lock (P2) and multi-block -----------------------------------------------------

    public function test_a_catalog_drop_on_the_old_item_does_not_turn_the_add_into_a_refund(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        Outfit::withoutGlobalScopes()->where('id', $r->outfits->first()->id)->update(['price' => 250000]);
        $this->save($this->admin, $r, [$r->outfits->first()->id, $extra->id])->assertOk();

        $this->approve($this->manager, $this->pending($r))->assertOk();

        $this->assertEqualsWithDelta(385000, $this->bill($r), 0.01);
        $this->assertEqualsWithDelta(330000, $this->bal('1100'), 0.01);
        $this->assertEqualsWithDelta(0, $this->bal('4910'), 0.01);
        $this->assertEqualsWithDelta(55000, $this->bal('1300'), 0.01);
    }

    public function test_a_six_day_rent_owes_two_blocks_of_the_new_item(): void
    {
        $r = $this->rental(330000, 660000, 'full', 6);
        $extra = $this->outfit(55000);
        $this->save($this->admin, $r, [$r->outfits->first()->id, $extra->id])->assertOk();

        $this->approve($this->manager, $this->pending($r))->assertOk();

        $this->assertEqualsWithDelta(770000, $this->bill($r), 0.01);
        $this->assertEqualsWithDelta(660000, (float) $r->fresh()->amount_paid, 0.01);
        $this->assertEqualsWithDelta(110000, $this->bal('1300'), 0.01);
    }

    // --- adjacent flows that must not move ---------------------------------------------------

    public function test_removing_an_item_from_a_paid_rent_still_goes_through_item_removal(): void
    {
        $a = $this->outfit(330000);
        $b = $this->outfit(110000);
        $r = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Two Items', 'phone' => '0812',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => 440000,
        ]);
        foreach ([$a, $b] as $s) {
            $r->outfits()->attach($s->id, ['price_incl_tax' => $s->price, 'price_excl_tax' => $s->price, 'tax_amount' => 0]);
        }
        RentalPaymentRecorder::recordIfNonZero($r->load('outfits'), 440000, null, null, 'cash', 'full', totalDue: 440000.0);

        $this->save($this->manager, $r, [$a->id])->assertOk();

        $this->assertCount(2, $r->fresh('outfits')->outfits);
        $this->assertSame(1, RentalApprovalRequest::where('status', 'pending')->where('payload->kind', 'item_removal')->count());
        $this->assertNull($this->pending($r));
    }

    // --- cool-down shortcut and practice data ------------------------------------------------

    public function test_the_cooldown_shortcut_on_a_paid_rent_files_the_same_kind_of_request(): void
    {
        $r = $this->rental();
        $boundary = $this->outfit(55000);
        $neighbour = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Before', 'phone' => '0813',
            'start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 0,
        ]);
        $neighbour->outfits()->attach($boundary->id, ['price_incl_tax' => 55000, 'price_excl_tax' => 55000, 'tax_amount' => 0]);
        $journals = JournalEntry::count();
        $body = $this->body($r, [$r->outfits->first()->id, $boundary->id], ['rental_id' => $r->id, 'approval_focus_outfit_ids' => [$boundary->id]]);

        $this->as($this->admin)->postJson(route('rentals.cooldown-approval-request'), $body + ['pay_action' => '1', 'payment_amount_add' => '55000'])->assertStatus(422);
        $this->as($this->admin)->postJson(route('rentals.cooldown-approval-request'), $body)->assertOk();

        $a = $this->pending($r);
        $this->assertNotNull($a);
        $this->assertTrue((bool) $a->payload['add_item_settled']);
        $this->assertSame('down_payment', $a->payload['rental']['payment_kind']);
        $this->assertEqualsWithDelta(330000, (float) $a->payload['rental']['amount_paid'], 0.01);
        $this->assertNothingSaved($r, $journals);
    }

    public function test_a_practice_rent_follows_the_rule_in_the_practice_queue_only(): void
    {
        $real = $this->rental();
        $s1 = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'P1-'.uniqid(), 'price' => 300000, 'stock' => 1, 'is_tutorial' => true]);
        $s2 = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'P2-'.uniqid(), 'price' => 55000, 'stock' => 1, 'is_tutorial' => true]);
        $practice = TutorialRental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => 300000,
            'created_by_user_id' => $this->manager->id, 'is_tutorial' => true,
        ]);
        $practice->outfits()->attach($s1->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 300000, 'tax_amount' => 0]);
        $this->assertSame($real->id, $practice->id);
        $journals = JournalEntry::count();

        $this->as($this->manager)->putJson(route('rentals.update', ['rental' => $practice->id, 'tutorial' => 1]), [
            'outfit_ids' => [$s1->id, $s2->id], 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => $practice->start_date->toDateString(), 'end_date' => $practice->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'full', 'payment_entry' => 'increment', 'payment_amount_add' => '0',
            'tutorial_practice' => '1',
        ])->assertOk();

        $this->assertSame(0, RentalApprovalRequest::query()->count(), 'nothing in the real queue');
        $this->assertSame(1, TutorialRentalApprovalRequest::query()->where('payload->kind', 'edit')->count());
        $this->assertCount(1, $practice->fresh('outfits')->outfits);
        $this->assertNothingSaved($real, $journals);
    }

    public function test_a_save_that_carries_money_along_with_the_new_item_never_records_it(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $journals = JournalEntry::count();
        $ids = [$r->outfits->first()->id, $extra->id];

        // the read-only balance riding along (increment) and a "replace" amount are both ignored
        $this->save($this->manager, $r, $ids, ['payment_amount_add' => '55000'])->assertOk();
        $this->assertNothingSaved($r, $journals);
        $this->as($this->supervisor)->postJson(route('approvals.rentals.reject', $this->pending($r)), ['reason' => 'retry'])->assertOk();

        $this->save($this->manager, $r, $ids, ['payment_entry' => 'replace', 'amount_paid' => '385000', 'payment_amount_add' => null])->assertOk();

        $a = $this->pending($r);
        $this->assertEqualsWithDelta(330000, (float) $a->payload['rental']['amount_paid'], 0.01);
        $this->assertNothingSaved($r, $journals);
    }

    // --- review D11-R1 / D11-R3 (independent review of 36fe4d6) ---------------------------------

    /** A rent of another customer that ends today, so that booking $outfit tomorrow hits the cool-down rule. */
    private function neighbourOn(Outfit $outfit): void
    {
        $neighbour = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Before', 'phone' => '0813',
            'start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 0,
        ]);
        $neighbour->outfits()->attach($outfit->id, ['price_incl_tax' => $outfit->price, 'price_excl_tax' => $outfit->price, 'tax_amount' => 0]);
    }

    private function cooldownSwap(User $who, Rental $r, Outfit $to): \Illuminate\Testing\TestResponse
    {
        return $this->as($who)->postJson(
            route('rentals.cooldown-approval-request'),
            $this->body($r, [$to->id], ['rental_id' => $r->id, 'approval_focus_outfit_ids' => [$to->id]])
        );
    }

    public function test_r1_a_cooldown_swap_to_a_cheaper_item_on_a_paid_rent_cannot_be_approved_by_its_requester(): void
    {
        $r = $this->rental();
        $cheaper = $this->outfit(220000);
        $this->neighbourOn($cheaper);
        $journals = JournalEntry::count();

        $this->cooldownSwap($this->supervisor, $r, $cheaper)->assertOk();

        $a = $this->pending($r);
        $this->assertNotNull($a);
        $this->assertTrue((bool) $a->payload['add_item_settled'], 'the swap is flagged even though the new bill is lower');
        $this->approve($this->supervisor, $a)->assertStatus(403);
        $this->assertSame($journals, JournalEntry::count(), 'no refund left the till');
        $this->assertEqualsWithDelta(330000, $this->bal('1100'), 0.01);
        $this->assertSame($r->outfits->first()->id, $r->fresh('outfits')->outfits->first()->id);

        // somebody else can still decide it, and then the normal refund happens
        $this->approve($this->manager, $a->fresh())->assertOk();
        $this->assertSame([$cheaper->id], $r->fresh('outfits')->outfits->pluck('id')->all());
        $this->assertEqualsWithDelta(220000, $this->bal('1100'), 0.01);
    }

    public function test_r1_a_cooldown_swap_at_the_same_price_is_flagged_and_needs_someone_else(): void
    {
        $r = $this->rental();
        $same = $this->outfit(330000);
        $this->neighbourOn($same);

        $this->cooldownSwap($this->supervisor, $r, $same)->assertOk();

        $a = $this->pending($r);
        $this->assertTrue((bool) $a->payload['add_item_settled']);
        $this->approve($this->supervisor, $a)->assertStatus(403);
        $this->approve($this->manager, $a->fresh())->assertOk();
        $this->assertSame([$same->id], $r->fresh('outfits')->outfits->pluck('id')->all());
    }

    public function test_r1_nobody_approves_their_own_edit_request_even_without_the_paid_rent_flag(): void
    {
        // A down-payment rent that is NOT settled: D11 does not flag it, the generic rule still applies.
        $r = $this->rental(330000, 100000, 'down_payment');
        $extra = $this->outfit(55000);
        $this->neighbourOn($extra);

        $this->as($this->supervisor)->postJson(
            route('rentals.cooldown-approval-request'),
            $this->body($r, [$r->outfits->first()->id, $extra->id], ['rental_id' => $r->id, 'approval_focus_outfit_ids' => [$extra->id], 'payment_kind' => 'down_payment'])
        )->assertOk();

        $a = $this->pending($r);
        $this->assertNotNull($a);
        $this->assertFalse((bool) $a->payload['add_item_settled']);
        $this->approve($this->supervisor, $a)->assertStatus(403);
        $this->assertCount(1, $r->fresh('outfits')->outfits);
        $this->approve($this->manager, $a->fresh())->assertOk();
        $this->assertCount(2, $r->fresh('outfits')->outfits);
    }

    public function test_r1_a_pay_with_a_cooldown_swap_on_a_paid_rent_is_refused_like_a_plain_save(): void
    {
        $r = $this->rental();
        $cheaper = $this->outfit(220000);
        $this->neighbourOn($cheaper);

        $this->as($this->supervisor)->postJson(
            route('rentals.cooldown-approval-request'),
            $this->body($r, [$cheaper->id], ['rental_id' => $r->id, 'approval_focus_outfit_ids' => [$cheaper->id], 'pay_action' => '1'])
        )->assertStatus(422);

        $this->assertNull($this->pending($r));
    }

    private function withPaidOverdueFine(Rental $r, int $fine, int $finePaid): void
    {
        $r->forceFill([
            'overdue_fee_amount' => $fine, 'overdue_fee_days' => 1, 'overdue_fee_rate' => $fine,
            'overdue_fee_applied_at' => now(), 'overdue_amount_paid' => $finePaid, 'overdue_payment_method' => 'cash',
        ])->save();
    }

    public function test_r3_a_paid_overdue_fine_is_counted_once_when_judging_that_the_rent_is_paid(): void
    {
        $r = $this->rental();
        $this->withPaidOverdueFine($r, 50000, 50000);
        $extra = $this->outfit(55000);
        $journals = JournalEntry::count();

        $this->save($this->manager, $r, [$r->outfits->first()->id, $extra->id])->assertOk();

        $a = $this->pending($r);
        $this->assertNotNull($a, 'rent 330.000 + fine 50.000 are both paid, so adding an item is a request');
        $this->assertTrue((bool) $a->payload['add_item_settled']);
        $this->assertCount(1, $r->fresh('outfits')->outfits);
        $this->assertSame($journals, JournalEntry::count());
    }

    public function test_r3_an_unpaid_overdue_fine_keeps_the_rent_unsettled_and_no_request_is_forced(): void
    {
        $r = $this->rental();
        $this->withPaidOverdueFine($r, 50000, 0);
        $extra = $this->outfit(55000);

        $this->save($this->manager, $r, [$r->outfits->first()->id, $extra->id]);

        $this->assertSame(0, RentalApprovalRequest::query()->where('payload->add_item_settled', true)->count());
    }
}
