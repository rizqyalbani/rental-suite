<?php

namespace Tests\Repro;

use App\Models\Account;
use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Throwaway repro tests for review d14 (9c53531). Not part of the default suites. */
class D14SwapPopupReproTest extends TestCase
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
        return Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'AI-'.uniqid(), 'price' => $price, 'stock' => 1]);
    }

    private function rental(int $price, int $paid, int $days = 3, int $tax = 0): Rental
    {
        $start = now()->addDays(1)->startOfDay();
        $outfit = $this->outfit($price);
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Paid Customer', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => $paid,
        ]);
        $excl = $tax > 0 ? round($price / (1 + $tax / 100), 2) : $price;
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $price, 'price_excl_tax' => $excl, 'tax_amount' => round($price - $excl, 2)]);
        $rental->load('outfits');
        $blocks = max(1, intdiv($days + 2, 3));
        RentalPaymentRecorder::recordIfNonZero($rental, $paid, null, null, 'cash', 'full', totalDue: (float) ($price * $blocks));

        return $rental->fresh('outfits');
    }

    private function save(User $who, Rental $r, array $outfitIds, array $override = [])
    {
        $p = [
            'outfit_ids' => $outfitIds, 'customer_name' => $r->customer_name, 'phone' => $r->phone,
            'start_date' => $r->start_date->toDateString(), 'end_date' => $r->end_date->toDateString(),
            'payment_method' => $r->payment_method, 'payment_kind' => $r->payment_kind,
            'discount' => '', 'discount_type' => 'fixed', 'payment_entry' => 'increment', 'payment_amount_add' => '0',
        ];
        foreach ($outfitIds as $id) {
            $p['item_discounts'][$id] = '';
            $p['item_discount_types'][$id] = 'fixed';
        }

        return $this->as($who)->putJson(route('rentals.update', $r), array_merge($p, $override));
    }

    public function test_D14_02_paid_overdue_fine_cheaper_swap_says_collect_with_pay(): void
    {
        // Rent 330.000 paid in full, overdue fine 100.000 also paid -> 430.000 received.
        $r = $this->rental(330000, 330000);
        $r->forceFill(['overdue_fee_amount' => 100000, 'overdue_amount_paid' => 100000])->save();
        $r = $r->fresh('outfits');
        $cheaper = $this->outfit(280000); // new bill 280.000 + fine 100.000 = 380.000 < 430.000 received -> refund 50.000
        $res = $this->save($this->supervisor, $r, [$cheaper->id]);
        fwrite(STDERR, "\nD14-02 status=".$res->status().' mode='.$res->json('mode').' msg='.$res->json('message')."\n");
        $this->assertStringContainsString('refunded', (string) $res->json('message'));
    }

    public function test_D14_02b_paid_overdue_fine_swap_down_by_exactly_the_fine_says_nothing_due(): void
    {
        $r = $this->rental(330000, 330000);
        $r->forceFill(['overdue_fee_amount' => 100000, 'overdue_amount_paid' => 100000])->save();
        $r = $r->fresh('outfits');
        $cheaper = $this->outfit(230000); // 230.000 + 100.000 = 330.000 vs 430.000 received -> refund 100.000
        $res = $this->save($this->supervisor, $r, [$cheaper->id]);
        fwrite(STDERR, "\nD14-02b status=".$res->status().' msg='.$res->json('message')."\n");
        $this->assertStringContainsString('refunded', (string) $res->json('message'));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('samePrices')]
    public function test_tax10_same_price_swap_says_nothing_due(int $price): void
    {
        StoreCache::forever('pricing_tax_percent', 10);
        $r = $this->rental($price, $price, 3, 10);
        $same = $this->outfit($price);
        $res = $this->save($this->supervisor, $r, [$same->id]);
        fwrite(STDERR, "\nTAX10 price=$price status=".$res->status().' msg='.$res->json('message')."\n");
        $this->assertStringContainsString('nothing more is due', (string) $res->json('message'));
    }

    public static function samePrices(): array
    {
        return [[33333], [99999], [1100000], [333333]];
    }

    public function test_six_day_two_block_cheaper_swap_says_refund_and_approval_outcome(): void
    {
        $r = $this->rental(330000, 660000, 6);
        $cheaper = $this->outfit(300000); // 2 blocks -> 600.000 vs 660.000 paid
        $res = $this->save($this->supervisor, $r, [$cheaper->id]);
        fwrite(STDERR, "\n6DAY status=".$res->status().' msg='.$res->json('message')."\n");
        $this->assertStringContainsString('refunded', (string) $res->json('message'));

        $a = RentalApprovalRequest::query()->where('status', 'pending')->latest('id')->first();
        $this->as($this->manager)->postJson(route('approvals.rentals.approve', $a));
        $after = Rental::withoutGlobalScopes()->find($r->id);
        $recs = RentalPaymentRecord::where('rental_id', $r->id)->pluck('amount')->all();
        fwrite(STDERR, "6DAY after approve: amount_paid=".$after->amount_paid.' kind='.$after->payment_kind.' records='.json_encode($recs)."\n");
        $this->assertTrue(true);
    }

    public function test_dp_rent_switch_to_full_below_due_hint(): void
    {
        $r = $this->rental(330000, 100000);
        $r->forceFill(['payment_kind' => 'full'])->save(); // a legacy "full" rent recorded below the total
        $r = $r->fresh('outfits');
        $res = $this->save($this->supervisor, $r, [$r->outfits->first()->id], ['payment_kind' => 'full', 'payment_amount_add' => '50000']);
        fwrite(STDERR, "\nFULLBELOW status=".$res->status().' err='.json_encode($res->json('errors'))."\n");
        $this->assertTrue(true);
    }
    public function test_D14_03_pay_button_partial_on_extended_full_rent(): void
    {
        foreach (['100000', '330000'] as $amt) {
            $r = $this->rental(330000, 330000);
            $res = $this->save($this->supervisor, $r, [$r->outfits->first()->id], [
                'end_date' => $r->end_date->copy()->addDays(3)->toDateString(),
                'pay_action' => 1, 'payment_amount_add' => $amt,
            ]);
            fwrite(STDERR, "\nPAYEXT amt=$amt status=".$res->status().' mode='.$res->json('mode').' err='.json_encode($res->json('errors')).' paid_after='.Rental::withoutGlobalScopes()->find($r->id)->amount_paid."\n");
        }
        $this->assertTrue(true);
    }
}
