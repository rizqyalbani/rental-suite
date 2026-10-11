<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\TutorialRental;
use App\Models\TutorialRentalApprovalRequest;
use App\Models\User;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Practice (tour) rents live in `tutorial_rentals` with their own id sequence, so practice rent #1
 * and real rent #1 exist side by side. Nothing a practice screen does may reach the real rent.
 * (Overnight review 2026-10-09: 03a P4/P4b, 03b C2-6, 05a C6-02/C6-03.)
 */
class PracticeSaveIsolationTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    private User $supervisor;

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
        $this->supervisor = User::factory()->create(['role' => 'supervisor']);
        $this->admin = User::factory()->create(['role' => 'admin']);
        foreach ([$this->manager, $this->supervisor, $this->admin] as $u) {
            $u->stores()->attach($this->store->id);
        }
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

    /** Real rent #1: 2 outfits @ 1.100.000 incl. PPN, 9 days, DP 1.980.000 recorded. */
    private function realRental(): Rental
    {
        $start = now()->addDay()->startOfDay();
        $rental = Rental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Real Customer', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(8)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => 1980000,
        ]);
        foreach ([1, 2] as $i) {
            $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'R'.$i.'-'.uniqid(), 'price' => 1100000, 'stock' => 1]);
            $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 1100000, 'price_excl_tax' => 1000000, 'tax_amount' => 100000]);
        }
        $rental->load('outfits');
        RentalPaymentRecorder::recordIfNonZero($rental, 1980000, null, null, 'cash', 'down_payment', totalDue: 6600000.0);

        return $rental->fresh('outfits');
    }

    /** Practice rent that gets the same id number as the real one. */
    private function practiceRental(User $owner, float $paid = 0, int $outfitCount = 1): TutorialRental
    {
        $t = TutorialRental::create([
            'store_id' => $this->store->id, 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => now()->addDay()->toDateString(), 'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => $paid,
            'created_by_user_id' => $owner->id, 'is_tutorial' => true,
        ]);
        for ($i = 1; $i <= $outfitCount; $i++) {
            $s = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'T'.$i.'-'.uniqid(), 'price' => 300000, 'stock' => 1, 'is_tutorial' => true]);
            $t->outfits()->attach($s->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 272727.27, 'tax_amount' => 27272.73]);
        }

        return $t->fresh('outfits');
    }

    private function assertRealRentUntouched(Rental $real, int $paymentRecords, int $journalEntries): void
    {
        $fresh = Rental::withTrashed()->find($real->id);
        $this->assertNull($fresh->deleted_at, 'real rent must not be deleted');
        $this->assertSame('active', $fresh->status);
        $this->assertSame($paymentRecords, RentalPaymentRecord::where('rental_id', $real->id)->count());
        $this->assertSame($journalEntries, JournalEntry::withoutGlobalScopes()->count(), 'no journal entry added or reversed');
        $this->assertSame(0, RentalApprovalRequest::query()->count(), 'nothing may land in the real approval queue');
    }


    public function test_practice_save_does_not_touch_the_real_rent_with_the_same_id(): void
    {
        $real = $this->realRental();
        $practice = $this->practiceRental($this->manager, paid: 100000, outfitCount: 2);
        $this->assertSame($real->id, $practice->id);
        $realOutfitIds = $real->outfits->pluck('id')->sort()->values()->all();

        $res = $this->as($this->manager)->putJson(route('rentals.update', ['rental' => $practice->id, 'tutorial' => 1]), [
            'outfit_ids' => $practice->outfits->pluck('id')->all(), 'customer_name' => 'Practice EDITED', 'phone' => '0800',
            'start_date' => $practice->start_date->toDateString(), 'end_date' => $practice->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'payment_entry' => 'increment', 'payment_amount_add' => '0',
        ]);
        fwrite(STDERR, "\nstatus=".$res->status()."\n");
        $freshReal = Rental::query()->find($real->id)->load('outfits');
        fwrite(STDERR, 'real name='.$freshReal->customer_name.' outfits='.json_encode($freshReal->outfits->map(fn ($s) => [$s->id, $s->pivot->price_incl_tax])->all())."\n");
        $freshPractice = TutorialRental::query()->find($practice->id)->load('outfits');
        fwrite(STDERR, 'practice name='.$freshPractice->customer_name."\n");
        $this->assertSame('Real Customer', $freshReal->customer_name);
        $this->assertSame($realOutfitIds, $freshReal->outfits->pluck('id')->sort()->values()->all());
    }
}
