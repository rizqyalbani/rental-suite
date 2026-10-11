<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * End to end through the real approve endpoint: an approved edit must leave
 * the ledger agreeing with the rental's new total. The original bug: an
 * approved edit that changed the total but not the amount paid (the Devi
 * case) journaled nothing, so Rental Receivable / Customer Deposits stayed on the old total.
 */
class RentalEditApprovalLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $approver;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);

        foreach ([
            ['1100', 'Store Cash', 'asset', 'debit', true],
            ['1200', 'Bank', 'asset', 'debit', true],
            ['1300', 'Rental Receivable', 'asset', 'debit', false],
            ['2300', 'Customer Deposits', 'liability', 'credit', false],
            ['4100', 'Rental Revenue', 'revenue', 'credit', false],
            ['4200', 'Late Fee Revenue', 'revenue', 'credit', false],
            ['4400', 'Cancellation Revenue (Forfeited Deposits)', 'revenue', 'credit', false],
            ['4910', 'Rental Refunds', 'revenue', 'debit', false],
        ] as [$code, $name, $type, $normal, $cash]) {
            Account::create(['code' => $code, 'name' => $name, 'type' => $type, 'normal_balance' => $normal, 'is_cash_equivalent' => $cash, 'is_active' => true]);
        }

        $this->store = Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        $this->approver = User::factory()->create(['role' => 'management']);
        StoreContext::set($this->store->id);
    }

    protected function tearDown(): void
    {
        // StoreContext is a static that outlives RefreshDatabase's rollback.
        StoreContext::set(null);
        parent::tearDown();
    }

    private function outfit(int $price, string $code): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $this->store->id, 'code' => $code, 'price' => $price, 'stock' => 1]);
    }

    /** A rental with one down payment already journaled, like the real store() flow leaves it. */
    private function paidRental(array $outfits, int $paid, string $method, string $kind, int $startInDays = 10): Rental
    {
        $rental = Rental::create([
            'store_id' => $this->store->id,
            'customer_name' => 'E2E Customer',
            'phone' => '081234567890',
            'start_date' => now()->addDays($startInDays)->toDateString(),
            'end_date' => now()->addDays($startInDays + 2)->toDateString(),
            'status' => 'active',
            'payment_method' => $method,
            'payment_kind' => $kind,
            'amount_paid' => $paid,
        ]);
        $total = 0;
        foreach ($outfits as $outfit) {
            $rental->outfits()->attach($outfit->id, ['price_incl_tax' => $outfit->price, 'price_excl_tax' => $outfit->price, 'tax_amount' => 0]);
            $total += (int) $outfit->price;
        }
        RentalPaymentRecorder::recordIfNonZero($rental, min($paid, $total), null, null, $method, $kind, totalDue: (float) $total);

        return $rental->fresh('outfits');
    }

    private function approveEdit(Rental $rental, array $outfitIds, array $rentalOverrides = [], array $takeover = []): \Illuminate\Testing\TestResponse
    {
        $payload = [
            'kind' => 'edit',
            'rental_id' => $rental->id,
            'rental' => array_merge([
                'customer_name' => $rental->customer_name,
                'phone' => $rental->phone,
                'start_date' => $rental->start_date->toDateString(),
                'end_date' => $rental->end_date->toDateString(),
                'status' => 'active',
                'payment_method' => $rental->payment_method,
                'payment_kind' => $rental->payment_kind,
                'amount_paid' => (float) $rental->amount_paid,
                'discount' => null,
                'discount_type' => 'fixed',
            ], $rentalOverrides),
            'outfit_ids' => $outfitIds,
            'item_discounts' => [],
            'item_discount_types' => [],
            'item_discount_notes' => [],
            'takeover_from' => $takeover,
        ];

        // An edit request is filed by someone else (Admin); the requester can never approve it.
        $requester = User::factory()->create(['role' => 'admin']);
        $approval = RentalApprovalRequest::create([
            'store_id' => $this->store->id,
            'requested_by_user_id' => $requester->id,
            'payload' => $payload,
            'status' => 'pending',
            'is_tutorial' => false,
        ]);

        return $this->actingAs($this->approver)
            ->withSession(['current_store_id' => $this->store->id])
            ->postJson(route('approvals.rentals.approve', $approval));
    }

    private function bal(string $code): float
    {
        return Account::where('code', $code)->firstOrFail()->balance();
    }

    public function test_an_approved_edit_that_only_changes_the_total_updates_the_ledger(): void
    {
        $a = $this->outfit(300000, 'E2E-A');
        $b = $this->outfit(150000, 'E2E-B');
        $rental = $this->paidRental([$a], 100000, 'cash', 'down_payment');
        $this->assertSame(200000.0, $this->bal('1300'));

        // Devi's case: items added, amount paid untouched.
        $response = $this->approveEdit($rental, [$a->id, $b->id]);

        $response->assertOk();
        $this->assertSame(350000.0, $this->bal('1300'));
        $this->assertSame(450000.0, $this->bal('2300'));
        $this->assertSame(100000.0, $this->bal('1100'));
    }

    public function test_an_approved_edit_that_leaves_the_customer_overpaid_refunds_by_payment_method(): void
    {
        $a = $this->outfit(300000, 'E2E-C');
        $rental = $this->paidRental([$a], 300000, 'qris', 'full');

        $response = $this->approveEdit($rental, [$a->id], ['discount' => 50000, 'discount_note' => 'ACC test']);

        $response->assertOk();
        $this->assertSame(50000.0, $this->bal('4910'));
        $this->assertSame(250000.0, $this->bal('1200'));
        $this->assertSame(0.0, $this->bal('1100'));
        $this->assertSame(1, JournalEntry::where('reference_type', 'rental_refund')->count());
    }

    public function test_approving_an_unchanged_edit_posts_nothing_new(): void
    {
        $a = $this->outfit(300000, 'E2E-D');
        $rental = $this->paidRental([$a], 100000, 'cash', 'down_payment');
        $entries = JournalEntry::count();

        $this->approveEdit($rental, [$a->id])->assertOk();

        $this->assertSame($entries, JournalEntry::count());
    }

    public function test_the_rental_that_loses_an_item_to_a_takeover_is_brought_down_too(): void
    {
        $a = $this->outfit(200000, 'E2E-E');
        $b1 = $this->outfit(200000, 'E2E-F');
        $b2 = $this->outfit(150000, 'E2E-G');
        // Adjacent, not overlapping: the source's item is only in the taker's
        // boundary window, which is what a takeover is for.
        $source = $this->paidRental([$b1, $b2], 100000, 'cash', 'down_payment', 5);
        $taker = $this->paidRental([$a], 100000, 'cash', 'down_payment', 8);
        $this->assertSame(250000.0, \App\Support\RentalLedgerSync::ledgerTotals($source)['piutang']); // 350k - 100k

        $response = $this->approveEdit($taker, [$a->id, $b1->id], [], [$b1->id => $source->id]);

        $response->assertOk();
        // The item moved, so the total across both rentals is unchanged — check each one.
        $takerTotals = \App\Support\RentalLedgerSync::ledgerTotals($taker->fresh());
        $sourceTotals = \App\Support\RentalLedgerSync::ledgerTotals($source->fresh());
        $this->assertSame(300000.0, $takerTotals['piutang']); // 400k - 100k
        $this->assertSame(400000.0, $takerTotals['uang_muka']);
        $this->assertSame(50000.0, $sourceTotals['piutang']); // 150k - 100k, was 250k
        $this->assertSame(150000.0, $sourceTotals['uang_muka']);
        $this->assertSame(1, $source->fresh()->outfits()->count());
    }

    public function test_a_direct_management_edit_that_lowers_the_total_below_what_was_paid_refunds_it(): void
    {
        $a = $this->outfit(300000, 'E2E-H');
        $rental = $this->paidRental([$a], 300000, 'cash', 'full');
        // management edits apply directly (an admin's edit goes to the approval queue instead).
        $admin = $this->approver;
        \App\Models\ShiftSession::create(['opened_by_user_id' => $admin->id, 'opened_at' => now(), 'period' => \App\Models\ShiftSession::PERIOD_MORNING]);

        $response = $this->actingAs($admin)
            ->withSession(['current_store_id' => $this->store->id])
            ->from(route('rentals.index'))
            ->put(route('rentals.update', $rental), [
                'outfit_ids' => [$a->id],
                'customer_name' => $rental->customer_name,
                'phone' => $rental->phone,
                'start_date' => $rental->start_date->toDateString(),
                'end_date' => $rental->end_date->toDateString(),
                'discount' => '50000',
                'discount_type' => 'fixed',
                'discount_note' => 'ACC test',
                // Required by validation; ignored because the paid amount already covers the new total.
                'payment_method' => 'cash',
                'payment_kind' => 'full',
                'amount_paid' => '300000',
            ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame(50000.0, $this->bal('4910'));
        $this->assertSame(250000.0, $this->bal('1100'));
    }

    public function test_the_dashboard_shows_piutang_only_from_rentals_booked_in_the_period(): void
    {
        $a = $this->outfit(300000, 'E2E-P1');
        $b = $this->outfit(300000, 'E2E-P2');
        // Booked in August, still owes 200k — a real receivable, but not "of the period".
        $old = $this->paidRental([$a], 100000, 'cash', 'down_payment', 5);
        \App\Models\Rental::whereKey($old->id)->update(['created_at' => '2026-08-20 10:00:00']);
        // Booked in the period, owes 200k.
        $new = $this->paidRental([$b], 100000, 'cash', 'down_payment', 5);
        \App\Models\Rental::whereKey($new->id)->update(['created_at' => '2026-09-05 10:00:00']);
        \App\Models\RentalPaymentRecord::where('rental_id', $new->id)->update(['recorded_at' => '2026-09-05 10:00:00']);
        \App\Models\RentalPaymentRecord::where('rental_id', $old->id)->update(['recorded_at' => '2026-08-20 10:00:00']);

        $response = $this->actingAs($this->approver)
            ->withSession(['current_store_id' => $this->store->id])
            ->get(route('accounting.dashboard', ['date_from' => '2026-09-01', 'date_to' => '2026-09-15']));

        $response->assertOk();
        $this->assertSame(200000.0, $response->viewData('piutangPeriode'));
        // Client-style breakdown: contract - paid = remaining, and cash vs bank.
        $this->assertSame(300000.0, $response->viewData('piutangKontrak'));
        $this->assertSame(100000.0, $response->viewData('piutangDibayar'));
        $this->assertSame(1, $response->viewData('piutangJumlahRental'));
        $this->assertSame(100000.0, $response->viewData('rentalCash'));
        $this->assertSame(0.0, $response->viewData('rentalBank'));
        $response->assertSee('1 rental created in this period: rent value Rp 300.000');
        $response->assertSee('already paid Rp 100.000');
        $response->assertSee('receivable (rent value − already paid) = Rp 200.000');
        $response->assertSee('1 rental not fully paid');
        $response->assertSee('Cash Rp 100.000');
        $response->assertSee('Remaining balance of rentals created');
        $response->assertDontSee('Total receivables of all rentals as of');
    }

    public function test_the_dashboard_updated_revenue_card_is_cash_in_minus_settlements_of_older_rentals(): void
    {
        $old = $this->outfit(300000, 'E2E-U1');
        $fresh = $this->outfit(200000, 'E2E-U2');
        // Booked in August, DP then; the balance is collected inside the period.
        $oldRental = $this->paidRental([$old], 100000, 'cash', 'down_payment', 5);
        \App\Models\Rental::whereKey($oldRental->id)->update(['created_at' => '2026-08-20 10:00:00']);
        \App\Models\RentalPaymentRecord::where('rental_id', $oldRental->id)->update(['recorded_at' => '2026-08-20 10:00:00']);
        RentalPaymentRecorder::recordIfNonZero($oldRental->fresh(), 200000, null, null, 'cash', 'final_payment', '2026-09-05 10:00:00', totalDue: 300000.0);
        // Booked and paid in full inside the period.
        $newRental = $this->paidRental([$fresh], 200000, 'cash', 'full', 5);
        \App\Models\Rental::whereKey($newRental->id)->update(['created_at' => '2026-09-06 10:00:00']);
        \App\Models\RentalPaymentRecord::where('rental_id', $newRental->id)->update(['recorded_at' => '2026-09-06 10:00:00']);

        $response = $this->actingAs($this->approver)
            ->withSession(['current_store_id' => $this->store->id])
            ->get(route('accounting.dashboard', ['date_from' => '2026-09-01', 'date_to' => '2026-09-15']));

        $response->assertOk();
        $this->assertSame(400000.0, $response->viewData('rentalRevenue'));
        $this->assertSame(200000.0, $response->viewData('pelunasanLama'));
        $this->assertSame(1, $response->viewData('pelunasanLamaJumlah'));
        $this->assertSame(200000.0, $response->viewData('pendapatanDiakuiUpdate'));
        $response->assertSee('Recognised Rental Revenue');
        $response->assertDontSee('Rental Revenue (Recognised)');
        $response->assertDontSee('Only calculated once the rental is fully paid');
        $response->assertSee('settlement of earlier rentals (1 rental) Rp 200.000');
        $response->assertSee('Settlement of Earlier Rentals');
        $response->assertSee('Total settlement of earlier rentals (1)');
        $this->assertSame(1, $response->viewData('pelunasanLamaList')->count());
    }
}
