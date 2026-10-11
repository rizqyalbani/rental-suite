<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\ShiftSession;
use App\Models\StockOpname;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalLedgerSync;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Idiot user" / hostile-input tests: things a person can do by accident or on
 * purpose that the UI is not supposed to allow — nonsense dates, negative and
 * absurd amounts, arrays where a string belongs, double submits, approving the
 * same request twice. The rule is not "it must succeed" but "it must never be
 * a server error, and it must never leave the ledger unbalanced".
 */
class IdiotUserTest extends TestCase
{
    use RefreshDatabase;

    protected Store $store;

    protected User $manager;

    protected User $operator;

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
        $this->operator = User::factory()->create(['role' => 'admin']);
        $this->operator->stores()->attach($this->store->id);
        ShiftSession::create(['opened_by_user_id' => $this->operator->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        StoreContext::set($this->store->id);
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        parent::tearDown();
    }

    protected function asManager()
    {
        return $this->actingAs($this->manager)->withSession(['current_store_id' => $this->store->id]);
    }

    protected function asOperator()
    {
        return $this->actingAs($this->operator)->withSession(['current_store_id' => $this->store->id]);
    }

    protected function outfit(int $price, string $code): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => $code, 'price' => $price, 'stock' => 1]);
    }

    protected function rental(array $outfits, int $paid, string $kind = 'down_payment', string $method = 'cash', int $startInDays = 10): Rental
    {
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Idiot Test', 'phone' => '0812',
            'start_date' => now()->addDays($startInDays)->toDateString(), 'end_date' => now()->addDays($startInDays + 2)->toDateString(),
            'status' => 'active', 'payment_method' => $method, 'payment_kind' => $kind, 'amount_paid' => $paid,
        ]);
        $total = 0;
        foreach ($outfits as $s) {
            $rental->outfits()->attach($s->id, ['price_incl_tax' => $s->price, 'price_excl_tax' => $s->price, 'tax_amount' => 0]);
            $total += (int) $s->price;
        }
        RentalPaymentRecorder::recordIfNonZero($rental, min($paid, $total), null, null, $method, $kind, totalDue: (float) $total);

        return $rental->fresh('outfits');
    }

    protected function assertLedgerBalanced(): void
    {
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.005, 'ledger is unbalanced');
        $piutang = Account::where('code', '1300')->first()->balance();
        $this->assertGreaterThanOrEqual(-0.005, $piutang, 'Rental Receivable went negative');
    }

    /** @param array<string, int> $statuses */
    protected function assertNoServerErrors(array $statuses, string $what): void
    {
        $bad = array_filter($statuses, fn ($s) => $s >= 500);
        $this->assertSame([], $bad, "$what returned a server error for: ".json_encode(array_keys($bad), JSON_UNESCAPED_UNICODE));
    }

    /** @return list<mixed> */
    protected function weird(): array
    {
        return ['', ' ', 'abc', '2026-13-45', '0000-00-00', '9999-99-99', '2026-02-30', '1900-01-01', '3000-01-01', '-1', '1e309',
            ['x'], ['a' => ['b' => 'c']], str_repeat('A', 6000), "'; DROP TABLE users;--", '<script>alert(1)</script>', "𝕏🙂 \u{202E}", "a\0b", '%', '_', '999999999999999999999999'];
    }

    // ---- reports and filters -------------------------------------------------

    public function test_no_report_filter_can_cause_a_server_error(): void
    {
        $this->rental([$this->outfit(300000, 'R-1')], 100000);
        $params = ['date_from', 'date_to', 'as_of', 'from', 'to', 'month', 'year', 'search', 'page', 'per_page', 'store_id', 'all_stores', 'status', 'sort'];
        $routes = ['accounting.dashboard', 'accounting.reports.trial-balance', 'accounting.reports.balance-sheet', 'accounting.reports.profit-loss',
            'accounting.reports.general-ledger', 'accounting.reports.piutang', 'accounting.reports.ppn', 'accounting.journal-entries.index', 'accounting.shifts-by-date'];

        $statuses = [];
        foreach ($routes as $route) {
            foreach ($params as $param) {
                foreach ($this->weird() as $i => $value) {
                    $statuses["$route?$param=#$i"] = $this->asManager()->get(route($route, [$param => $value]))->getStatusCode();
                }
            }
        }

        $this->assertNoServerErrors($statuses, 'a report filter');
    }

    // ---- rental edits --------------------------------------------------------

    public function test_nonsense_direct_edits_never_cause_a_server_error_or_a_bad_ledger(): void
    {
        $a = $this->outfit(300000, 'E-1');
        $b = $this->outfit(150000, 'E-2');
        $rental = $this->rental([$a], 300000, 'full');
        $base = ['outfit_ids' => [$a->id], 'customer_name' => 'X', 'phone' => '1', 'start_date' => $rental->start_date->toDateString(), 'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '300000', 'discount' => '0', 'discount_type' => 'fixed'];
        $cases = [
            'negative discount' => ['discount' => '-500000'],
            'discount bigger than total' => ['discount' => '99999999'],
            'percent over 100' => ['discount' => '250', 'discount_type' => 'percent'],
            'negative percent' => ['discount' => '-10', 'discount_type' => 'percent'],
            'negative amount paid' => ['amount_paid' => '-1000'],
            'zero amount paid' => ['amount_paid' => '0'],
            'amount paid absurd' => ['amount_paid' => '999999999999'],
            'amount paid text' => ['amount_paid' => 'one hundred thousand'],
            'amount paid array' => ['amount_paid' => ['1']],
            'end before start' => ['end_date' => now()->subYear()->toDateString()],
            'dates far future' => ['start_date' => '9999-12-30', 'end_date' => '9999-12-31'],
            'dates nonsense' => ['start_date' => 'yesterday', 'end_date' => 'tomorrow'],
            'outfit ids empty' => ['outfit_ids' => []],
            'outfit ids garbage' => ['outfit_ids' => ['abc', -1, 0, 999999]],
            'outfit ids duplicated' => ['outfit_ids' => [$a->id, $a->id, $a->id]],
            'outfit ids a string' => ['outfit_ids' => 'lots'],
            'more items, no payment field change' => ['outfit_ids' => [$a->id, $b->id]],
            'huge note' => ['notes' => str_repeat('x', 100000)],
            'kind nonsense' => ['payment_kind' => 'gratis'],
            'method nonsense' => ['payment_method' => str_repeat('m', 500)],
            'extra charge negative' => ['extra_charge' => '-5000'],
            'extra charge absurd' => ['extra_charge' => '1e20'],
            'payment_entry nonsense' => ['payment_entry' => 'increment', 'payment_amount_add' => '-100'],
            'nothing at all' => [],
        ];

        $statuses = [];
        foreach ($cases as $name => $override) {
            $payload = $override === [] ? [] : array_merge($base, $override);
            $statuses[$name] = $this->asManager()->putJson(route('rentals.update', $rental), $payload)->getStatusCode();
        }

        $this->assertNoServerErrors($statuses, 'a direct rental edit');
        $this->assertLedgerBalanced();
    }

    public function test_a_broken_approval_payload_never_causes_a_server_error_or_a_bad_ledger(): void
    {
        $a = $this->outfit(300000, 'P-1');
        $rental = $this->rental([$a], 100000);
        $full = fn (array $rentalOverride = [], array $top = []) => array_merge([
            'kind' => 'edit', 'rental_id' => $rental->id,
            'rental' => array_merge(['customer_name' => 'X', 'phone' => '1', 'start_date' => $rental->start_date->toDateString(), 'end_date' => $rental->end_date->toDateString(),
                'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 100000, 'discount' => null, 'discount_type' => 'fixed'], $rentalOverride),
            'outfit_ids' => [$a->id], 'item_discounts' => [], 'item_discount_types' => [], 'item_discount_notes' => [],
        ], $top);
        $payloads = [
            'empty payload' => [],
            'no rental block' => ['kind' => 'edit', 'rental_id' => $rental->id],
            'unknown kind' => $full([], ['kind' => 'explode']),
            'rental id missing' => $full([], ['rental_id' => 999999]),
            'rental id string' => $full([], ['rental_id' => 'abc']),
            'outfit ids nonsense' => $full([], ['outfit_ids' => ['x', null, -3]]),
            'outfit ids empty' => $full([], ['outfit_ids' => []]),
            'amount negative' => $full(['amount_paid' => -500]),
            'amount absurd' => $full(['amount_paid' => 9.9e15]),
            'amount string' => $full(['amount_paid' => 'a lot']),
            'discount without note' => $full(['discount' => 50000]),
            'discount huge' => $full(['discount' => 99999999, 'discount_note' => 'x']),
            'dates reversed' => $full(['start_date' => '2030-01-10', 'end_date' => '2020-01-01']),
            'dates nonsense' => $full(['start_date' => 'x', 'end_date' => null]),
            'status cancelled' => $full(['status' => 'cancelled']),
            'takeover from itself' => $full([], ['takeover_from' => [$a->id => $rental->id]]),
            'takeover garbage' => $full([], ['takeover_from' => 'nope']),
            'takeover missing rental' => $full([], ['takeover_from' => [$a->id => 999999]]),
        ];

        $statuses = [];
        foreach ($payloads as $name => $payload) {
            $approval = RentalApprovalRequest::create(['store_id' => $this->store->id, 'requested_by_user_id' => $this->operator->id, 'payload' => $payload, 'status' => 'pending', 'is_tutorial' => false]);
            $statuses[$name] = $this->asManager()->postJson(route('approvals.rentals.approve', $approval))->getStatusCode();
        }

        $this->assertNoServerErrors($statuses, 'an approval with a broken payload');
        $this->assertLedgerBalanced();
    }

    public function test_approving_the_same_request_over_and_over_only_applies_it_once(): void
    {
        $a = $this->outfit(300000, 'D-1');
        $b = $this->outfit(150000, 'D-2');
        $rental = $this->rental([$a], 100000);
        $approval = RentalApprovalRequest::create(['store_id' => $this->store->id, 'requested_by_user_id' => $this->operator->id, 'status' => 'pending', 'is_tutorial' => false, 'payload' => [
            'kind' => 'edit', 'rental_id' => $rental->id,
            'rental' => ['customer_name' => 'X', 'phone' => '1', 'start_date' => $rental->start_date->toDateString(), 'end_date' => $rental->end_date->toDateString(), 'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 100000, 'discount' => null, 'discount_type' => 'fixed'],
            'outfit_ids' => [$a->id, $b->id], 'item_discounts' => [], 'item_discount_types' => [], 'item_discount_notes' => []]]);

        $codes = [];
        for ($i = 0; $i < 6; $i++) {
            $codes[] = $this->asManager()->postJson(route('approvals.rentals.approve', $approval))->getStatusCode();
        }

        $this->assertSame(200, $codes[0]);
        $this->assertSame([], array_filter(array_slice($codes, 1), fn ($c) => $c >= 500));
        $this->assertSame(350000.0, Account::where('code', '1300')->first()->balance());   // 450k - 100k, once
        $this->assertLedgerBalanced();
    }

    public function test_a_long_random_mess_of_edits_keeps_the_ledger_consistent(): void
    {
        mt_srand(20260920);
        $outfits = [];
        for ($i = 0; $i < 6; $i++) {
            $outfits[] = $this->outfit(50000 * ($i + 1), 'M-'.$i);
        }
        $rentals = [];
        foreach ([[0, 1], [2], [3, 4], [5]] as $n => $idx) {
            $rentals[] = $this->rental(array_map(fn ($i) => $outfits[$i], $idx), [100000, 150000, 200000, 250000][$n], $n % 2 ? 'full' : 'down_payment', $n % 3 ? 'cash' : 'qris', 10 + $n * 5);
        }

        $statuses = [];
        for ($step = 0; $step < 60; $step++) {
            $rental = $rentals[mt_rand(0, count($rentals) - 1)]->fresh('outfits');
            if ($rental->status !== 'active') {
                continue;
            }
            $pick = array_values(array_filter($outfits, fn () => mt_rand(0, 1)));
            $ids = $pick === [] ? [$outfits[0]->id] : array_map(fn ($s) => $s->id, $pick);
            $payload = ['outfit_ids' => $ids, 'customer_name' => 'Chaos', 'phone' => '1', 'start_date' => $rental->start_date->toDateString(), 'end_date' => $rental->end_date->toDateString(),
                'payment_method' => $rental->payment_method ?: 'cash', 'payment_kind' => $rental->payment_kind ?: 'full', 'amount_paid' => (string) mt_rand(1, 40) * 10000,
                'discount' => mt_rand(0, 1) ? (string) (mt_rand(0, 6) * 10000) : '0', 'discount_type' => 'fixed', 'discount_note' => 'chaos'];
            $statuses["step$step"] = $this->asManager()->putJson(route('rentals.update', $rental), $payload)->getStatusCode();
            $this->assertLedgerBalanced();
        }

        $this->assertNoServerErrors($statuses, 'random edits');
        // After the dust settles, a re-run of the repair must find nothing it can safely fix,
        // and running it again must change nothing.
        RentalLedgerSync::run();
        $entries = \App\Models\JournalEntry::count();
        $second = RentalLedgerSync::run();
        $this->assertSame(0, $second['adjusted']);
        $this->assertSame($entries, \App\Models\JournalEntry::count());
        $this->assertLedgerBalanced();
    }

    // ---- stock opname --------------------------------------------------------

    public function test_stock_opname_endpoints_survive_garbage_input(): void
    {
        $sup = User::factory()->create(['role' => 'supervisor']);
        $sup->stores()->attach($this->store->id);
        $outfit = $this->outfit(1000, 'O-1');
        $session = StockOpname::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'started_by_user_id' => $sup->id, 'started_at' => now()]);
        $act = fn () => $this->actingAs($sup)->withSession(['current_store_id' => $this->store->id]);

        $statuses = [];
        foreach ($this->weird() as $i => $value) {
            $statuses["mark outfit#$i"] = $act()->postJson(route('stock-opname.mark', $session), ['outfit_id' => $value, 'status' => 'ok'])->getStatusCode();
            $statuses["mark status#$i"] = $act()->postJson(route('stock-opname.mark', $session), ['outfit_id' => $outfit->id, 'status' => $value])->getStatusCode();
            $statuses["mark note#$i"] = $act()->postJson(route('stock-opname.mark', $session), ['outfit_id' => $outfit->id, 'status' => 'missing', 'note' => $value])->getStatusCode();
            $statuses["scan token#$i"] = $act()->postJson(route('stock-opname.scan', $session), ['token' => $value])->getStatusCode();
            $statuses["pin#$i"] = $this->post(route('outfits.opname-pin', $outfit), ['pin' => $value])->getStatusCode();
            $statuses["complete notes#$i"] = $act()->post(route('stock-opname.complete', StockOpname::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'started_by_user_id' => $sup->id, 'started_at' => now(), 'is_tutorial_practice' => true])), ['notes' => $value])->getStatusCode();
        }
        // A "photo" that is really text, and a photo for an outfit that does not exist.
        $fake = \Illuminate\Http\UploadedFile::fake()->createWithContent('proof.jpg', 'this is not an image');
        $statuses['fake photo'] = $act()->post(route('stock-opname.mark', $session), ['outfit_id' => $outfit->id, 'status' => 'damaged', 'photo' => $fake], ['Accept' => 'application/json'])->getStatusCode();
        $statuses['item photo missing'] = $act()->get('/stock-opname/items/999999/photo')->getStatusCode();

        $this->assertNoServerErrors($statuses, 'a stock opname request');
    }
    // ---- every place a person can type an amount or a date --------------------

    public function test_every_accounting_and_rental_form_survives_garbage_in_every_field(): void
    {
        $a = $this->outfit(300000, 'G-1');
        $rental = $this->rental([$a], 100000);
        $fields = ['amount', 'amount_paid', 'debit', 'credit', 'price', 'discount', 'extra_charge', 'date', 'movement_date', 'expense_date', 'entry_date', 'start_date', 'end_date',
            'purchase_date', 'in_service_date', 'useful_life_months', 'salvage_value', 'description', 'memo', 'note', 'notes', 'name', 'code', 'type', 'direction', 'payment_method', 'payment_kind',
            'status', 'account_id', 'cash_account_id', 'counter_account_id', 'category_account_id', 'payment_account_id', 'customer_name', 'phone', 'outfit_ids', 'lines', 'store_id'];
        $routes = [
            ['post', route('accounting.journal-entries.store')], ['post', route('accounting.fixed-assets.store')], ['post', route('accounts.store')],
            ['post', route('cash-movements.store')], ['post', route('expenses.store')], ['post', route('rentals.store')],
            ['post', route('rentals.status', $rental)], ['post', route('rentals.pickup', $rental)], ['post', route('outfits.quick-add')],
            ['post', route('rentals.cooldown-approval-request')],
        ];

        $statuses = [];
        foreach ($this->weird() as $i => $value) {
            $payload = array_fill_keys($fields, $value);
            foreach ($routes as [$method, $url]) {
                $statuses["$url #$i"] = $this->asManager()->json(strtoupper($method), $url, $payload)->getStatusCode();
            }
            // Delete the same rental over and over; and against ids that do not exist.
            $statuses["delete #$i"] = $this->asManager()->deleteJson(route('rentals.destroy', $rental))->getStatusCode();
        }
        $statuses['delete missing'] = $this->asManager()->deleteJson('/rentals/999999')->getStatusCode();

        $this->assertNoServerErrors($statuses, 'a form');
        $this->assertLedgerBalanced();
    }

    // ---- this session's lifecycle guards under garbage input -----------------

    private function pendingEditRequestFor(Rental $r): RentalApprovalRequest
    {
        return RentalApprovalRequest::create(['store_id' => $this->store->id, 'requested_by_user_id' => $this->operator->id, 'status' => 'pending', 'is_tutorial' => false, 'payload' => [
            'kind' => 'edit', 'rental_id' => $r->id, 'base_amount_paid' => (float) $r->amount_paid,
            'rental' => ['customer_name' => $r->customer_name, 'phone' => $r->phone, 'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
                'status' => 'active', 'payment_method' => $r->payment_method ?? 'cash', 'payment_kind' => $r->payment_kind ?? 'down_payment', 'amount_paid' => (float) $r->amount_paid,
                'discount' => 50000, 'discount_type' => 'fixed', 'discount_note' => 'x'],
            'outfit_ids' => [$r->outfits->first()->id], 'item_discounts' => [], 'item_discount_types' => [], 'item_discount_notes' => []]]);
    }

    public function test_status_and_pickup_and_delete_survive_garbage_input_while_an_edit_is_pending(): void
    {
        $a = $this->outfit(300000, 'PEND-1');
        $rental = $this->rental([$a], 300000, 'full');
        $this->pendingEditRequestFor($rental);

        $statuses = [];
        foreach ($this->weird() as $i => $value) {
            $statuses["status target#$i"] = $this->asManager()->postJson(route('rentals.status', $rental), ['status' => $value])->getStatusCode();
            $statuses["status refund#$i"] = $this->asManager()->postJson(route('rentals.status', $rental), ['status' => 'cancelled', 'refund' => $value])->getStatusCode();
            $statuses["pickup#$i"] = $this->asManager()->postJson(route('rentals.pickup', $rental), ['picked_up' => $value])->getStatusCode();
        }
        // Hammer delete: must never be a server error, and must never actually delete
        // while the edit is pending — every attempt should come back the same way.
        for ($i = 0; $i < 5; $i++) {
            $statuses["delete#$i"] = $this->asManager()->deleteJson(route('rentals.destroy', $rental))->getStatusCode();
        }

        $this->assertNoServerErrors($statuses, 'a status/pickup/delete call while an edit is pending');
        $this->assertNotNull(Rental::find($rental->id));
        $this->assertSame('active', $rental->fresh()->status);
        $this->assertLedgerBalanced();
    }

    public function test_a_second_edit_request_is_rejected_no_matter_how_garbage_the_payload_is(): void
    {
        $a = $this->outfit(300000, 'PEND-2');
        $rental = $this->rental([$a], 100000, 'down_payment');
        $this->pendingEditRequestFor($rental);
        $base = ['outfit_ids' => [$a->id], 'customer_name' => 'X', 'phone' => '1', 'start_date' => $rental->start_date->toDateString(), 'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000'];

        $statuses = [];
        foreach ($this->weird() as $i => $value) {
            $statuses["discount#$i"] = $this->asOperator()->putJson(route('rentals.update', $rental), $base + ['discount' => $value, 'discount_type' => 'fixed', 'discount_note' => 'x'])->getStatusCode();
            $statuses["amount#$i"] = $this->asOperator()->putJson(route('rentals.update', $rental), array_merge($base, ['amount_paid' => $value]))->getStatusCode();
        }

        $this->assertNoServerErrors($statuses, 'a second edit request submission');
        // Every single one of these — garbage or not — must have been turned away for the
        // same reason (a pending request already exists), never silently queued as a second one.
        $this->assertSame(1, RentalApprovalRequest::withoutGlobalScopes()->where('status', 'pending')->where('payload->rental_id', $rental->id)->count());
        $this->assertLedgerBalanced();
    }

    public function test_the_exact_payment_ceiling_survives_garbage_amounts(): void
    {
        $a = $this->outfit(300000, 'PAY-1');
        $rental = $this->rental([$a], 100000, 'down_payment');
        $base = ['outfit_ids' => [$a->id], 'customer_name' => 'X', 'phone' => '1', 'start_date' => $rental->start_date->toDateString(), 'end_date' => $rental->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'final_payment', 'discount' => '0', 'discount_type' => 'fixed'];

        $statuses = [];
        foreach ($this->weird() as $i => $value) {
            $statuses["pay#$i"] = $this->asManager()->putJson(route('rentals.update', $rental), array_merge($base, ['amount_paid' => $value]))->getStatusCode();
        }

        $this->assertNoServerErrors($statuses, 'a pay-the-remainder call');
        // No garbage value should have pushed amount_paid past the 300000 total.
        $this->assertLessThanOrEqual(300000.0, (float) $rental->fresh()->amount_paid);
        $this->assertLedgerBalanced();
    }

    public function test_rentals_with_nothing_to_charge_do_not_break_the_ledger_or_the_pages(): void
    {
        $free = $this->outfit(0, 'FREE-1');
        $rental = $this->rental([$free], 0, 'full');
        $paidRental = $this->rental([$this->outfit(200000, 'FREE-2')], 200000, 'full');
        // A 100% discount after the customer already paid in full.
        $this->asManager()->putJson(route('rentals.update', $paidRental), ['outfit_ids' => [$paidRental->outfits->first()->id], 'customer_name' => 'X', 'phone' => '1',
            'start_date' => $paidRental->start_date->toDateString(), 'end_date' => $paidRental->end_date->toDateString(), 'payment_method' => 'cash', 'payment_kind' => 'full',
            'amount_paid' => '200000', 'discount' => '200000', 'discount_type' => 'fixed', 'discount_note' => 'gratis']);

        RentalLedgerSync::reconcileRental($rental->fresh(), true);
        RentalLedgerSync::reconcileRental($paidRental->fresh(), true);
        $statuses = [
            'dashboard' => $this->asManager()->get(route('accounting.dashboard'))->getStatusCode(),
            'piutang' => $this->asManager()->get(route('accounting.reports.piutang'))->getStatusCode(),
            'rents' => $this->asManager()->get(route('rentals.index'))->getStatusCode(),
        ];

        $this->assertNoServerErrors($statuses, 'a page');
        $this->assertLedgerBalanced();
    }
}
