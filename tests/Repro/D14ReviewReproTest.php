<?php

namespace Tests\Repro;

use App\Http\Controllers\RentalController;
use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Outfit;
use App\Models\TutorialRental;
use App\Models\TutorialShiftSession;
use App\Models\User;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/** Throwaway repro tests for review d14 (5587130). Not part of the default suites. */
class D14ReviewReproTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    protected function tearDown(): void
    {
        StoreContext::set(null, false);
        parent::tearDown();
    }

    private function setupSameIdShifts(bool $sameRentId): array
    {
        $adminA = User::factory()->create(['role' => 'admin', 'name' => 'Real Admin']);
        $adminB = User::factory()->create(['role' => 'admin', 'name' => 'Practising Admin']);

        $real = ShiftSession::withoutGlobalScopes()->create([
            'store_id' => 1, 'opened_by_user_id' => $adminA->id, 'opened_at' => now()->subDays(3), 'period' => ShiftSession::PERIOD_MORNING,
        ]);
        $real->forceFill(['closed_at' => now()->subDays(3)->addHours(6), 'closed_by_user_id' => $adminA->id, 'total_revenue' => 300000, 'total_transactions' => 1])->save();

        $realOutfit = Outfit::withoutGlobalScopes()->create(['store_id' => 1, 'code' => 'R'.uniqid(), 'price' => 300000, 'stock' => 1]);
        $realRent = Rental::create([
            'store_id' => 1, 'customer_name' => 'Budi Real Customer', 'phone' => '0812999', 'notes' => 'X',
            'start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(7)->toDateString(),
            'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => 300000,
            'shift_session_id' => null, // created in another shift: only its PAYMENT is in shift $real
        ]);
        $realRent->outfits()->attach($realOutfit->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 300000, 'tax_amount' => 0]);
        RentalPaymentRecord::create([
            'rental_id' => $realRent->id, 'shift_session_id' => $real->id, 'received_by_user_id' => $adminA->id,
            'amount' => 300000, 'payment_method' => 'cash', 'payment_kind' => 'full', 'recorded_at' => now()->toDateTimeString(),
        ]);

        $practice = new TutorialShiftSession;
        $practice->forceFill(['opened_by_user_id' => $adminB->id, 'opened_at' => now(), 'period' => 'morning', 'store_id' => 1]);
        $practice->id = $real->id;
        $practice->save();

        $pOutfit = Outfit::withoutGlobalScopes()->create(['store_id' => 1, 'code' => 'P'.uniqid(), 'price' => 120000, 'stock' => 1]);
        $pr = new TutorialRental;
        $pr->forceFill([
            'customer_name' => 'Sample Customer', 'phone' => '000000000',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'active', 'handled_by_user_id' => $adminB->id, 'created_by_user_id' => $adminB->id,
            'shift_session_id' => $practice->id, 'payment_method' => 'cash', 'payment_kind' => 'dp', 'amount_paid' => 50000,
        ]);
        if ($sameRentId) {
            $pr->id = $realRent->id;
        } else {
            $pr->id = $realRent->id + 1000;
        }
        $pr->save();
        $pr->outfits()->attach($pOutfit->id, ['price_incl_tax' => 120000, 'price_excl_tax' => 120000, 'tax_amount' => 0]);

        return [$practice, $realRent, $adminB];
    }

    private function cardStats(TutorialShiftSession $practice): array
    {
        $m = new \ReflectionMethod(RentalController::class, 'computeShiftStats');
        $m->setAccessible(true);

        return StoreContext::runAs(1, fn () => $m->invoke(app(RentalController::class), $practice));
    }

    public function test_D14_01_practice_running_card_shows_real_payment_of_the_same_shift_id(): void
    {
        [$practice] = $this->setupSameIdShifts(false);
        $stats = $this->cardStats($practice);
        $names = collect($stats['details'])->map(fn ($d) => $d['rental']->customer_name)->all();
        fwrite(STDERR, "\nD14-01 card total_received=".$stats['total_received'].' names='.json_encode($names)."\n");
        // Expected after the C4-03 intent: 50.000 and only the practice rent.
        $this->assertEqualsWithDelta(50000, (float) $stats['total_received'], 0.01, 'practice card shows real money');
    }

    public function test_D14_01b_practice_running_card_with_same_rent_id_reads_real_record(): void
    {
        [$practice] = $this->setupSameIdShifts(true);
        $stats = $this->cardStats($practice);
        fwrite(STDERR, "\nD14-01b card total_received=".$stats['total_received']."\n");
        $this->assertEqualsWithDelta(50000, (float) $stats['total_received'], 0.01, 'practice card uses the real record of the same rent id');
    }

    public function test_D14_01c_close_total_and_card_disagree_after_fix(): void
    {
        [$practice, , $adminB] = $this->setupSameIdShifts(false);
        $card = $this->cardStats($practice);
        $this->actingAs($adminB)->post(route('shifts.close'), ['tutorial_practice' => 1])->assertSessionHasNoErrors();
        $closed = TutorialShiftSession::find($practice->id);
        fwrite(STDERR, "\nD14-01c card=".$card['total_received'].' closed='.$closed->total_revenue."\n");
        $this->assertEqualsWithDelta((float) $closed->total_revenue, (float) $card['total_received'], 0.01, 'card and close total disagree');
    }
}
