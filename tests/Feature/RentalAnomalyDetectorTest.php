<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalPaymentRecord;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalAnomalyDetector;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The dashboard's "Rentals to Review" list: each pattern found while
 * reconciling September by hand is flagged, and a clean rent is not.
 */
class RentalAnomalyDetectorTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private int $n = 0;

    private int $shiftId;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-29 10:00:00');
        StoreContext::set(null);
        $this->store = Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        StoreContext::set($this->store->id);
        $this->shiftId = $this->openShiftAs(User::factory()->create(['role' => 'admin']))->id;
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A 300rb rent booked on $created, with the given [amount, recorded_at, created_at?, shift?] payments. */
    private function rent(string $name, string $created, string $status, array $payments, string $phone = '0811'): Rental
    {
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => 'S'.(++$this->n), 'price' => 300000, 'stock' => 1]);
        $rental = Rental::create([
            'store_id' => $this->store->id,
            'customer_name' => $name,
            'phone' => $phone.$this->n,
            'start_date' => '2026-09-10',
            'end_date' => '2026-09-12',
            'status' => $status,
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => array_sum(array_column($payments, 0)),
        ]);
        $rental->forceFill(['created_at' => $created, 'updated_at' => $created])->saveQuietly();
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 300000, 'price_excl_tax' => 300000, 'tax_amount' => 0]);
        foreach ($payments as $p) {
            $rec = RentalPaymentRecord::create([
                'rental_id' => $rental->id,
                'shift_session_id' => array_key_exists(3, $p) ? $p[3] : $this->shiftId,
                'amount' => $p[0],
                'payment_method' => 'cash',
                'payment_kind' => 'down_payment',
                'recorded_at' => $p[1],
            ]);
            $rec->forceFill(['created_at' => $p[2] ?? $p[1]])->saveQuietly();
        }

        return $rental->fresh();
    }

    private function flagsFor(Rental $rental): array
    {
        $row = RentalAnomalyDetector::forPeriod('2026-09-01', '2026-09-15')
            ->first(fn ($r) => $r['rental']->id === $rental->id);

        return $row['flags'] ?? [];
    }

    public function test_clean_rent_is_not_flagged(): void
    {
        $clean = $this->rent('CLEAN', '2026-09-05 10:00:00', 'returned', [[100000, '2026-09-05 10:00:00'], [200000, '2026-09-11 10:00:00']]);

        $this->assertSame([], $this->flagsFor($clean));
    }

    public function test_each_problem_pattern_is_flagged(): void
    {
        $deleted = $this->rent('DELETED', '2026-09-04 10:00:00', 'active', [[100000, '2026-09-04 10:00:00']]);
        $deleted->delete();
        $overpaid = $this->rent('OVER', '2026-09-05 10:00:00', 'returned', [[330000, '2026-09-05 10:00:00']]);
        $unpaid = $this->rent('SHORT', '2026-09-06 10:00:00', 'returned', [[100000, '2026-09-06 10:00:00']]);
        $late = $this->rent('LATE', '2026-09-07 10:00:00', 'returned', [[100000, '2026-09-07 10:00:00'], [200000, '2026-09-08 10:00:00', '2026-09-27 10:00:00']]);
        $noShift = $this->rent('NOSHIFT', '2026-09-08 10:00:00', 'returned', [[300000, '2026-09-08 10:00:00', null, null]]);
        $changed = $this->rent('CHANGED', '2026-09-09 10:00:00', 'active', [[100000, '2026-09-09 10:00:00']]);
        RentalActivityLog::record($changed, 'updated', ['outfit_ids' => [['S1'], ['S1', 'PIN']]], null, User::factory()->create()->id)
            ->forceFill(['created_at' => '2026-09-24 10:00:00'])->saveQuietly();

        $this->assertStringContainsString('Deleted although it had already received Rp 100.000', implode(' ', $this->flagsFor($deleted)));
        $this->assertStringContainsString('Overpaid by Rp 30.000', implode(' ', $this->flagsFor($overpaid)));
        $this->assertStringContainsString('Already returned but still short by Rp 200.000', implode(' ', $this->flagsFor($unpaid)));
        $this->assertStringContainsString('was only entered on 27 Sep', implode(' ', $this->flagsFor($late)));
        $this->assertStringContainsString('is not in any shift', implode(' ', $this->flagsFor($noShift)));
        $this->assertStringContainsString('Changed after the period, 24 Sep', implode(' ', $this->flagsFor($changed)));
    }

    public function test_bulk_backfilled_payments_are_not_reported_as_late(): void
    {
        // Six payments created in the same minute = the ledger sync, not a person typing late.
        $rents = collect(range(1, 6))->map(fn ($i) => $this->rent('SYNC'.$i, '2026-09-0'.$i.' 10:00:00', 'returned',
            [[300000, '2026-09-0'.$i.' 10:00:00', '2026-09-24 22:31:00']]));

        foreach ($rents as $rental) {
            $this->assertSame([], $this->flagsFor($rental));
        }
    }

    public function test_dashboard_shows_the_list(): void
    {
        $deleted = $this->rent('SAMPLE CUSTOMER', '2026-09-04 10:00:00', 'active', [[100000, '2026-09-04 10:00:00']]);
        $deleted->delete();

        $this->actingAs(User::factory()->create(['role' => 'management']))
            ->get(route('accounting.dashboard', ['date_from' => '2026-09-01', 'date_to' => '2026-09-15']))
            ->assertOk()
            ->assertSee('Rentals to Review (1)')
            ->assertSee('SAMPLE CUSTOMER')
            ->assertSee('Deleted although it had already received Rp 100.000', false)
            ->assertSee('deleted=1', false);
    }

    public function test_mark_checked_hides_rent_until_a_new_problem_appears(): void
    {
        $rental = $this->rent('SHORT', '2026-09-06 10:00:00', 'returned', [[100000, '2026-09-06 10:00:00']]);
        $user = User::factory()->create(['role' => 'management']);
        $range = ['date_from' => '2026-09-01', 'date_to' => '2026-09-15'];
        $dashboard = fn () => $this->actingAs($user)->get(route('accounting.dashboard', $range));

        $dashboard()->assertSee('Rentals to Review (1)');

        $this->actingAs($user)->post(route('accounting.perlu-dicek.mark', $rental->id), $range)
            ->assertRedirect(route('accounting.dashboard', $range).'#perluDicekList');
        $dashboard()->assertSee('Rentals to Review (0)')->assertSee('Checked (1)');

        // A different problem later (more still owed) brings it back.
        RentalPaymentRecord::where('rental_id', $rental->id)->update(['amount' => 50000]);
        $rental->forceFill(['amount_paid' => 50000])->saveQuietly();
        $dashboard()->assertSee('Rentals to Review (1)');

        $this->actingAs($user)->delete(route('accounting.perlu-dicek.unmark', $rental->id), $range)->assertRedirect();
        $dashboard()->assertSee('Rentals to Review (1)')->assertDontSee('Checked (');
    }

    public function test_admin_cannot_mark_checked(): void
    {
        $rental = $this->rent('SHORT', '2026-09-06 10:00:00', 'returned', [[100000, '2026-09-06 10:00:00']]);

        $this->actingAs(User::factory()->create(['role' => 'admin']))
            ->post(route('accounting.perlu-dicek.mark', $rental->id), ['date_from' => '2026-09-01', 'date_to' => '2026-09-15'])
            ->assertForbidden();
    }
}
