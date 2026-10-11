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
class TutorialIdCollisionTest extends TestCase
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

    public function test_practice_wrong_input_delete_files_a_practice_request_and_never_deletes_the_real_rent(): void
    {
        $real = $this->realRental();
        $records = RentalPaymentRecord::where('rental_id', $real->id)->count();
        $journals = JournalEntry::withoutGlobalScopes()->count();
        $practice = $this->practiceRental($this->supervisor, paid: 100000);
        $this->assertSame($real->id, $practice->id, 'precondition: the two sequences collide');

        $this->as($this->supervisor)
            ->deleteJson(route('rentals.destroy', ['rental' => $practice->id, 'tutorial' => 1]), ['wrong_input' => 1, 'reason' => 'practice run'])
            ->assertOk()->assertJsonPath('mode', 'delete_approval_request_sent');

        $req = TutorialRentalApprovalRequest::query()->where('payload->kind', 'delete')->firstOrFail();
        $this->assertSame($practice->id, (int) $req->payload['rental_id']);

        $this->assertRealRentUntouched($real, $records, $journals);
        $this->assertNotSoftDeleted('tutorial_rentals', ['id' => $practice->id]);
        $this->assertSame(0, RentalActivityLog::query()->where('rental_id', $real->id)->where('action', 'like', 'delete%')->count(), 'no false log line on the real rent');
    }

    public function test_practice_wrong_input_delete_can_be_finished_by_the_requester_and_leaves_real_data_alone(): void
    {
        // D8: in the tour one person plays every role, so a practice request may be approved by who filed it
        // (a supervisor files it; management would have deleted directly).
        $real = $this->realRental();
        $records = RentalPaymentRecord::where('rental_id', $real->id)->count();
        $journals = JournalEntry::withoutGlobalScopes()->count();
        $practice = $this->practiceRental($this->supervisor, paid: 100000);

        $this->as($this->supervisor)
            ->deleteJson(route('rentals.destroy', ['rental' => $practice->id, 'tutorial' => 1]), ['wrong_input' => 1, 'reason' => 'practice run'])
            ->assertOk()->assertJsonPath('mode', 'delete_approval_request_sent');
        $req = TutorialRentalApprovalRequest::query()->where('payload->kind', 'delete')->firstOrFail();

        $this->as($this->supervisor)
            ->postJson(route('approvals.rentals.approve', ['approval' => $req->id, 'tutorial' => 1]))
            ->assertOk();

        $this->assertSame('approved', $req->fresh()->status);
        $this->assertSoftDeleted('tutorial_rentals', ['id' => $practice->id]);
        $this->assertRealRentUntouched($real, $records, $journals);
    }

    public function test_real_wrong_input_delete_still_needs_management_who_is_not_the_requester(): void
    {
        // Same flow on REAL data: the D8 waiver must not reach it.
        $real = $this->realRental();
        $this->as($this->supervisor)->deleteJson(route('rentals.destroy', $real), ['wrong_input' => 1, 'reason' => 'typo'])
            ->assertOk()->assertJsonPath('mode', 'delete_approval_request_sent');
        $req = RentalApprovalRequest::query()->where('payload->kind', 'delete')->firstOrFail();

        $this->as($this->supervisor)->postJson(route('approvals.rentals.approve', $req))->assertForbidden();
        $this->assertSame('pending', $req->fresh()->status);
        $this->assertNull(Rental::withTrashed()->find($real->id)->deleted_at);
    }

    public function test_practice_plain_delete_by_admin_files_a_practice_request_and_never_deletes_the_real_rent(): void
    {
        $real = $this->realRental();
        $records = RentalPaymentRecord::where('rental_id', $real->id)->count();
        $journals = JournalEntry::withoutGlobalScopes()->count();
        $practice = $this->practiceRental($this->admin);
        ShiftSession::create(['opened_by_user_id' => $this->admin->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);

        $this->as($this->admin)->deleteJson(route('rentals.destroy', ['rental' => $practice->id, 'tutorial' => 1]))->assertOk();
        $this->assertSame(1, TutorialRentalApprovalRequest::query()->where('payload->kind', 'delete')->count());

        // Practice approvals can only be opened by the person who filed them (tour: one trainee plays both roles).
        $req = TutorialRentalApprovalRequest::query()->firstOrFail();
        $req->update(['requested_by_user_id' => $this->supervisor->id]);
        $this->as($this->supervisor)->postJson(route('approvals.rentals.approve', ['approval' => $req->id, 'tutorial' => 1]))->assertOk();

        $this->assertRealRentUntouched($real, $records, $journals);
        $this->assertSoftDeleted('tutorial_rentals', ['id' => $practice->id]);
    }

    public function test_practice_item_removal_files_a_practice_request_not_a_real_one(): void
    {
        $real = $this->realRental();
        $practice = $this->practiceRental($this->manager, paid: 100000, outfitCount: 2);
        $this->assertSame($real->id, $practice->id);
        $keep = $practice->outfits->first()->id;

        $this->as($this->manager)->putJson(route('rentals.update', ['rental' => $practice->id, 'tutorial' => 1]), [
            'outfit_ids' => [$keep], 'customer_name' => 'Practice', 'phone' => '0800',
            'start_date' => $practice->start_date->toDateString(), 'end_date' => $practice->end_date->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'payment_entry' => 'increment', 'payment_amount_add' => '0',
        ])->assertOk();

        $this->assertSame(0, RentalApprovalRequest::query()->count(), 'no request in the real queue');
        $this->assertSame(1, TutorialRentalApprovalRequest::query()->where('payload->kind', 'item_removal')->count());
        $this->assertSame(0, RentalActivityLog::query()->where('rental_id', $real->id)->where('action', 'item_removal_requested')->count(), 'no false log line on the real rent');
    }

    public function test_practice_cancel_flagged_only_by_form_field_acts_on_the_practice_rent(): void
    {
        $real = $this->realRental();
        $practice = $this->practiceRental($this->manager);

        // The tour tags forms with tutorial_practice=1 but not the URL.
        $this->as($this->manager)
            ->postJson('/rentals/'.$practice->id.'/status', ['status' => 'cancelled', 'tutorial_practice' => 1])
            ->assertOk();

        $this->assertSame('active', $real->fresh()->status, 'real rent must stay active');
        $this->assertSame('cancelled', $practice->fresh()->status);
    }

    public function test_practice_flag_does_not_waive_the_open_shift_rule_for_a_real_rent(): void
    {
        $real = $this->realRental();

        $this->as($this->admin)
            ->postJson(route('rentals.status', $real), ['status' => 'cancelled', 'tutorial_practice' => 1])
            ->assertStatus(422);

        $this->assertSame('active', $real->fresh()->status);
    }

    public function test_practice_rent_without_a_shift_is_still_allowed_for_admin(): void
    {
        $practice = $this->practiceRental($this->admin);

        $this->as($this->admin)
            ->postJson(route('rentals.status', ['rental' => $practice->id, 'tutorial' => 1]), ['status' => 'cancelled', 'tutorial_practice' => 1])
            ->assertOk();

        $this->assertSame('cancelled', $practice->fresh()->status);
    }

    public function test_deleting_a_practice_rent_directly_does_not_reverse_the_real_rents_money(): void
    {
        $real = $this->realRental();
        $records = RentalPaymentRecord::where('rental_id', $real->id)->count();
        $journals = JournalEntry::withoutGlobalScopes()->count();
        $practice = $this->practiceRental($this->manager); // unpaid, so management may delete it straight away
        $this->assertSame($real->id, $practice->id);

        $this->as($this->manager)->deleteJson(route('rentals.destroy', ['rental' => $practice->id, 'tutorial' => 1]))->assertOk();

        $this->assertSoftDeleted('tutorial_rentals', ['id' => $practice->id]);
        $this->assertRealRentUntouched($real, $records, $journals);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('is_reversal', true)->count());
    }

    /**
     * Found in the browser run 2026-10-10: cancelling a practice rent booked "DP forfeited" against the
     * real rent's payment records (same id), because updateStatus() still called the ledger for practice rents.
     */
    public function test_cancelling_a_practice_rent_books_nothing_on_the_real_rents_ledger(): void
    {
        $real = $this->realRental();
        $records = RentalPaymentRecord::where('rental_id', $real->id)->count();
        $journals = JournalEntry::withoutGlobalScopes()->count();
        $practice = $this->practiceRental($this->manager, paid: 300000);
        $this->assertSame($real->id, $practice->id);

        $this->as($this->manager)
            ->postJson(route('rentals.status', ['rental' => $practice->id, 'tutorial' => 1]), ['status' => 'cancelled'])
            ->assertOk();

        $this->assertSame('cancelled', $practice->fresh()->status);
        $this->assertRealRentUntouched($real, $records, $journals);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('reference_type', 'rental_cancellation')->count());
    }

    public function test_cancelling_a_practice_rent_with_refund_books_nothing_on_the_real_rents_ledger(): void
    {
        $real = $this->realRental();
        $records = RentalPaymentRecord::where('rental_id', $real->id)->count();
        $journals = JournalEntry::withoutGlobalScopes()->count();
        $practice = $this->practiceRental($this->manager, paid: 300000);

        $this->as($this->manager)
            ->postJson(route('rentals.status', ['rental' => $practice->id, 'tutorial' => 1]), ['status' => 'cancelled', 'refund' => 1])
            ->assertOk();

        $this->assertRealRentUntouched($real, $records, $journals);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('reference_type', 'rental_cancellation')->count());
    }

    public function test_reopening_a_cancelled_practice_rent_does_not_touch_the_real_rents_ledger(): void
    {
        $real = $this->realRental();
        $records = RentalPaymentRecord::where('rental_id', $real->id)->count();
        $journals = JournalEntry::withoutGlobalScopes()->count();
        $practice = $this->practiceRental($this->manager, paid: 300000);

        $this->as($this->manager)->postJson(route('rentals.status', ['rental' => $practice->id, 'tutorial' => 1]), ['status' => 'cancelled'])->assertOk();
        $this->as($this->manager)->postJson(route('rentals.status', ['rental' => $practice->id, 'tutorial' => 1]), ['status' => 'active'])->assertOk();

        $this->assertSame('active', $practice->fresh()->status);
        $this->assertRealRentUntouched($real, $records, $journals);
        $this->assertSame(0, JournalEntry::withoutGlobalScopes()->where('is_reversal', true)->count());
    }

    public function test_undoing_a_practice_return_looks_at_the_practice_log_not_the_real_rents(): void
    {
        $real = $this->realRental();
        $practice = $this->practiceRental($this->admin, paid: 300000);
        ShiftSession::create(['opened_by_user_id' => $this->admin->id, 'opened_at' => now(), 'period' => ShiftSession::PERIOD_MORNING]);
        // A real "returned by this admin" log line with the same rental id must not let the admin reopen the practice rent.
        RentalActivityLog::record($real, 'status_changed', ['status' => ['active', 'returned']], userId: $this->admin->id);
        $practice->update(['status' => 'returned']);

        $this->as($this->admin)
            ->postJson(route('rentals.status', ['rental' => $practice->id, 'tutorial' => 1]), ['status' => 'active'])
            ->assertStatus(403);
    }

    /**
     * Found in the browser run 2026-10-10: with a leftover practice rent #1 owned by the same user, the Edit button on the
     * REAL rent #1 opened the practice rent (the by-id lookup preferred the caller's own practice rent).
     */
    public function test_search_by_id_returns_the_real_rent_even_when_the_caller_owns_a_practice_rent_with_that_id(): void
    {
        $real = $this->realRental();
        $practice = $this->practiceRental($this->manager);
        $this->assertSame($real->id, $practice->id);

        $row = $this->as($this->manager)->getJson(route('rentals.search', ['id' => $real->id, 'per_page' => 1]))->assertOk()->json('data.0');

        $this->assertSame('Real Customer', $row['customer_name']);
        $this->assertEmpty($row['is_tutorial'] ?? false);
    }

    public function test_search_by_id_with_tutorial_flag_returns_the_practice_rent(): void
    {
        $real = $this->realRental();
        $practice = $this->practiceRental($this->manager);

        $row = $this->as($this->manager)->getJson(route('rentals.search', ['id' => $practice->id, 'per_page' => 1, 'tutorial' => 1]))->assertOk()->json('data.0');

        $this->assertSame('Practice', $row['customer_name']);
        $this->assertTrue((bool) $row['is_tutorial']);
    }

    public function test_search_by_id_still_finds_a_practice_rent_when_no_real_rent_has_that_id(): void
    {
        $practice = $this->practiceRental($this->manager);

        $row = $this->as($this->manager)->getJson(route('rentals.search', ['id' => $practice->id, 'per_page' => 1]))->assertOk()->json('data.0');

        $this->assertSame('Practice', $row['customer_name'], 'old links without the flag keep working when nothing real shares the id');
    }

    public function test_my_requests_links_a_practice_request_to_the_practice_rent(): void
    {
        $real = $this->realRental();
        $practice = $this->practiceRental($this->manager, paid: 100000);
        TutorialRentalApprovalRequest::create([
            'requested_by_user_id' => $this->manager->id, 'status' => 'pending', 'store_id' => $this->store->id, 'is_tutorial' => true,
            'payload' => ['kind' => 'edit', 'rental_id' => $practice->id, 'rental' => ['customer_name' => 'Practice']],
        ]);

        $html = $this->as($this->manager)->get(route('my-requests.index'))->assertOk()->getContent();

        $this->assertStringContainsString('edit='.$practice->id.'&amp;tutorial=1', $html);
    }

    public function test_a_practice_rent_does_not_count_the_money_of_the_real_rent_with_the_same_id(): void
    {
        $real = $this->realRental();
        $practice = $this->practiceRental($this->manager);

        $this->assertSame($real->id, $practice->id);
        $this->assertGreaterThan(0, $real->moneyReceived());
        $this->assertEqualsWithDelta(0.0, $practice->moneyReceived(), 0.001);
    }
}
