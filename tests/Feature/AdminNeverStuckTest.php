<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * A non-technical shop admin doing the wrong thing: typing more than the bill
 * into a QRIS payment, pasting "Rp 300.000", double-clicking Pay, paying while a
 * request waits, sending an invoice to a broken phone number. The rule for every
 * case: never a server error, the ledger stays right, and there is ALWAYS a way
 * forward afterwards (the rent can still be paid, returned and invoiced).
 *
 * No real message is ever sent: every outbound call is faked (and the base
 * TestCase blocks any request a test did not fake).
 */
class AdminNeverStuckTest extends TestCase
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
        ShiftSession::create(['opened_by_user_id' => $this->admin->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
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

    private function outfit(int $price): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'N-'.uniqid(), 'price' => $price, 'stock' => 1]);
    }

    private function dpRental(int $price = 300000, int $paid = 100000, bool $pickedUp = false, int $startInDays = 1): Rental
    {
        $outfit = $this->outfit($price);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Layperson', 'phone' => '081234567890',
            'start_date' => now()->addDays($startInDays)->toDateString(), 'end_date' => now()->addDays($startInDays + 2)->toDateString(),
            'status' => 'active', 'payment_method' => 'qris', 'payment_kind' => 'down_payment', 'amount_paid' => $paid,
            'picked_up_at' => $pickedUp ? now() : null,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $price, 'price_excl_tax' => $price, 'tax_amount' => 0]);
        RentalPaymentRecorder::recordIfNonZero($rental, $paid, null, null, 'qris', 'down_payment', totalDue: (float) $price);

        return $rental->fresh('outfits');
    }

    /** The edit modal's "Pay" button: same rent, only the payment fields change. */
    private function pay(User $u, Rental $r, mixed $amount, string $method = 'qris')
    {
        return $this->as($u)->putJson(route('rentals.update', $r), [
            'outfit_ids' => [$r->outfits->first()->id], 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => $method, 'payment_kind' => 'final_payment',
            // The real modal re-submits the saved discount untouched.
            'amount_paid' => $amount, 'discount' => $r->discount !== null ? (string) (int) $r->discount : '', 'discount_type' => $r->discount_type ?? 'fixed',
            'discount_note' => $r->discount_note,
        ]);
    }

    private function assertHealthy(string $what): void
    {
        $this->assertEqualsWithDelta((float) JournalEntryLine::sum('debit'), (float) JournalEntryLine::sum('credit'), 0.005, "$what: ledger unbalanced");
        $this->assertGreaterThanOrEqual(-0.005, Account::where('code', '1300')->first()->balance(), "$what: Rental Receivable went negative");
    }

    /** @param array<string,int> $codes */
    private function assertNoServerError(array $codes, string $what): void
    {
        $this->assertSame([], array_filter($codes, fn ($c) => $c >= 500), "$what gave a server error: ".json_encode(array_filter($codes, fn ($c) => $c >= 500)));
    }

    // ---- QRIS typed too much -------------------------------------------------

    public function test_qris_overpay_on_a_new_rent_is_refused_and_the_exact_amount_then_works(): void
    {
        $outfit = $this->outfit(300000);
        $body = fn ($amount) => [
            'outfit_ids' => [$outfit->id], 'customer_name' => 'QRIS Over', 'phone' => '0811',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'payment_method' => 'qris', 'payment_kind' => 'full', 'amount_paid' => $amount, 'discount' => null, 'discount_type' => 'fixed',
        ];

        foreach (['3000000', '300001', '350000', '999999999'] as $tooMuch) {
            $res = $this->as($this->admin)->postJson(route('rentals.store'), $body($tooMuch));
            $res->assertStatus(422);
            $this->assertNotEmpty($res->json('message'), 'the admin must be told why');
        }
        $this->assertSame(0, Rental::where('customer_name', 'QRIS Over')->count());

        $this->as($this->admin)->postJson(route('rentals.store'), $body('300000'))->assertSuccessful();
        $this->assertSame(1, Rental::where('customer_name', 'QRIS Over')->count());
        $this->assertHealthy('new rent after overpay attempts');
    }

    public function test_qris_overpay_on_pay_is_refused_nothing_changes_and_the_exact_amount_then_works(): void
    {
        $r = $this->dpRental();

        foreach (['3000000', '300001', '400000', '200000000'] as $tooMuch) {
            $res = $this->pay($this->admin, $r, $tooMuch);
            $res->assertStatus(422);
            $this->assertStringContainsString('more than', strtolower((string) $res->json('message')));
            $this->assertSame(100000.0, (float) $r->fresh()->amount_paid, 'a refused payment must not be half-applied');
        }

        $this->pay($this->admin, $r, '300000')->assertOk();
        $this->assertSame(300000.0, (float) $r->fresh()->amount_paid);
        $this->assertSame(0.0, Account::where('code', '1300')->first()->balance());
        $this->assertHealthy('pay after overpay attempts');
    }

    public function test_messy_amounts_typed_by_hand_never_crash_and_never_overpay(): void
    {
        $r = $this->dpRental();
        $codes = [];
        foreach (['Rp 300.000', '300.000', '300,000', ' 300000 ', '3e5', '-300000', '0', '', 'three hundred thousand', '300000.00001', ['300000'], null] as $i => $messy) {
            $codes["#$i"] = $this->pay($this->admin, $r, $messy)->getStatusCode();
        }

        $this->assertNoServerError($codes, 'a hand-typed amount');
        $this->assertLessThanOrEqual(300000.0, (float) $r->fresh()->amount_paid);
        $this->assertHealthy('messy amounts');

        // Whatever was typed before, the correct amount still goes through.
        if ((float) $r->fresh()->amount_paid < 300000) {
            $this->pay($this->admin, $r->fresh('outfits'), '300000')->assertOk();
        }
        $this->assertSame(300000.0, (float) $r->fresh()->amount_paid);
    }

    public function test_double_clicking_pay_charges_once(): void
    {
        $r = $this->dpRental();

        $first = $this->pay($this->admin, $r, '300000');
        $second = $this->pay($this->admin, $r, '300000');

        $first->assertOk();
        $this->assertLessThan(500, $second->getStatusCode());
        $this->assertSame(300000.0, (float) $r->fresh()->amount_paid);
        $this->assertSame(300000.0, (float) \App\Models\RentalPaymentRecord::where('rental_id', $r->id)->sum('amount'));
        $this->assertSame(300000.0, Account::where('code', '1200')->first()->balance(), 'QRIS lands in Bank exactly once');
        $this->assertHealthy('double click');
    }

    // ---- a request is waiting: blocked, but never stuck ------------------------

    public function test_a_waiting_request_blocks_pay_and_return_but_both_work_once_decided(): void
    {
        $r = $this->dpRental(pickedUp: false, startInDays: 0);

        // Admin asks for a discount (goes to approval)…
        $this->as($this->admin)->putJson(route('rentals.update', $r), [
            'outfit_ids' => [$r->outfits->first()->id], 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => 'qris', 'payment_kind' => 'down_payment', 'amount_paid' => '100000',
            'discount' => '50000', 'discount_type' => 'fixed', 'discount_note' => 'regular customer',
        ])->assertOk()->assertJsonPath('mode', 'edit_approval_request_sent');

        // …then panics and tries everything.
        $this->pay($this->admin, $r, '300000')->assertStatus(422);
        $this->pay($this->admin, $r, '250000')->assertStatus(422);
        $this->as($this->admin)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->assertStatus(422);
        $this->as($this->admin)->postJson(route('rentals.status', $r), ['status' => 'returned'])->assertStatus(422);
        $this->assertSame(100000.0, (float) $r->fresh()->amount_paid);

        // Management decides (approves the discount) — everything opens up again.
        $approval = RentalApprovalRequest::where('status', 'pending')->firstOrFail();
        $this->as($this->manager)->postJson(route('approvals.rentals.approve', $approval))->assertOk();

        $this->pay($this->admin, $r->fresh('outfits'), '250000')->assertOk();
        $this->as($this->admin)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->assertOk();
        $this->as($this->admin)->postJson(route('rentals.status', $r), ['status' => 'returned'])->assertOk();
        $this->assertSame('returned', $r->fresh()->status);
        $this->assertHealthy('approve then finish');
    }

    public function test_paying_then_asking_for_a_discount_ends_in_a_refund_not_a_lock(): void
    {
        $r = $this->dpRental();
        $this->pay($this->admin, $r, '300000')->assertOk();

        $this->as($this->admin)->putJson(route('rentals.update', $r), [
            'outfit_ids' => [$r->outfits->first()->id], 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => 'qris', 'payment_kind' => 'final_payment', 'amount_paid' => '300000',
            'discount' => '50000', 'discount_type' => 'fixed', 'discount_note' => 'wrong price',
        ])->assertOk()->assertJsonPath('mode', 'edit_approval_request_sent');

        $res = $this->as($this->manager)->postJson(route('approvals.rentals.approve', RentalApprovalRequest::where('status', 'pending')->firstOrFail()));

        $res->assertOk();
        $this->assertNotNull($res->json('refund_message'), 'the manager must be told money goes back');
        $this->assertSame(50000.0, Account::where('code', '4910')->first()->balance());
        $this->as($this->admin)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->assertOk();
        $this->assertHealthy('refund path');
    }

    // ---- invoice / WhatsApp never blocks the shop ------------------------------

    public function test_sending_the_invoice_to_a_broken_number_explains_itself_and_the_rent_still_works(): void
    {
        Http::fake(['*' => Http::response(['status' => true], 200)]);
        config()->set('services.fonnte.token', 'test-token-never-real');
        config()->set('services.fonnte.invoice_send_mode', 'link');

        $codes = [];
        foreach (['', ' ', 'abc', '0', '+62', '0812-3456-7890', '+62 812 3456 7890', str_repeat('9', 40), '<script>'] as $i => $phone) {
            $r = $this->dpRental();
            Rental::whereKey($r->id)->update(['phone' => $phone]);
            $codes["phone#$i"] = $this->as($this->admin)->postJson(route('invoices.send-whatsapp', $r))->getStatusCode();
            // The rent itself is untouched and still payable (a blank phone must be filled
            // in first — the edit form says so — then it goes through).
            $fresh = $r->fresh('outfits');
            $first = $this->pay($this->admin, $fresh, '300000');
            if ($first->getStatusCode() === 422) {
                $first->assertJsonValidationErrors('phone');
                $fresh->phone = '081200000000';
                $this->pay($this->admin, $fresh, '300000')->assertOk();
            } else {
                $first->assertOk();
            }
        }

        $this->assertNoServerError($codes, 'sending an invoice');
        Http::assertSent(fn ($req) => ! str_contains((string) $req->url(), 'localhost'));
        $this->assertHealthy('whatsapp');
    }

    public function test_whatsapp_provider_down_gives_a_message_and_blocks_nothing(): void
    {
        Http::fake(['*' => Http::response(['status' => false, 'reason' => 'device disconnected'], 500)]);
        config()->set('services.fonnte.token', 'test-token-never-real');
        config()->set('services.fonnte.invoice_send_mode', 'link');
        $r = $this->dpRental();

        $res = $this->as($this->admin)->postJson(route('invoices.send-whatsapp', $r));

        $this->assertNotEmpty($res->json('error'), 'the admin must see why sending failed');
        $this->pay($this->admin, $r, '300000')->assertOk();
        $this->as($this->admin)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->assertOk();
    }

    public function test_no_whatsapp_token_configured_is_a_clear_message_not_a_crash_loop(): void
    {
        config()->set('services.fonnte.token', null);
        config()->set('services.fonnte.invoice_send_mode', 'link');
        $r = $this->dpRental();

        $res = $this->as($this->admin)->postJson(route('invoices.send-whatsapp', $r));

        $this->assertNotEmpty($res->json('error'));
        $this->pay($this->admin, $r, '300000')->assertOk();
    }

    // ---- the whole day of a careless admin -------------------------------------

    public function test_a_careless_admins_whole_day_never_ends_in_a_dead_end(): void
    {
        $r = $this->dpRental(pickedUp: false, startInDays: 0);
        $codes = [];

        // Tries to hand the outfit over before paying, then to return it, then to cancel with refund.
        $codes['pickup unpaid'] = $this->as($this->admin)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->getStatusCode();
        $codes['return unpaid'] = $this->as($this->admin)->postJson(route('rentals.status', $r), ['status' => 'returned'])->getStatusCode();
        $codes['refund as admin'] = $this->as($this->admin)->postJson(route('rentals.status', $r), ['status' => 'cancelled', 'refund' => true])->getStatusCode();
        $codes['status garbage'] = $this->as($this->admin)->postJson(route('rentals.status', $r), ['status' => 'paid'])->getStatusCode();
        // Overpays by QRIS twice, underpays once.
        $codes['overpay 1'] = $this->pay($this->admin, $r, '3000000')->getStatusCode();
        $codes['overpay 2'] = $this->pay($this->admin, $r, '310000')->getStatusCode();
        $codes['underpay'] = $this->pay($this->admin, $r, '150000')->getStatusCode();
        // Reloads the list page and the rent in between.
        $codes['index'] = $this->as($this->admin)->get(route('rentals.index'))->getStatusCode();

        $this->assertNoServerError($codes, 'careless admin');
        $this->assertSame('active', $r->fresh()->status);
        $this->assertLessThanOrEqual(300000.0, (float) $r->fresh()->amount_paid);

        // The way out always exists: pay the rest, hand over, return.
        $this->pay($this->admin, $r->fresh('outfits'), '300000')->assertOk();
        $this->as($this->admin)->postJson(route('rentals.pickup', $r), ['picked_up' => true])->assertOk();
        $this->as($this->admin)->postJson(route('rentals.status', $r), ['status' => 'returned'])->assertOk();
        $this->assertSame('returned', $r->fresh()->status);
        $this->assertSame(300000.0, (float) \App\Models\RentalPaymentRecord::where('rental_id', $r->id)->sum('amount'));
        $this->assertHealthy('whole day');
    }

    public function test_a_rent_returned_before_it_was_paid_off_can_still_be_settled(): void
    {
        // Real-world case: handed back while 165.000 was still owed, before
        // "return needs full payment" existed. Returned rents are locked for admin.
        $r = $this->dpRental(pickedUp: true, startInDays: -3);
        Rental::whereKey($r->id)->update(['status' => 'returned']);

        $this->assertLessThan(500, $this->pay($this->admin, $r->fresh('outfits'), '300000')->getStatusCode());
        $this->assertSame(100000.0, (float) $r->fresh()->amount_paid, 'locked: admin cannot pay a returned rent');

        // The way out: management reopens, admin collects, returns again.
        $this->as($this->manager)->postJson(route('rentals.status', $r), ['status' => 'active'])->assertOk();
        $this->pay($this->admin, $r->fresh('outfits'), '300000')->assertOk();
        $this->as($this->admin)->postJson(route('rentals.status', $r), ['status' => 'returned'])->assertOk();

        $this->assertSame('returned', $r->fresh()->status);
        $this->assertSame(300000.0, (float) $r->fresh()->amount_paid);
        $this->assertSame(0.0, Account::where('code', '1300')->first()->balance());
        $this->assertHealthy('settle after return');
    }

    // ---- Long rent (more than one 3-day block) ------------------------------

    /** The 5–13 Okt video: 9 days = 3 blocks of 200.000, minus 50.000 item discount = 550.000. */
    public function test_full_payment_on_a_multi_block_rent_is_checked_against_the_real_total(): void
    {
        $outfit = $this->outfit(200000);
        $body = fn ($amount) => [
            'outfit_ids' => [$outfit->id], 'customer_name' => 'LISA', 'phone' => '0812',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(9)->toDateString(),
            'payment_method' => 'qris', 'payment_kind' => 'full', 'amount_paid' => $amount, 'discount' => null, 'discount_type' => 'fixed',
            'item_discounts' => [$outfit->id => '50000'], 'item_discount_types' => [$outfit->id => 'fixed'], 'item_discount_notes' => [$outfit->id => 'Discount approved by Vallen'],
        ];

        $this->as($this->admin)->postJson(route('rentals.store'), $body('550000'))->assertSuccessful();
        $rental = Rental::where('customer_name', 'LISA')->firstOrFail();
        $this->assertEqualsWithDelta(550000, (float) $rental->amount_paid, 0.01);
        // Paying only one block's worth is not "full".
        $this->as($this->admin)->postJson(route('rentals.store'), array_merge($body('150000'), ['customer_name' => 'LISA 2']))->assertStatus(422);
        $this->assertHealthy('multi-block full payment');
    }
}
