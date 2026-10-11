<?php

namespace Tests\Repro;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
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

/** Throwaway repro for the review of 62a8783. Same fixtures as PaidRentAddItemApprovalTest. */
class D11FixReviewTest extends TestCase
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

    private function outfit(int $price): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'RV-'.uniqid(), 'price' => $price, 'stock' => 1]);
    }

    private function rental(int $price = 330000, int $paid = 330000, string $kind = 'full'): Rental
    {
        $start = now()->addDays(1)->startOfDay();
        $outfit = $this->outfit($price);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Paid Customer', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => $kind, 'amount_paid' => $paid,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $price, 'price_excl_tax' => $price, 'tax_amount' => 0]);
        $rental->load('outfits');
        RentalPaymentRecorder::recordIfNonZero($rental, $paid, null, null, 'cash', $kind, totalDue: (float) $price);

        return $rental->fresh('outfits');
    }

    private function neighbourOn(Outfit $outfit): void
    {
        $n = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Before', 'phone' => '0813',
            'start_date' => now()->subDays(2)->toDateString(), 'end_date' => now()->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 0,
        ]);
        $n->outfits()->attach($outfit->id, ['price_incl_tax' => $outfit->price, 'price_excl_tax' => $outfit->price, 'tax_amount' => 0]);
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

    private function pending(Rental $r): ?RentalApprovalRequest
    {
        return RentalApprovalRequest::query()->where('status', 'pending')->where('payload->kind', 'edit')->where('payload->rental_id', $r->id)->first();
    }

    private function approve(User $who, RentalApprovalRequest $a)
    {
        return $this->as($who)->postJson(route('approvals.rentals.approve', $a));
    }

    private function bal(string $code): float
    {
        return Account::where('code', $code)->firstOrFail()->balance();
    }

    private function withPaidOverdueFine(Rental $r, int $fine, int $finePaid): void
    {
        $r->forceFill([
            'overdue_fee_amount' => $fine, 'overdue_fee_days' => 1, 'overdue_fee_rate' => $fine,
            'overdue_fee_applied_at' => now(), 'overdue_amount_paid' => $finePaid, 'overdue_payment_method' => 'cash',
        ])->save();
    }

    private function ledgerDiff(): float
    {
        $d = (float) JournalEntryLine::query()->withoutGlobalScopes()->sum('debit');
        $c = (float) JournalEntryLine::query()->withoutGlobalScopes()->sum('credit');

        return round($d - $c, 2);
    }

    private function dump(string $label, array $vals): void
    {
        fwrite(STDERR, "\n[$label] ".json_encode($vals));
    }

    /** Q5: cheaper cool-down swap on a paid rent, approved by a second person: refund once, ledger balanced, idempotent. */
    public function test_q5_cheaper_swap_refund_booked_once(): void
    {
        $r = $this->rental();
        $cheaper = $this->outfit(220000);
        $this->neighbourOn($cheaper);
        $this->as($this->supervisor)->postJson(route('rentals.cooldown-approval-request'),
            $this->body($r, [$cheaper->id], ['rental_id' => $r->id, 'approval_focus_outfit_ids' => [$cheaper->id]]))->assertOk();
        $a = $this->pending($r);
        $this->assertTrue((bool) $a->payload['add_item_settled']);
        $this->approve($this->manager, $a)->assertOk();
        $r = $r->fresh('outfits');
        $after = ['kas' => $this->bal('1100'), 'retur4910' => $this->bal('4910'), 'rev4100' => $this->bal('4100'), 'piutang' => $this->bal('1300'), 'um2300' => $this->bal('2300'),
            'amount_paid' => (float) $r->amount_paid, 'kind' => $r->payment_kind, 'records' => (float) RentalPaymentRecord::where('rental_id', $r->id)->sum('amount'),
            'journals' => JournalEntry::withoutGlobalScopes()->count(), 'ledgerDiff' => $this->ledgerDiff()];
        $this->dump('q5 after approve', $after);

        \App\Support\RentalLedgerSync::reconcileRental($r, refundOverpayment: true, byUserId: $this->manager->id);
        // a later plain save (notes only) by management, and a second reconcile
        $this->as($this->manager)->putJson(route('rentals.update', $r), $this->body($r, $r->outfits->pluck('id')->all(), ['notes' => 'later']))->assertOk();
        \App\Support\RentalLedgerSync::reconcileRental($r->fresh('outfits'), refundOverpayment: true, byUserId: $this->manager->id);
        $again = ['kas' => $this->bal('1100'), 'retur4910' => $this->bal('4910'), 'journals' => JournalEntry::withoutGlobalScopes()->count(), 'ledgerDiff' => $this->ledgerDiff(),
            'amount_paid' => (float) $r->fresh()->amount_paid];
        $this->dump('q5 after re-reconcile + notes save', $again);

        $this->assertEqualsWithDelta(220000, $after['kas'], 0.01);
        $this->assertEqualsWithDelta(0, $after['ledgerDiff'], 0.01);
        $this->assertEqualsWithDelta($after['kas'], $again['kas'], 0.01, 'refund booked only once');
        $this->assertEqualsWithDelta($after['retur4910'], $again['retur4910'], 0.01);
        $this->assertEqualsWithDelta(0, $again['ledgerDiff'], 0.01);
    }

    /** Q4: cool-down $due leaves out the overdue fine while the lock counts the paid fine. */
    public function test_q4_cooldown_add_item_with_paid_fine_is_refused_unlike_save(): void
    {
        // Rent 330.000 paid in full + fine 100.000 paid. Add an item of 20.000 (on a cool-down edge).
        $r1 = $this->rental();
        $this->withPaidOverdueFine($r1, 100000, 100000);
        $r1 = $r1->fresh('outfits');
        $extra1 = $this->outfit(20000);
        $this->neighbourOn($extra1);
        $res1 = $this->as($this->supervisor)->postJson(route('rentals.cooldown-approval-request'),
            $this->body($r1, [$r1->outfits->first()->id, $extra1->id], ['rental_id' => $r1->id, 'approval_focus_outfit_ids' => [$extra1->id]]));
        $this->dump('q4 cooldown add 20k', ['status' => $res1->status(), 'msg' => $res1->json('message')]);

        // Same change through a plain Save (no cool-down on this item).
        $r2 = $this->rental();
        $this->withPaidOverdueFine($r2, 100000, 100000);
        $r2 = $r2->fresh('outfits');
        $extra2 = $this->outfit(20000);
        $res2 = $this->as($this->supervisor)->putJson(route('rentals.update', $r2), $this->body($r2, [$r2->outfits->first()->id, $extra2->id]));
        $this->dump('q4 save add 20k', ['status' => $res2->status(), 'mode' => $res2->json('mode')]);

        // Without a fine the same cool-down add is accepted.
        $r3 = $this->rental();
        $extra3 = $this->outfit(20000);
        $this->neighbourOn($extra3);
        $res3 = $this->as($this->supervisor)->postJson(route('rentals.cooldown-approval-request'),
            $this->body($r3, [$r3->outfits->first()->id, $extra3->id], ['rental_id' => $r3->id, 'approval_focus_outfit_ids' => [$extra3->id]]));
        $this->dump('q4 cooldown add 20k no fine', ['status' => $res3->status()]);

        $res2->assertOk();
        $res3->assertOk();
        $this->assertSame(200, $res1->status(), 'cool-down request refused only because the paid fine is counted as rent payment');
    }

    /** Q1b: a supervisor gives an order discount on a fully paid rent with a plain Save: refund leaves the till, nobody else decides. */
    public function test_q1b_supervisor_discount_on_paid_rent_refunds_without_second_person(): void
    {
        $r = $this->rental();
        $res = $this->as($this->supervisor)->putJson(route('rentals.update', $r), $this->body($r, $r->outfits->pluck('id')->all(),
            ['discount' => '100000', 'discount_type' => 'fixed', 'discount_note' => 'goodwill']));
        $this->dump('q1b supervisor discount', ['status' => $res->status(), 'mode' => $res->json('mode'), 'kas' => $this->bal('1100'), 'retur' => $this->bal('4910'),
            'pending' => RentalApprovalRequest::query()->where('status', 'pending')->count()]);
        $this->assertEqualsWithDelta(330000, $this->bal('1100'), 0.01, 'cash left the till on one person\'s decision');
    }

    /** Q1: a supervisor files a cool-down CREATE with money and a discount, then approves it alone. */
    public function test_q1_supervisor_self_approves_cooldown_create_with_money(): void
    {
        $s = $this->outfit(330000);
        $this->neighbourOn($s);
        $start = now()->addDay()->toDateString();
        $end = now()->addDays(3)->toDateString();
        $this->as($this->supervisor)->postJson(route('rentals.cooldown-approval-request'), [
            'outfit_ids' => [$s->id], 'start_date' => $start, 'end_date' => $end,
            'customer_name' => 'Walk in', 'phone' => '0815',
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '280000',
            'discount' => '50000', 'discount_type' => 'fixed', 'discount_note' => 'friend',
            'item_discounts' => [$s->id => ''], 'item_discount_types' => [$s->id => 'fixed'],
        ])->assertOk();
        $a = RentalApprovalRequest::query()->where('status', 'pending')->where('payload->kind', 'create')->first();
        $this->assertNotNull($a);
        $res = $this->approve($this->supervisor, $a);
        $this->dump('q1 self-approve create', ['status' => $res->status(), 'kas' => $this->bal('1100'), 'requested_by' => $a->requested_by_user_id, 'decided_by' => $a->fresh()->decided_by_user_id]);
        $this->assertSame(403, $res->status(), 'self-approval of a cool-down create with money/discount is still allowed');
    }

    // ---- second reviewer probes (P6-P8) ----

    /** P6: a FREE rent (100% discount) that was never paid: a supervisor adds an item with a plain Save. */
    public function test_p6_supervisor_adds_item_to_free_unpaid_rent(): void
    {
        $r = $this->rental(330000, 0, 'down_payment');
        $r->forceFill(['discount' => 100, 'discount_type' => 'percent', 'discount_note' => 'FREE: promo'])->save();
        $r = $r->fresh('outfits');
        $extra = $this->outfit(55000);
        $res = $this->as($this->supervisor)->putJson(route('rentals.update', $r), $this->body($r, [$r->outfits->first()->id, $extra->id],
            ['discount' => '100', 'discount_type' => 'percent', 'discount_note' => 'FREE: promo', 'payment_kind' => 'down_payment']));
        $fresh = $r->fresh('outfits');
        $this->dump('p6 free rent add item', ['status' => $res->status(), 'mode' => $res->json('mode'), 'msg' => $res->json('message'),
            'pending' => $this->pending($r)?->id, 'outfits' => $fresh->outfits->count(),
            'bill' => RentalFinancialSummary::summarize($fresh, 0)['net_incl_tax'], 'kas' => $this->bal('1100')]);
        $this->assertNotNull($this->pending($r), 'item added to a free (Rp 0, nothing paid) rent without a second person');
    }

    /** P7: transaction edit mode ON: a supervisor adds an item to a paid rent and pays it in one go. */
    public function test_p7_edit_mode_supervisor_adds_and_pays(): void
    {
        StoreCache::forever('admin_transaction_edit_mode', true);
        ShiftSession::create(['opened_by_user_id' => $this->supervisor->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $res = $this->as($this->supervisor)->putJson(route('rentals.update', $r), $this->body($r, [$r->outfits->first()->id, $extra->id],
            ['pay_action' => '1', 'payment_amount_add' => '55000']));
        $res2 = null;
        if ($r->fresh('outfits')->outfits->count() === 2 && $this->bal('1100') < 384999) {
            $res2 = $this->as($this->supervisor)->putJson(route('rentals.update', $r), $this->body($r->fresh(), [$r->outfits->first()->id, $extra->id],
                ['pay_action' => '1', 'payment_amount_add' => '55000']));
        }
        $this->dump('p7 edit mode', ['status' => $res->status(), 'mode' => $res->json('mode'), 'msg' => $res->json('message'), 'status2' => $res2?->status(),
            'pending' => $this->pending($r)?->id, 'outfits' => $r->fresh('outfits')->outfits->count(), 'kas' => $this->bal('1100'), 'dr-cr' => $this->ledgerDiff()]);
        $this->assertNotNull($this->pending($r), 'edit mode: item added to a paid rent without a second person');
    }

    /** P8: chain of plain Saves by one supervisor: extend dates, add item (rent no longer settled), shorten back, Pay. */
    public function test_p8_supervisor_chain_extend_add_shorten_pay(): void
    {
        $this->chain('full');
    }

    /** P8b: same chain on a down-payment rent that is fully paid (kind stays down_payment). */
    public function test_p8b_chain_on_settled_down_payment_rent(): void
    {
        $this->chain('down_payment');
    }

    private function chain(string $kind): void
    {
        ShiftSession::create(['opened_by_user_id' => $this->supervisor->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        $r = $this->rental(330000, 330000, $kind);
        $orig = $r->outfits->first()->id;
        $extra = $this->outfit(55000);
        $origEnd = $r->end_date->toDateString();
        $longEnd = $r->start_date->copy()->addDays(5)->toDateString();
        $out = [];
        $steps = [
            'extend' => [[$orig], ['end_date' => $longEnd, 'payment_kind' => 'down_payment']],
            'add' => [[$orig, $extra->id], ['end_date' => $longEnd]],
            'shorten' => [[$orig, $extra->id], ['end_date' => $origEnd]],
            'pay' => [[$orig, $extra->id], ['end_date' => $origEnd, 'pay_action' => '1', 'payment_amount_add' => '55000']],
        ];
        foreach ($steps as $name => [$ids, $ov]) {
            $fr = $r->fresh('outfits');
            $res = $this->as($this->supervisor)->putJson(route('rentals.update', $fr), $this->body($fr, $ids, $ov));
            $out[$name] = [$res->status(), $res->json('mode'), $res->json('message') ?? json_encode($res->json('errors')),
                'outfits' => $fr->fresh('outfits')->outfits->count(), 'paid' => (float) $fr->fresh()->amount_paid, 'kind' => $fr->fresh()->payment_kind,
                'end' => $fr->fresh()->end_date->toDateString(), 'pending' => RentalApprovalRequest::query()->where('status', 'pending')->count()];
        }
        $out['kas'] = $this->bal('1100');
        $out['dr-cr'] = $this->ledgerDiff();
        $this->dump('p8 chain '.$kind, $out);
        $this->assertFalse($r->fresh('outfits')->outfits->count() === 2 && $this->bal('1100') > 384999,
            'item added to a paid rent and paid with no second person, via a chain of saves');
    }

    /** P9: practice (tutorial) edit request: its own requester may approve it (exemption works through the real route). */
    public function test_p9_practice_requester_can_approve_own_practice_edit(): void
    {
        $s1 = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'P1-'.uniqid(), 'price' => 300000, 'stock' => 1, 'is_tutorial' => true]);
        $s2 = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'P2-'.uniqid(), 'price' => 55000, 'stock' => 1, 'is_tutorial' => true]);
        $practice = \App\Models\TutorialRental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => 300000,
            'created_by_user_id' => $this->supervisor->id, 'is_tutorial' => true,
        ]);
        $practice->outfits()->attach($s1->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 300000, 'tax_amount' => 0]);
        $journals = JournalEntry::count();
        $this->as($this->supervisor)->putJson(route('rentals.update', ['rental' => $practice->id, 'tutorial' => 1]), [
            'outfit_ids' => [$s1->id, $s2->id], 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => $practice->start_date->toDateString(), 'end_date' => $practice->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'full', 'payment_entry' => 'increment', 'payment_amount_add' => '0',
            'tutorial_practice' => '1',
        ])->assertOk();
        $a = \App\Models\TutorialRentalApprovalRequest::query()->where('payload->kind', 'edit')->first();
        $this->assertNotNull($a);
        $res = $this->as($this->supervisor)->postJson(route('approvals.rentals.approve', ['approval' => $a->id, 'tutorial' => 1]));
        $this->dump('p9 practice self-approve', ['status' => $res->status(), 'msg' => $res->json('message'), 'outfits' => $practice->fresh('outfits')->outfits->count(), 'journals_added' => JournalEntry::count() - $journals]);
        $this->assertSame(200, $res->status());
        $this->assertSame($journals, JournalEntry::count());
    }

    /** P10: admin files the add-item request, a supervisor (someone else) approves; then Pay; ledger balanced. */
    public function test_p10_admin_request_supervisor_approves(): void
    {
        $r = $this->rental();
        $extra = $this->outfit(55000);
        $this->as($this->admin)->putJson(route('rentals.update', $r), $this->body($r, [$r->outfits->first()->id, $extra->id]))->assertOk();
        $a = $this->pending($r);
        $this->assertNotNull($a);
        $res = $this->approve($this->supervisor, $a);
        $pay = $this->as($this->admin)->putJson(route('rentals.update', $r), $this->body($r->fresh(), [$r->outfits->first()->id, $extra->id], ['pay_action' => '1', 'payment_amount_add' => '55000']));
        $this->dump('p10 admin->supervisor', ['approve' => $res->status(), 'pay' => $pay->status(), 'outfits' => $r->fresh('outfits')->outfits->count(), 'kas' => $this->bal('1100'), 'dr-cr' => $this->ledgerDiff(), 'kind' => $r->fresh()->payment_kind]);
        $this->assertSame(200, $res->status());
        $this->assertEqualsWithDelta(385000, $this->bal('1100'), 0.01);
        $this->assertSame(0.0, $this->ledgerDiff());
    }
}
