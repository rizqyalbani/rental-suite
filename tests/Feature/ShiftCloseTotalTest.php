<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class ShiftCloseTotalTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_close_total_includes_overdue_payments_recorded_in_shift(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->post(route('shifts.open'), ['period' => ShiftSession::PERIOD_MORNING]);
        $shift = ShiftSession::where('opened_by_user_id', $user->id)->whereNull('closed_at')->firstOrFail();

        $outfit = Outfit::create(['code' => 'SHIFT-TOTAL-01', 'stock' => 1, 'price' => 145000]);
        $rental = Rental::create([
            'customer_name' => 'Overdue Guy',
            'phone' => '081200000099',
            'notes' => 'X',
            'start_date' => now()->subDays(3)->toDateString(),
            'end_date' => now()->subDay()->toDateString(),
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'final_payment',
            'amount_paid' => 145000,
            'overdue_amount_paid' => 80000,
            'shift_session_id' => $shift->id,
        ]);
        $rental->outfits()->attach($outfit->id, ['price_incl_tax' => 145000, 'price_excl_tax' => 145000, 'tax_amount' => 0]);

        foreach ([
            ['amount' => 25000, 'kind' => 'down_payment'],
            ['amount' => 120000, 'kind' => 'final_payment'],
            ['amount' => 80000, 'kind' => 'overdue'],
        ] as $rec) {
            RentalPaymentRecord::create([
                'rental_id' => $rental->id,
                'shift_session_id' => $shift->id,
                'received_by_user_id' => $user->id,
                'amount' => $rec['amount'],
                'payment_method' => 'cash',
                'payment_kind' => $rec['kind'],
                'recorded_at' => now()->toDateTimeString(),
            ]);
        }

        $this->actingAs($user)->post(route('shifts.close'));

        $shift->refresh();
        $this->assertNotNull($shift->closed_at);
        $this->assertSame(225000.0, (float) $shift->total_revenue, 'Close total must match the sum of payment records, including overdue payments.');
    }

    public function test_soft_deleting_a_rent_updates_the_closed_shift_stored_total(): void
    {
        $manager = User::factory()->create(['role' => 'management']);
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->post(route('shifts.open'), ['period' => ShiftSession::PERIOD_MORNING]);
        $shift = ShiftSession::where('opened_by_user_id', $user->id)->whereNull('closed_at')->firstOrFail();

        $rentals = [];
        foreach ([10000, 27000] as $i => $amount) {
            $outfit = Outfit::create(['code' => 'SHIFT-DEL-0'.$i, 'stock' => 1, 'price' => 35000]);
            $rental = Rental::create([
                'customer_name' => 'Cust '.$i,
                'phone' => '08120000010'.$i,
                'notes' => 'X',
                'start_date' => now()->addDay()->toDateString(),
                'end_date' => now()->addDays(3)->toDateString(),
                'status' => 'active',
                'payment_method' => 'cash',
                'payment_kind' => 'down_payment',
                'amount_paid' => $amount,
                'shift_session_id' => $shift->id,
            ]);
            $rental->outfits()->attach($outfit->id);
            RentalPaymentRecord::create([
                'rental_id' => $rental->id,
                'shift_session_id' => $shift->id,
                'received_by_user_id' => $user->id,
                'amount' => $amount,
                'payment_method' => 'cash',
                'payment_kind' => 'down_payment',
                'recorded_at' => now()->toDateTimeString(),
            ]);
            $rentals[] = $rental;
        }

        $this->actingAs($user)->post(route('shifts.close'));
        $shift->refresh();
        $this->assertSame(37000.0, (float) $shift->total_revenue);
        $this->assertSame(2, (int) $shift->total_transactions);

        // A paid rent cannot simply be deleted (that money would vanish from the books);
        // only a rent typed in by mistake can, with a written reason
        // (RentalController::destroy(), paidRentalDeleteBlockMessage()).
        $this->actingAs($manager)->deleteJson(route('rentals.destroy', $rentals[0]))
            ->assertStatus(422)
            ->assertJsonPath('needs_wrong_input', true);
        $shift->refresh();
        $this->assertSame(37000.0, (float) $shift->total_revenue);

        $this->actingAs($manager)->deleteJson(route('rentals.destroy', $rentals[0]), [
            'wrong_input' => true,
            'reason' => 'Entered by mistake',
        ])->assertOk();

        $shift->refresh();
        $this->assertSame(27000.0, (float) $shift->total_revenue, 'Stored shift total must drop when a rent is soft-deleted.');
        $this->assertSame(1, (int) $shift->total_transactions);
    }

    public function test_open_card_report_and_close_total_agree_when_a_shift_holds_a_correction(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post(route('shifts.open'), ['period' => ShiftSession::PERIOD_MORNING]);
        $shift = ShiftSession::where('opened_by_user_id', $admin->id)->whereNull('closed_at')->firstOrFail();

        $make = function (string $name, int $price, ?int $shiftId) {
            $outfit = Outfit::create(['code' => 'CORR-'.uniqid(), 'stock' => 1, 'price' => $price]);
            $rental = Rental::create([
                'customer_name' => $name, 'phone' => '0812'.random_int(1000000, 9999999), 'notes' => 'X',
                'start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(7)->toDateString(),
                'status' => 'active', 'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => $price,
                'shift_session_id' => $shiftId,
            ]);
            $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $price, 'price_excl_tax' => $price, 'tax_amount' => 0]);

            return $rental;
        };
        $record = fn (Rental $r, float $amount, string $kind) => RentalPaymentRecord::create([
            'rental_id' => $r->id, 'shift_session_id' => $shift->id, 'received_by_user_id' => $admin->id,
            'amount' => $amount, 'payment_method' => 'cash', 'payment_kind' => $kind, 'recorded_at' => now()->toDateTimeString(),
        ]);

        $own = $make('Created here', 500000, $shift->id);
        $record($own, 500000, 'full');
        $older = $make('Created earlier', 700000, null);
        $record($older, -300000, 'refund');

        $card = $this->actingAs($admin)->get(route('rentals.index'))->assertOk();
        $this->assertEqualsWithDelta(200000, (float) $card->viewData('shiftStats')['total_received'], 0.01, 'open shift card');
        $card->assertSee('Correction');

        $this->actingAs($admin)->post(route('shifts.close'));
        $shift->refresh();
        $this->assertEqualsWithDelta(200000, (float) $shift->total_revenue, 0.01, 'stored close total');

        $report = $this->actingAs($admin)->get(route('shifts.report', $shift))->assertOk();
        $this->assertEqualsWithDelta(200000, (float) $report->viewData('totalReceived'), 0.01, 'report');
        $this->assertEqualsWithDelta(200000, (float) $report->viewData('byAdmin')->sum('received'), 0.01, 'by-admin summary adds up');
        $report->assertSee('Correction');
        $this->actingAs($admin)->get(route('shifts.report.download-pdf', $shift))->assertOk()->assertHeader('content-type', 'application/pdf');
    }
}
