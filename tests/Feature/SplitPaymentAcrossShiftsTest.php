<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalPaymentRecord;
use App\Models\ShiftSession;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalFinancialSummary;
use App\Support\StoreCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * End-to-end money flow: rental created with an item discount AND an order
 * discount, paid with a down payment in shift A, then settled with a final
 * payment in shift B. Each shift's report must only count the cash that
 * actually entered that shift's drawer.
 *
 * Pricing mirrors production: tax 10%, prices include tax.
 *   gross 15.000 → item disc 10% (−1.500) → order disc 10% of subtotal (−1.350)
 *   → total due 12.150. Down payment 10.000 → remaining 2.150.
 *   Final payment: staff type in exactly what was received (2.150, cumulative 12.150) —
 *   anything above the total due is rejected, change is handed back by hand.
 */
class SplitPaymentAcrossShiftsTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private function admin(): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();

        return $user;
    }

    private function enablePricing(): void
    {
        StoreCache::forever('pricing_enabled', true);
        StoreCache::forever('pricing_tax_percent', 10);
        StoreCache::forever('pricing_prices_include_tax', true);
    }

    /** @return array{rental: Rental, outfit: Outfit, shiftA: ShiftSession, user: User} */
    private function createRentalWithDownPaymentInShiftA(): array
    {
        $this->enablePricing();
        $user = $this->admin();

        $outfit = Outfit::create([
            'code' => 'SPLIT-01',
            'description' => 'Split payment test item',
            'price' => 15000,
            'stock' => 1,
        ]);

        $this->actingAs($user)->post(route('shifts.open'), ['period' => ShiftSession::PERIOD_MORNING]);
        $shiftA = ShiftSession::where('opened_by_user_id', $user->id)->whereNull('closed_at')->firstOrFail();

        $this->actingAs($user)->postJson(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Split Payment Customer',
            'phone' => '081234567890',
            'notes' => 'Branch A',
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => '10000',
            'discount' => '10',
            'discount_type' => 'percent',
            'discount_note' => 'order promo',
            'item_discounts' => [$outfit->id => '10'],
            'item_discount_types' => [$outfit->id => 'percent'],
            'item_discount_notes' => [$outfit->id => 'item promo'],
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
        ])->assertOk();

        $rental = Rental::latest('id')->firstOrFail();

        return ['rental' => $rental, 'outfit' => $outfit, 'shiftA' => $shiftA, 'user' => $user];
    }

    /** Closes shift A, opens shift B, and settles the remaining 2.150. */
    private function settleFinalPaymentInShiftB(array $ctx): ShiftSession
    {
        $this->actingAs($ctx['user'])->post(route('shifts.close'));
        $this->actingAs($ctx['user'])->post(route('shifts.open'), ['period' => ShiftSession::PERIOD_AFTERNOON]);
        $shiftB = ShiftSession::where('opened_by_user_id', $ctx['user']->id)->whereNull('closed_at')->firstOrFail();
        $this->assertNotSame((int) $ctx['shiftA']->id, (int) $shiftB->id);

        $rental = $ctx['rental']->fresh();
        $payload = [
            'outfit_ids' => [$ctx['outfit']->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'notes' => $rental->notes,
            'payment_method' => 'cash',
            'payment_kind' => 'final_payment',
            'payment_entry' => 'replace',
            'amount_paid' => '15000',
            'discount' => $rental->discount !== null ? (string) (float) $rental->discount : '',
            'discount_type' => $rental->discount_type ?? 'fixed',
            'discount_note' => $rental->discount_note ?? '',
            'item_discounts' => [$ctx['outfit']->id => '10'],
            'item_discount_types' => [$ctx['outfit']->id => 'percent'],
            'item_discount_notes' => [$ctx['outfit']->id => 'item promo'],
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
        ];

        // A 5.000 note on top of the 10.000 down payment goes over the 12.150 total due:
        // the client's exact-payment rule rejects it and nothing is recorded.
        $this->actingAs($ctx['user'])->putJson(route('rentals.update', $rental), $payload)->assertStatus(422);
        $this->assertSame(10000.0, (float) $rental->fresh()->amount_paid);
        $this->assertSame(0, RentalPaymentRecord::where('rental_id', $rental->id)->where('shift_session_id', $shiftB->id)->count());

        // Staff type in exactly what was received: the 2.150 that is left (cumulative 12.150).
        $payload['amount_paid'] = '12150';
        $this->actingAs($ctx['user'])->putJson(route('rentals.update', $rental), $payload)->assertOk();

        return $shiftB;
    }

    private function reportData(ShiftSession $shift, User $viewer): array
    {
        $response = $this->actingAs($viewer)
            ->get(route('shifts.report', ['shift' => $shift->id]))
            ->assertOk();

        return [
            'totalReceived' => (float) $response->viewData('totalReceived'),
            'rentalReports' => $response->viewData('rentalReports'),
        ];
    }

    public function test_create_persists_both_discounts_and_total_due(): void
    {
        $ctx = $this->createRentalWithDownPaymentInShiftA();
        $rental = $ctx['rental']->fresh()->load('outfits');

        // Order discount must survive the store request.
        $this->assertSame(10.0, (float) $rental->discount, 'Order discount value was not persisted.');
        $this->assertSame('percent', $rental->discount_type);
        $this->assertSame('order promo', $rental->discount_note);

        // Item discount lives on the pivot.
        $pivot = $rental->outfits->first()->pivot;
        $this->assertSame(10.0, (float) $pivot->discount, 'Item discount value was not persisted.');
        $this->assertSame('percent', $pivot->discount_type);

        // 15.000 − 1.500 (item 10%) − 1.350 (order 10% of post-item subtotal) = 12.150
        $due = (float) RentalFinancialSummary::summarize($rental, 10)['net_incl_tax'];
        $this->assertSame(12150.0, $due, 'Total due should apply both discounts.');

        $this->assertSame(10000.0, (float) $rental->amount_paid);
        $this->assertSame(10000.0, (float) $rental->down_payment_amount);
        $this->assertSame('down_payment', $rental->payment_kind);
        $this->assertSame((int) $ctx['shiftA']->id, (int) $rental->shift_session_id);
    }

    public function test_down_payment_creates_a_payment_record_in_shift_a(): void
    {
        $ctx = $this->createRentalWithDownPaymentInShiftA();

        $records = RentalPaymentRecord::where('rental_id', $ctx['rental']->id)->get();
        $this->assertCount(1, $records, 'Down payment at creation must produce a payment record.');
        $this->assertSame(10000.0, (float) $records->first()->amount);
        $this->assertSame((int) $ctx['shiftA']->id, (int) $records->first()->shift_session_id);
    }

    public function test_final_payment_in_shift_b_records_only_the_remaining_balance(): void
    {
        $ctx = $this->createRentalWithDownPaymentInShiftA();
        $shiftB = $this->settleFinalPaymentInShiftB($ctx);

        $rental = $ctx['rental']->fresh();
        // Settled exactly at the total due; nothing above it is ever recorded.
        $this->assertSame('final_payment', $rental->payment_kind);

        $recordsB = RentalPaymentRecord::where('rental_id', $rental->id)
            ->where('shift_session_id', $shiftB->id)
            ->get();
        $this->assertCount(1, $recordsB, 'Final payment must produce a payment record in shift B.');
        $this->assertSame(
            2150.0,
            (float) $recordsB->first()->amount,
            'Shift B must only receive the remaining balance (due 12.000 − DP 10.000), never the change.'
        );

        // Total recorded across all shifts must equal the total due.
        $totalRecorded = (float) RentalPaymentRecord::where('rental_id', $rental->id)->sum('amount');
        $this->assertSame(12150.0, $totalRecorded, 'Sum of payment records must equal the total due.');
    }

    public function test_final_payment_with_missing_discount_field_cannot_wipe_the_order_discount(): void
    {
        $ctx = $this->createRentalWithDownPaymentInShiftA();

        $this->actingAs($ctx['user'])->post(route('shifts.close'));
        $this->actingAs($ctx['user'])->post(route('shifts.open'), ['period' => ShiftSession::PERIOD_AFTERNOON]);
        $shiftB = ShiftSession::where('opened_by_user_id', $ctx['user']->id)->whereNull('closed_at')->firstOrFail();

        $rental = $ctx['rental']->fresh();
        // Simulate a client that submits the final payment with EMPTY order
        // discount fields (regression: this used to wipe the discount, raising
        // the due from 12.150 to 13.500 and recording 3.500 instead of 2.150).
        // Admin does not get the down-payment discount lock (that is for
        // management/supervisor, see the next test); instead the omitted discount no
        // longer matches the saved one, so this is not a pure payment any more and
        // the admin's save is parked as an edit request. 13.500 is the due that the
        // payment checks compute from the submitted (empty) discount.
        $this->actingAs($ctx['user'])->putJson(route('rentals.update', $rental), [
            'outfit_ids' => [$ctx['outfit']->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'notes' => $rental->notes,
            'payment_method' => 'cash',
            'payment_kind' => 'final_payment',
            'payment_entry' => 'replace',
            'amount_paid' => '13500',
            'discount' => '',
            'discount_type' => 'percent',
            'discount_note' => '',
            'item_discounts' => [$ctx['outfit']->id => '10'],
            'item_discount_types' => [$ctx['outfit']->id => 'percent'],
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
        ])->assertOk()->assertJson(['mode' => 'edit_approval_request_sent']);

        $rental = $rental->fresh();
        $this->assertSame(10.0, (float) $rental->discount, 'The order discount must survive a submit that left it out.');
        $this->assertSame('order promo', $rental->discount_note);
        $this->assertSame(10000.0, (float) $rental->amount_paid, 'Nothing is applied before the request is approved.');
        $this->assertSame(0, RentalPaymentRecord::where('rental_id', $rental->id)->where('shift_session_id', $shiftB->id)->count());
    }

    public function test_final_payment_by_management_with_missing_discount_field_keeps_the_locked_discount(): void
    {
        $ctx = $this->createRentalWithDownPaymentInShiftA();
        $manager = User::factory()->create(['role' => 'management']);

        $rental = $ctx['rental']->fresh();
        // Management saves directly, so the order discount is locked while the down payment
        // is open: the empty discount fields are replaced by the saved ones before the
        // payment checks, and the true remaining balance (2.150) is recorded.
        $this->actingAs($manager)->putJson(route('rentals.update', $rental), [
            'outfit_ids' => [$ctx['outfit']->id],
            'customer_name' => $rental->customer_name,
            'phone' => $rental->phone,
            'notes' => $rental->notes,
            'payment_method' => 'cash',
            'payment_kind' => 'final_payment',
            'payment_entry' => 'replace',
            'amount_paid' => '12150',
            'discount' => '',
            'discount_type' => 'percent',
            'discount_note' => '',
            'item_discounts' => [$ctx['outfit']->id => '10'],
            'item_discount_types' => [$ctx['outfit']->id => 'percent'],
            'start_date' => $rental->start_date->toDateString(),
            'end_date' => $rental->end_date->toDateString(),
        ])->assertOk();

        $rental = $rental->fresh();
        $this->assertSame(10.0, (float) $rental->discount, 'Locked order discount must survive the final payment.');
        $this->assertSame('order promo', $rental->discount_note);
        $this->assertSame(12150.0, (float) $rental->amount_paid);
        $this->assertSame(
            12150.0,
            (float) RentalPaymentRecord::where('rental_id', $rental->id)->sum('amount'),
            'Payment records must add up to the true total due, never the wiped-discount 13.500.'
        );
    }

    public function test_each_shift_report_counts_only_its_own_cash(): void
    {
        $ctx = $this->createRentalWithDownPaymentInShiftA();
        $shiftB = $this->settleFinalPaymentInShiftB($ctx);

        // Shift A keeps the down payment even after the final payment happened elsewhere.
        $reportA = $this->reportData($ctx['shiftA'], $ctx['user']);
        $this->assertSame(
            10000.0,
            $reportA['totalReceived'],
            'Shift A must still show the 10.000 down payment after the rental is settled in shift B.'
        );

        // Shift B shows only the remaining 2.000 (rental appears as from another shift).
        $reportB = $this->reportData($shiftB, $ctx['user']);
        $this->assertSame(
            2150.0,
            $reportB['totalReceived'],
            'Shift B must show only the remaining balance received during that shift.'
        );
        $external = collect($reportB['rentalReports'])
            ->first(fn ($row) => (int) $row['rental']->id === (int) $ctx['rental']->id);
        $this->assertNotNull($external, 'The settled rental must appear on shift B\'s report.');
        $this->assertTrue(! empty($external['from_other_shift']), 'Rental settled in shift B should be flagged as from another shift.');
        $this->assertSame(0.0, (float) $external['outstanding'], 'Nothing should remain outstanding after settlement.');
    }
}
