<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\StockOpname;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\TutorialRental;
use App\Models\TutorialShiftSession;
use App\Models\TutorialRentalApprovalRequest;
use App\Models\User;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Leakage test per feature (readiness checklist, owner request 1b-b): a practice (tour) action must
 * leave every REAL number exactly as it was: real rents, payments, journals, ledger balances, the
 * open shift's payment total and the real outfits. Each test snapshots the real side, runs the practice
 * action through the real routes, and compares. Every test also proves the practice side DID change,
 * so a test cannot pass just because the action did nothing.
 */
class PracticeLeakageTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    private User $admin;

    private User $supervisor;

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
        $this->manager = User::factory()->create(['role' => 'management']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        $this->supervisor = User::factory()->create(['role' => 'supervisor']);
        foreach ([$this->manager, $this->admin, $this->supervisor] as $u) {
            $u->stores()->attach($this->store->id);
        }
        $this->shift = ShiftSession::create(['opened_by_user_id' => $this->admin->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
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

    /** A real rent with a real DP in the real open shift: the numbers a practice action must not move. */
    private function realRental(): Rental
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'REAL-'.uniqid(), 'price' => 1100000, 'stock' => 1]);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Real Customer', 'phone' => '0812',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 500000,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 1100000, 'price_excl_tax' => 1000000, 'tax_amount' => 100000]);
        RentalPaymentRecorder::recordIfNonZero($rental->load('outfits'), 500000, null, null, 'cash', 'down_payment', totalDue: 1100000.0);

        return $rental->fresh('outfits');
    }

    /** A dedicated practice outfit (the tour uses `outfits.is_tutorial`). */
    private function practiceOutfit(int $price = 300000): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'PRAC-'.uniqid(), 'price' => $price, 'stock' => 1, 'is_tutorial' => true]);
    }


    /** Practice rent seeded straight into `tutorial_rentals` with booked prices (as the tour leaves it after a save). */
    private function practiceRental(float $paid = 100000, ?User $owner = null): TutorialRental
    {
        $t = TutorialRental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => $paid,
            'created_by_user_id' => ($owner ?? $this->admin)->id, 'is_tutorial' => true,
        ]);
        $t->outfits()->attach($this->practiceOutfit()->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 272727.27, 'tax_amount' => 27272.73]);

        return $t->fresh('outfits');
    }

    /** Everything real money / real records are made of, read raw (no scopes) so nothing can hide a leak. */
    private function realSnapshot(): array
    {
        return [
            'rentals' => DB::table('rentals')->count(),
            'rentals_amount_paid' => (float) DB::table('rentals')->sum('amount_paid'),
            'payment_records' => DB::table('rental_payment_records')->count(),
            'payment_records_sum' => (float) DB::table('rental_payment_records')->sum('amount'),
            'shift_payment_sum' => (float) RentalPaymentRecord::where('shift_session_id', $this->shift->id)->sum('amount'),
            'journal_entries' => DB::table('journal_entries')->count(),
            'journal_lines' => DB::table('journal_entry_lines')->count(),
            'journal_debit' => (float) DB::table('journal_entry_lines')->sum('debit'),
            'kas' => Account::where('code', '1100')->first()->balance(),
            'uang_muka' => Account::where('code', '2300')->first()->balance(),
            'pendapatan' => Account::where('code', '4100')->first()->balance(),
            'shift_sessions' => DB::table('shift_sessions')->count(),
            'real_approvals' => DB::table('rental_approval_requests')->count(),
            'real_activity_logs' => DB::table('rental_activity_logs')->count(),
            'real_outfits' => DB::table('outfits')->where('is_tutorial', false)->count(),
            'expenses' => DB::table('expenses')->count(),
            'cash_movements' => DB::table('cash_movements')->count(),
        ];
    }

    private function assertNothingLeaked(array $before, string $what): void
    {
        $this->assertSame($before, $this->realSnapshot(), $what.': a real number changed after a practice action');
    }

    /**
     * Finding F1 (docs/readiness-isolation-audit.md), fixed: the price/ceiling lookups used to hide practice outfits
     * (tutorial scope), so a practice rent read "total due Rp 0" and any payment was refused. A practice create with
     * a DP now lands in the practice table with the practice outfit's price, and no real number moves.
     */
    public function test_creating_a_practice_rent_with_a_dp_lands_in_the_practice_table_and_leaks_nothing(): void
    {
        $real = $this->realRental();
        $outfit = $this->practiceOutfit();
        $before = $this->realSnapshot();

        $this->as($this->admin)->postJson(route('rentals.store'), [
            'outfit_ids' => [$outfit->id], 'customer_name' => 'Practice New', 'phone' => '0800',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000',
            'discount' => null, 'discount_type' => 'fixed', 'tutorial_practice' => '1',
        ])->assertSuccessful();

        $created = TutorialRental::query()->where('customer_name', 'Practice New')->first();
        $this->assertNotNull($created, 'practice side did change');
        $this->assertEqualsWithDelta(100000, (float) $created->amount_paid, 0.01);
        $this->assertNull($created->shift_session_id, 'a practice rent never points at the real open shift');
        $this->assertEqualsWithDelta(300000, (float) $created->outfits->first()->pivot->price_incl_tax, 0.01, 'booked at the practice outfit price');
        $this->assertNothingLeaked($before, 'practice create with a DP');
        $this->assertSame('Real Customer', Rental::find($real->id)->customer_name);
    }

    /** @return array<string, mixed> */
    private function practiceCreateBody(Outfit $outfit, string $kind, string $amount, int $days = 2): array
    {
        return [
            'outfit_ids' => [$outfit->id], 'customer_name' => 'Practice Scenario', 'phone' => '0800',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(1 + $days)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => $kind, 'amount_paid' => $amount,
            'discount' => null, 'discount_type' => 'fixed', 'tutorial_practice' => '1',
        ];
    }

    /** F1 scenarios: the practice total is now real, so the "full" check and the over-payment ceiling work like on a real rent. */
    public function test_practice_create_full_payment_checks_the_real_total_due(): void
    {
        $this->realRental();
        $outfit = $this->practiceOutfit(300000);
        $before = $this->realSnapshot();

        // 3-day block price 300.000 (tax-inclusive): a full payment below it is refused, the exact amount is accepted
        $this->as($this->admin)->postJson(route('rentals.store'), $this->practiceCreateBody($outfit, 'full', '299999'))->assertStatus(422);
        $this->assertSame(0, TutorialRental::query()->where('customer_name', 'Practice Scenario')->count());

        $this->as($this->admin)->postJson(route('rentals.store'), $this->practiceCreateBody($outfit, 'full', '300000'))->assertSuccessful();
        $this->assertSame(1, TutorialRental::query()->where('customer_name', 'Practice Scenario')->count(), 'practice side did change');
        $this->assertNothingLeaked($before, 'practice create, full payment');
    }

    public function test_practice_create_refuses_a_payment_above_the_total_due(): void
    {
        $this->realRental();
        $outfit = $this->practiceOutfit(300000);
        $before = $this->realSnapshot();

        $res = $this->as($this->admin)->postJson(route('rentals.store'), $this->practiceCreateBody($outfit, 'down_payment', '300001'));

        $this->assertGreaterThanOrEqual(400, $res->status(), 'more than the total due must be refused, like on a real rent');
        $this->assertSame(0, TutorialRental::query()->where('customer_name', 'Practice Scenario')->count());
        $this->assertNothingLeaked($before, 'practice create, payment above total');
    }

    public function test_practice_create_prices_a_multi_block_rent_per_three_day_block(): void
    {
        $this->realRental();
        $outfit = $this->practiceOutfit(300000);
        $before = $this->realSnapshot();

        // 6 rental days = 2 blocks of 3 days = 600.000: a full payment of one block is refused, two blocks accepted
        $this->as($this->admin)->postJson(route('rentals.store'), $this->practiceCreateBody($outfit, 'full', '300000', days: 5))->assertStatus(422);
        $this->as($this->admin)->postJson(route('rentals.store'), $this->practiceCreateBody($outfit, 'full', '600000', days: 5))->assertSuccessful();
        $this->assertNothingLeaked($before, 'practice create, 6-day rent');
    }

    /** The practice rent may only point at a practice shift (FK to tutorial_shift_sessions), and it does when one is open. */
    public function test_practice_create_attaches_to_the_open_practice_shift_not_the_real_one(): void
    {
        $this->realRental();
        // an older, closed practice shift first, so the open one gets id 2 and cannot match the real shift (id 1) by accident
        TutorialShiftSession::create(['opened_by_user_id' => $this->admin->id, 'opened_at' => now()->subDay(), 'closed_at' => now()->subDay()->addHour(), 'period' => ShiftSession::PERIOD_MORNING]);
        $practiceShift = TutorialShiftSession::create(['opened_by_user_id' => $this->admin->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        $before = $this->realSnapshot();

        $this->as($this->admin)->postJson(route('rentals.store'), $this->practiceCreateBody($this->practiceOutfit(300000), 'down_payment', '100000'))->assertSuccessful();

        $created = TutorialRental::query()->where('customer_name', 'Practice Scenario')->first();
        $this->assertSame($practiceShift->id, (int) $created->shift_session_id);
        $this->assertNotSame((int) $this->shift->id, (int) $created->shift_session_id, 'not the real open shift');
        $this->assertNothingLeaked($before, 'practice create inside a practice shift');
    }

    /** Invariant behind the F1 fix: a REAL rent (no practice flag) still cannot see or price a practice outfit. */
    public function test_a_real_create_still_cannot_price_a_practice_outfit(): void
    {
        $this->realRental();
        $outfit = $this->practiceOutfit();
        $before = $this->realSnapshot();

        $res = $this->as($this->admin)->postJson(route('rentals.store'), [
            'outfit_ids' => [$outfit->id], 'customer_name' => 'Real Sneaky', 'phone' => '0800',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000',
            'discount' => null, 'discount_type' => 'fixed',
        ]);

        $this->assertGreaterThanOrEqual(400, $res->status(), 'a real create with a practice outfit must be refused');
        $this->assertSame(0, Rental::query()->where('customer_name', 'Real Sneaky')->count());
        $this->assertSame(0, TutorialRental::query()->where('customer_name', 'Real Sneaky')->count());
        $this->assertNothingLeaked($before, 'real create with a practice outfit');
    }

    /**
     * Finding F2, fixed: "Pay" on a practice rent used to be filed as a practice edit request (the practice outfit's
     * price was invisible, so the save looked like a price change). It is now applied at once, like on a real rent.
     */
    public function test_paying_a_practice_rent_is_applied_at_once_and_leaks_nothing(): void
    {
        $this->realRental();
        $practice = $this->practiceRental(paid: 100000);
        $before = $this->realSnapshot();

        $res = $this->as($this->admin)->putJson(route('rentals.update', ['rental' => $practice->id, 'tutorial' => 1]), $this->payBody($practice));

        $res->assertOk();
        $this->assertNotSame('edit_approval_request_sent', $res->json('mode'));
        $this->assertSame(0, TutorialRentalApprovalRequest::query()->count(), 'no practice request is filed for a plain Pay');
        $this->assertEqualsWithDelta(300000, (float) TutorialRental::find($practice->id)->amount_paid, 0.01, 'practice side did change');
        $this->assertNothingLeaked($before, 'practice Pay');
    }

    /**
     * Finding F3, fixed: a supervisor's Pay on a practice rent now moves the practice rent's paid amount (it used to
     * answer "success" while nothing changed). Still no real number moves.
     */
    public function test_a_supervisor_paying_a_practice_rent_moves_only_the_practice_amount(): void
    {
        $this->realRental();
        $practice = $this->practiceRental(paid: 100000, owner: $this->supervisor);
        $before = $this->realSnapshot();

        $this->as($this->supervisor)->putJson(route('rentals.update', ['rental' => $practice->id, 'tutorial' => 1]), $this->payBody($practice))
            ->assertOk()->assertJsonPath('ok', true);

        $this->assertEqualsWithDelta(300000, (float) TutorialRental::find($practice->id)->amount_paid, 0.01, 'practice side did change');
        $this->assertSame(0, TutorialRentalApprovalRequest::query()->count());
        $this->assertNothingLeaked($before, 'supervisor Pay on a practice rent');
    }

    public function test_editing_and_saving_a_practice_rent_leaks_nothing_into_real_data(): void
    {
        $this->realRental();
        $practice = $this->practiceRental(paid: 100000);
        $before = $this->realSnapshot();

        $this->as($this->admin)->putJson(route('rentals.update', ['rental' => $practice->id, 'tutorial' => 1]), [
            'outfit_ids' => $practice->outfits->pluck('id')->all(), 'customer_name' => 'Practice EDITED', 'phone' => '0801',
            'start_date' => $practice->start_date->toDateString(), 'end_date' => $practice->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'payment_entry' => 'increment', 'payment_amount_add' => '0',
            'tutorial_practice' => '1',
        ])->assertSuccessful();

        $this->assertSame(1, TutorialRentalApprovalRequest::query()->where('payload->kind', 'edit')->count(), 'practice side did change (edit of a paid rent is filed as a practice request)');
        $this->assertNothingLeaked($before, 'practice edit + save');
    }

    public function test_cancelling_a_practice_rent_with_a_dp_leaks_nothing_into_real_data(): void
    {
        $this->realRental();
        $practice = $this->practiceRental(paid: 100000);
        $before = $this->realSnapshot();

        $this->as($this->admin)->postJson(route('rentals.status', ['rental' => $practice->id, 'tutorial' => 1]), ['status' => 'cancelled', 'tutorial_practice' => 1])
            ->assertSuccessful();

        $this->assertSame('cancelled', TutorialRental::find($practice->id)->status, 'practice side did change');
        $this->assertNothingLeaked($before, 'practice cancel');
    }

    /** Exactly what the Pay button in the edit modal submits: same items and dates, payment fields only (amount_paid is the new cumulative total). */
    private function payBody(TutorialRental $practice): array
    {
        return [
            'outfit_ids' => $practice->outfits->pluck('id')->all(), 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => $practice->start_date->toDateString(), 'end_date' => $practice->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'final_payment', 'amount_paid' => '300000',
            'discount' => '', 'discount_type' => 'fixed', 'tutorial_practice' => '1',
        ];
    }

    /** Control for F2: the very same Pay body on an identical REAL rent is applied at once (so the practice difference is real). */
    public function test_control_the_same_pay_body_on_a_real_rent_is_applied_at_once(): void
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'CTRL-'.uniqid(), 'price' => 300000, 'stock' => 1]);
        $real = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 100000,
        ]);
        $real->outfits()->attach($outfit->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 272727.27, 'tax_amount' => 27272.73]);
        RentalPaymentRecorder::recordIfNonZero($real->load('outfits'), 100000, null, null, 'cash', 'down_payment', totalDue: 300000.0);
        $real = $real->fresh('outfits');

        $body = [
            'outfit_ids' => [$outfit->id], 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => $real->start_date->toDateString(), 'end_date' => $real->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'final_payment', 'amount_paid' => '300000',
            'discount' => '', 'discount_type' => 'fixed',
        ];
        $this->as($this->admin)->putJson(route('rentals.update', $real), $body)->assertOk()->assertJsonPath('ok', true);

        $this->assertEqualsWithDelta(300000, (float) $real->fresh()->amount_paid, 0.01);
    }

    public function test_a_practice_stock_opname_does_not_touch_the_real_one_and_cleanup_removes_only_the_practice(): void
    {
        $real = StockOpname::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'started_by_user_id' => $this->manager->id, 'started_at' => now(), 'is_tutorial_practice' => false]);
        $realBefore = $real->fresh()->toArray();
        $realOutfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'OPN-'.uniqid(), 'price' => 100000, 'stock' => 1]);
        $outfitBefore = $realOutfit->fresh()->toArray();

        $this->as($this->supervisor)->postJson(route('stock-opname.tutorial-start'))->assertOk();

        $this->assertSame(1, StockOpname::withoutGlobalScopes()->where('is_tutorial_practice', true)->count(), 'practice side did change');
        $this->assertSame(1, StockOpname::withoutGlobalScopes()->where('is_tutorial_practice', false)->count());
        $this->assertSame($realBefore, $real->fresh()->toArray());
        $this->assertSame($outfitBefore, $realOutfit->fresh()->toArray());

        $this->as($this->supervisor)->postJson(route('stock-opname.tutorial-cleanup'))->assertOk();

        $this->assertSame(0, StockOpname::withoutGlobalScopes()->where('is_tutorial_practice', true)->count());
        $this->assertSame($realBefore, $real->fresh()->toArray(), 'cleanup must not delete or change the real session');
    }

    public function test_an_open_practice_stock_opname_does_not_block_starting_a_real_one(): void
    {
        $this->as($this->supervisor)->postJson(route('stock-opname.tutorial-start'))->assertOk();

        $this->as($this->manager)->post(route('stock-opname.store'));

        $this->assertSame(1, StockOpname::withoutGlobalScopes()->where('is_tutorial_practice', false)->count(), 'a real session was started next to the open practice one');
    }

    /** Guard: the snapshot would catch a leak (a REAL payment on the REAL rent moves it). */
    public function test_the_snapshot_detects_a_real_payment(): void
    {
        $real = $this->realRental();
        $before = $this->realSnapshot();

        RentalPaymentRecorder::recordIfNonZero($real, 100000, $this->shift->id, $this->admin->id, 'cash', 'final_payment', totalDue: 1100000.0);

        $this->assertNotSame($before, $this->realSnapshot());
    }
}
