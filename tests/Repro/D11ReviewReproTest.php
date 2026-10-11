<?php

namespace Tests\Repro;

use App\Models\Account;
use App\Models\JournalEntry;
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

/** Throwaway repro for the D11 review (commit 36fe4d6). Prints observed behaviour; asserts only what is claimed. */
class D11ReviewReproTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;
    private User $admin;
    private User $supervisor;
    private User $supervisor2;
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
        $this->supervisor2 = User::factory()->create(['role' => 'supervisor']);
        $this->manager = User::factory()->create(['role' => 'management']);
        foreach ([$this->admin, $this->supervisor, $this->supervisor2, $this->manager] as $u) {
            $u->stores()->attach($this->store->id);
            ShiftSession::create(['opened_by_user_id' => $u->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        }
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

    private function outfit(int $price): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'RP-'.uniqid(), 'price' => $price, 'stock' => 1]);
    }

    private function rental(int $price = 330000, int $paid = 330000, string $kind = 'full', int $days = 3, array $extra = [], float $tax = 0): Rental
    {
        $start = now()->addDays(1)->startOfDay();
        $outfit = $this->outfit($price);
        $rental = Rental::create(array_merge([
            'store_id' => $this->store->id, 'customer_name' => 'Paid Customer', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => $kind, 'amount_paid' => $paid,
        ], $extra));
        $excl = $tax > 0 ? round($price / (1 + $tax / 100), 2) : $price;
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $price, 'price_excl_tax' => $excl, 'tax_amount' => round($price - $excl, 2)]);
        $rental->load('outfits');
        RentalPaymentRecorder::recordIfNonZero($rental, $paid, null, null, 'cash', $kind, totalDue: $this->bill($rental));

        return $rental->fresh('outfits');
    }

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

    private function save(User $who, Rental $r, array $outfitIds, array $override = [])
    {
        return $this->as($who)->putJson(route('rentals.update', $r), $this->body($r, $outfitIds, $override));
    }

    private function pendings(Rental $r)
    {
        return RentalApprovalRequest::query()->where('status', 'pending')->where('payload->kind', 'edit')->where('payload->rental_id', $r->id)->orderBy('id')->get();
    }

    private function bal(string $code): float
    {
        return Account::where('code', $code)->firstOrFail()->balance();
    }

    private function bill(Rental $r): float
    {
        return (float) RentalFinancialSummary::summarize($r->fresh('outfits'), (float) StoreCache::get('pricing_tax_percent', 0))['net_incl_tax'];
    }

    private function recorded(Rental $r): float
    {
        return (float) RentalPaymentRecord::where('rental_id', $r->id)->sum('amount');
    }

    private function out(string $label, $res, ?Rental $r = null): void
    {
        $r = $r?->fresh('outfits');
        fwrite(STDERR, sprintf("\n[%s] http=%s mode=%s msg=%s | items=%s kind=%s paid=%s dp_amt=%s rec=%s bill=%s",
            $label, $res?->status(), $res?->json('mode'), substr((string) ($res?->json('message') ?? ''), 0, 90),
            $r ? $r->outfits->count() : '-', $r?->payment_kind, $r?->amount_paid, var_export($r?->down_payment_amount, true),
            $r ? $this->recorded($r) : '-', $r ? $this->bill($r) : '-'));
    }

    private function neighbourOn(Outfit $s): void
    {
        $n = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Before', 'phone' => '0813',
            'start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 0,
        ]);
        $n->outfits()->attach($s->id, ['price_incl_tax' => $s->price, 'price_excl_tax' => $s->price, 'tax_amount' => 0]);
    }

    /** R1: cool-down swap A -> B (same price) on a paid rent: flagged? self-approvable? Compare with update(). */
    public function test_r1_cooldown_swap_flag_and_self_approval(): void
    {
        $r = $this->rental();
        $b = $this->outfit(330000);
        $this->neighbourOn($b);
        $body = $this->body($r, [$b->id], ['rental_id' => $r->id, 'approval_focus_outfit_ids' => [$b->id]]);
        $res = $this->as($this->supervisor)->postJson(route('rentals.cooldown-approval-request'), $body);
        $a = $this->pendings($r)->first();
        $this->out('R1 cooldown swap', $res, $r);
        fwrite(STDERR, ' flag='.var_export($a?->payload['add_item_settled'] ?? null, true));
        if ($a) {
            $ap = $this->as($this->supervisor)->postJson(route('approvals.rentals.approve', $a));
            $this->out('R1 self-approve cooldown swap', $ap, $r);
            $ap->assertStatus(403); // FIXED (D11-R1): the requester can no longer approve
            $this->assertSame([$r->outfits->first()->id], $r->fresh('outfits')->outfits->pluck('id')->all());
        }

        // same change through update(): flagged
        $r2 = $this->rental();
        $b2 = $this->outfit(330000);
        $res2 = $this->save($this->supervisor, $r2, [$b2->id]);
        $a2 = $this->pendings($r2)->first();
        $this->out('R1 update swap', $res2, $r2);
        fwrite(STDERR, ' flag='.var_export($a2?->payload['add_item_settled'] ?? null, true));
        $this->assertTrue((bool) ($a2?->payload['add_item_settled'] ?? false));
    }

    /** R1b: supervisor cool-down swap to a CHEAPER item on a paid rent, approved by the same supervisor -> refund. */
    public function test_r1b_cooldown_cheaper_swap_self_approved_refund(): void
    {
        $r = $this->rental();
        $b = $this->outfit(220000);
        $this->neighbourOn($b);
        $res = $this->as($this->supervisor)->postJson(route('rentals.cooldown-approval-request'), $this->body($r, [$b->id], ['rental_id' => $r->id, 'approval_focus_outfit_ids' => [$b->id]]));
        $a = $this->pendings($r)->first();
        $this->out('R1b cooldown cheaper swap', $res, $r);
        fwrite(STDERR, ' flag='.var_export($a?->payload['add_item_settled'] ?? null, true).' requester='.$a?->requested_by_user_id.' sup='.$this->supervisor->id);
        $ap = $this->as($this->supervisor)->postJson(route('approvals.rentals.approve', $a));
        $this->out('R1b self-approve', $ap, $r);
        fwrite(STDERR, ' refund='.json_encode($ap->json('refund_amount') ?? $ap->json('refund')).' 1100='.$this->bal('1100').' 4910='.$this->bal('4910').' 4100='.$this->bal('4100'));
        $ap->assertStatus(403); // FIXED (D11-R1): the requester can no longer approve
    }

    /** R2: cool-down files a second pending edit request while a D11 request waits; what happens on approve. */
    public function test_r2_cooldown_second_request_while_pending(): void
    {
        $r = $this->rental();
        $x = $this->outfit(55000);
        $y = $this->outfit(77000);
        $this->neighbourOn($y);
        $this->save($this->admin, $r, [$r->outfits->first()->id, $x->id])->assertOk();
        $res = $this->as($this->admin)->postJson(route('rentals.cooldown-approval-request'), $this->body($r, [$r->outfits->first()->id, $y->id], ['rental_id' => $r->id, 'approval_focus_outfit_ids' => [$y->id]]));
        $p = $this->pendings($r);
        $this->out('R2 cooldown second', $res, $r);
        fwrite(STDERR, ' pending='.$p->count());
        $this->assertSame(2, $p->count());
        $first = $this->as($this->manager)->postJson(route('approvals.rentals.approve', $p[0]));
        $this->out('R2 approve first', $first, $r);
        $second = $this->as($this->manager)->postJson(route('approvals.rentals.approve', $p[1]));
        $this->out('R2 approve second', $second, $r);
        fwrite(STDERR, ' 1300='.$this->bal('1300').' 4100='.$this->bal('4100'));
    }

    /** R3: rent paid, overdue fine applied but unpaid -> D11 does not trigger. */
    public function test_r3_overdue_fine_unpaid(): void
    {
        $r = $this->rental(330000, 330000, 'full', 3, ['overdue_fee_amount' => 50000, 'overdue_fee_days' => 1, 'overdue_fee_rate' => 50000, 'overdue_fee_applied_at' => now()]);
        $x = $this->outfit(55000);
        $ids = [$r->outfits->first()->id, $x->id];
        $res = $this->save($this->admin, $r, $ids);
        $this->out('R3 admin save', $res, $r);
        $res = $this->save($this->supervisor, $r, $ids);
        $this->out('R3 supervisor save', $res, $r);
        $res = $this->save($this->supervisor, $r, $ids, ['payment_amount_add' => '55000', 'pay_action' => '1']);
        $this->out('R3 supervisor pay+item', $res, $r);
        fwrite(STDERR, ' pending='.$this->pendings($r)->count().' kas='.$this->bal('1100'));
    }

    /** R3b: rent paid AND its overdue fine paid: is D11 still triggered? (double count of the fine) */
    public function test_r3b_overdue_fine_paid(): void
    {
        $r = $this->rental(330000, 330000, 'full', 3, ['overdue_fee_amount' => 50000, 'overdue_fee_days' => 1, 'overdue_fee_rate' => 50000, 'overdue_fee_applied_at' => now(), 'overdue_amount_paid' => 50000, 'overdue_payment_method' => 'cash']);
        $x = $this->outfit(55000);
        $ids = [$r->outfits->first()->id, $x->id];
        $res = $this->save($this->manager, $r, $ids);
        $this->out('R3b manager save', $res, $r);
        $res = $this->save($this->admin, $r, $ids);
        $this->out('R3b admin save', $res, $r);
        $res = $this->save($this->supervisor, $r, $ids, ['payment_amount_add' => '55000', 'pay_action' => '1']);
        $this->out('R3b supervisor pay+item', $res, $r);
        fwrite(STDERR, ' pending='.$this->pendings($r)->count());
        $this->assertGreaterThan(0, RentalApprovalRequest::where('payload->add_item_settled', true)->count(), 'FIXED (D11-R3): the paid fine is counted once, D11 fires');
    }

    /** Q4: bill-raising date change on a paid rent (known gap). */
    public function test_q4_date_extension(): void
    {
        $r = $this->rental();
        $ids = [$r->outfits->first()->id];
        $end = $r->end_date->copy()->addDays(3)->toDateString();
        $res = $this->save($this->admin, $r, $ids, ['end_date' => $end]);
        $this->out('Q4 admin save +3d', $res, $r);
        $res = $this->save($this->supervisor, $r, $ids, ['end_date' => $end]);
        $this->out('Q4 supervisor save +3d', $res, $r);
        $res = $this->save($this->supervisor, $r, $ids, ['end_date' => $end, 'payment_amount_add' => '330000', 'pay_action' => '1']);
        $this->out('Q4 supervisor pay +3d', $res, $r);
        fwrite(STDERR, ' pending='.$this->pendings($r)->count());
    }

    /** Q3: admin transaction edit mode ON keeps the old behaviour. */
    public function test_q3_edit_mode_on(): void
    {
        StoreCache::forever('admin_transaction_edit_mode', true);
        $r = $this->rental();
        $x = $this->outfit(55000);
        $ids = [$r->outfits->first()->id, $x->id];
        $res = $this->save($this->admin, $r, $ids);
        $this->out('Q3 admin save', $res, $r);
        $res = $this->save($this->supervisor, $r, $ids);
        $this->out('Q3 supervisor save', $res, $r);
        $res = $this->save($this->supervisor, $r, $ids, ['payment_amount_add' => '55000', 'pay_action' => '1']);
        $this->out('Q3 supervisor pay+item', $res, $r);
        $this->assertSame(0, RentalApprovalRequest::where('payload->add_item_settled', true)->count());
    }

    /** Q1: self-approval via ?tutorial=1, admin approving, other supervisor approving. */
    public function test_q1_guard_bypass_attempts(): void
    {
        $r = $this->rental();
        $x = $this->outfit(55000);
        $this->save($this->supervisor, $r, [$r->outfits->first()->id, $x->id])->assertOk();
        $a = $this->pendings($r)->first();
        $t = $this->as($this->supervisor)->postJson(route('approvals.rentals.approve', ['approval' => $a->id, 'tutorial' => 1]));
        $this->out('Q1 self ?tutorial=1', $t, $r);
        $t->assertStatus(403);
        $ad = $this->as($this->admin)->postJson(route('approvals.rentals.approve', $a));
        $this->out('Q1 admin approve', $ad, $r);
        $this->assertNotSame(200, $ad->status());
        $ok = $this->as($this->supervisor2)->postJson(route('approvals.rentals.approve', $a));
        $this->out('Q1 other supervisor', $ok, $r);
        $ok->assertOk();
    }

    /** Q2/Q7: PPN 10% inclusive, 4-day rent with extra charge; approve then Pay; ledger and dp amount. */
    public function test_q2_tax10_extra_day_flow(): void
    {
        StoreCache::forever('pricing_tax_percent', 10);
        $r = $this->rental(330000, 380000, 'full', 4, ['extra_charge' => 50000], 10);
        fwrite(STDERR, "\n[Q2 start] bill=".$this->bill($r).' rec='.$this->recorded($r));
        $x = $this->outfit(55000);
        $ids = [$r->outfits->first()->id, $x->id];
        $res = $this->save($this->admin, $r, $ids, ['extra_charge' => '50000']);
        $this->out('Q2 admin save', $res, $r);
        $a = $this->pendings($r)->first();
        if (! $a) {
            return;
        }
        $ap = $this->as($this->manager)->postJson(route('approvals.rentals.approve', $a));
        $this->out('Q2 approve', $ap, $r);
        $fin = RentalFinancialSummary::summarize($r->fresh('outfits'), 10);
        fwrite(STDERR, ' 1300='.$this->bal('1300').' 2300='.$this->bal('2300').' 4100='.$this->bal('4100').' finKeys='.implode(',', array_slice(array_keys($fin), 0, 12)));
        $r = $r->fresh('outfits');
        $pay = $this->save($this->supervisor, $r, $r->outfits->pluck('id')->all(), ['extra_charge' => '50000', 'payment_amount_add' => (string) ($this->bill($r) - (float) $r->amount_paid), 'pay_action' => '1']);
        $this->out('Q2 pay balance', $pay, $r);
        fwrite(STDERR, ' 1100='.$this->bal('1100').' 1300='.$this->bal('1300').' 2300='.$this->bal('2300').' 4100='.$this->bal('4100'));
        $d = (float) JournalEntry::with('lines')->get()->flatMap->lines->sum('debit');
        $c = (float) JournalEntry::with('lines')->get()->flatMap->lines->sum('credit');
        $this->assertEqualsWithDelta($d, $c, 0.01);
    }
}
