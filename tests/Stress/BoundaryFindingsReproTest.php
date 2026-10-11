<?php

namespace Tests\Stress;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * Minimal, named repros of the NEW findings of the boundary / hostile-input run
 * (docs/overnight-2026-10-09/04b-stress-boundary.md). Same convention as tests/Repro and NewFindingsReproTest: each test
 * asserts the CURRENT (wrong) behaviour, so a PASSING test = finding confirmed. When a finding is fixed its test fails on
 * purpose: flip or delete it then.
 *
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/BoundaryFindingsReproTest.php
 */
class BoundaryFindingsReproTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores, BoundaryHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initWorld(5);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function rawCreate(array $over = [], array $outfits = [], int $days = 3, string $actor = 'admin1'): array
    {
        return $this->w->http('post', route('rentals.store'), $this->createPayload($over, $outfits, $days), $actor, 1);
    }

    private function refundAccounts(int $rentalId): array
    {
        return DB::table('journal_entries as e')->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->where('e.reference_type', 'item_removal_refund')->where('e.reference_id', $rentalId)->where('l.credit', '>', 0)->pluck('a.code')->all();
    }

    /**
     * B9 (FIXED): an item removal used to be approved with ANY refund_payment_account_id (credit side of the refund entry).
     * Now a non-cash, inactive or unknown account is refused with 422 and nothing is applied.
     */
    public function test_FIXED_b9_item_removal_refund_account_must_be_a_cash_or_bank_account(): void
    {
        $outcome = [];
        foreach (['revenue' => '4100', 'unknown' => null] as $name => $code) {
            $a1 = $this->outfitAt(330000);
            $a2 = $this->outfitAt(440000);
            $start = Carbon::now()->startOfDay()->addDays($name === 'revenue' ? 1 : 10);
            $payload = $this->createPayload(['payment_kind' => 'full', 'amount_paid' => '770000'], [$a1, $a2]);
            $payload['start_date'] = $start->toDateString();
            $payload['end_date'] = $start->copy()->addDays(2)->toDateString();
            $id = (int) $this->w->http('post', route('rentals.store'), $payload, 'admin1', 1)['json']['rental_id'];
            $p = $this->w->basePayload($this->row($id));
            $p['outfit_ids'] = [$a1];
            unset($p['item_discounts'][$a2], $p['item_discount_types'][$a2], $p['item_discount_notes'][$a2]);
            $this->w->http('put', route('rentals.update', $id), $p, 'mgr1', 1);
            $req = DB::table('rental_approval_requests')->where('payload->kind', 'item_removal')->where('payload->rental_id', $id)->first();
            $accountId = $code ? (int) DB::table('accounts')->where('code', $code)->value('id') : 999999;
            $res = $this->w->http('post', route('approvals.item-removal.approve', $req->id), ['refund_payment_account_id' => $accountId], 'sup1', 1);
            $this->assertSame(422, $res['status'], $res['body']);
            $this->assertSame('pending', DB::table('rental_approval_requests')->where('id', $req->id)->value('status'), "$name: nothing applied");
            $this->assertSame([], $this->refundAccounts($id), "$name: no refund entry");
            $outcome[$name] = true;
        }
        $this->assertCount(2, $outcome);
    }

    /**
     * B3 (Low): item_discounts / item_discount_types / item_discount_notes are not validated by store() or update().
     * Negative amounts and free text are stored in rental_outfit; arrays answer 500.
     */
    public function test_b3_item_discounts_are_not_validated(): void
    {
        $sid = $this->outfit(3);
        $negative = $this->rawCreate(['amount_paid' => '1000', 'item_discounts' => [$sid => '-50000'], 'item_discount_types' => [$sid => 'fixed'], 'item_discount_notes' => [$sid => 'x']]);
        $this->assertSame(200, $negative['status']);
        $this->assertEquals(-50000.0, (float) DB::table('rental_outfit')->value('discount'), 'a negative item discount is stored');
        $this->assertEquals(660000.0, $this->bill((int) $negative['json']['rental_id']), 'the bill ignores it (no money effect, but an I3 violation in the data)');

        $array = $this->rawCreate(['amount_paid' => '1000', 'start_date' => Carbon::now()->addDays(10)->toDateString(), 'end_date' => Carbon::now()->addDays(12)->toDateString(),
            'item_discounts' => [$sid => [5]], 'item_discount_types' => [$sid => 'fixed'], 'item_discount_notes' => [$sid => 'x']]);
        $this->assertSame(500, $array['status'], 'an array as an item discount answers 500');

        $note = $this->rawCreate(['amount_paid' => '1000', 'start_date' => Carbon::now()->addDays(20)->toDateString(), 'end_date' => Carbon::now()->addDays(22)->toDateString(),
            'item_discounts' => [$sid => '50000'], 'item_discount_notes' => [$sid => ['x']]]);
        $this->assertSame(500, $note['status'], 'an array as an item discount note answers 500');

        // free text is stored; on MySQL strict mode (decimal(12,2) / varchar(10)) these would be SQL errors instead - not reproducible on sqlite
        $junk = $this->rawCreate(['amount_paid' => '1000', 'start_date' => Carbon::now()->addDays(30)->toDateString(), 'end_date' => Carbon::now()->addDays(32)->toDateString(),
            'item_discounts' => [$sid => 'abc'], 'item_discount_types' => [$sid => 'a-type-longer-than-ten'], 'item_discount_notes' => [$sid => 'x']]);
        $this->assertSame(200, $junk['status']);
        $stored = DB::table('rental_outfit')->where('rental_id', (int) $junk['json']['rental_id'])->first();
        $this->assertSame('abc', (string) $stored->discount);
        $this->assertSame('a-type-longer-than-ten', $stored->discount_type);
    }

    /**
     * B1 (Low): an empty discount_type is converted to null and written to a NOT NULL column: create answers 500, edit answers
     * 409 whose message is the raw SQL error (table, column and the bound customer data).
     */
    public function test_b1_empty_discount_type_answers_500_and_leaks_sql_on_edit(): void
    {
        $create = $this->rawCreate(['discount' => '10000', 'discount_type' => '', 'discount_note' => 'x', 'amount_paid' => '1000']);
        $this->assertSame(500, $create['status']);
        $this->assertStringContainsString('discount_type', $create['body']);

        $id = $this->create($this->createPayload(['payment_kind' => 'full', 'amount_paid' => '660000'], [$this->outfit(3)]));
        $p = array_merge($this->w->basePayload($this->row($id)), ['discount' => '10000', 'discount_type' => '', 'discount_note' => 'x']);
        $edit = $this->w->http('put', route('rentals.update', $id), $p, 'mgr1', 1);
        $this->assertSame(409, $edit['status']);
        $this->assertStringContainsString('SQLSTATE', $edit['body'], 'the browser receives the raw SQL error');
    }

    /**
     * B2 (Low): an array where the code casts a field with (string) answers 500 "Array to string conversion"
     * (discount_type in normalizeRentalMoneyRequest, `reason` of a delete, `decision_note` of approve / reject).
     */
    public function test_b2_arrays_in_unvalidated_text_fields_answer_500(): void
    {
        $this->assertSame(500, $this->rawCreate(['discount' => '10', 'discount_type' => ['percent'], 'discount_note' => 'x', 'amount_paid' => '1000'])['status'], 'discount_type[]');

        $id = $this->create($this->createPayload([], [$this->outfit(3)]));
        $del = $this->w->http('delete', route('rentals.destroy', $id), ['wrong_input' => true, 'reason' => ['x']], 'mgr1', 1);
        $this->assertSame(500, $del['status'], 'reason[] on delete');

        $p = $this->w->basePayload($this->row($id));
        $p['customer_name'] = 'Renamed';
        $this->w->http('put', route('rentals.update', $id), $p, 'admin1', 1);
        $a = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'edit')->first();
        $res = $this->w->http('post', route('approvals.rentals.approve', $a->id), ['decision_note' => ['x']], 'mgr1', 1);
        $this->assertSame(500, $res['status'], 'decision_note[] on approve');
        $this->assertSame('pending', DB::table('rental_approval_requests')->where('id', $a->id)->value('status'), 'nothing was applied');
    }

    /**
     * B4 (Low): outfit_ids with a nested array passes `exists`, then array_map('intval') turns [[3]] into [1]: another outfit
     * (here the cheapest, id 1) is attached silently.
     */
    public function test_b4_nested_outfit_ids_attach_a_different_outfit(): void
    {
        $wanted = $this->outfit(6); // 1.100.000
        $res = $this->rawCreate(['outfit_ids' => [[$wanted]], 'amount_paid' => '1000']);
        $this->assertSame(200, $res['status']);
        $attached = DB::table('rental_outfit')->where('rental_id', (int) $res['json']['rental_id'])->pluck('outfit_id')->all();
        $this->assertSame([1], array_map('intval', $attached), 'outfit id 1 is attached instead of the requested one');
        $this->assertNotContains($wanted, array_map('intval', $attached));
    }

    /**
     * B11 (Low): a JSON integer as a date passes the `date` rule and is stored as a UNIX timestamp (1970).
     */
    public function test_b11_integer_dates_become_unix_timestamps(): void
    {
        $res = $this->rawCreate(['start_date' => 20261015, 'end_date' => 20261017, 'amount_paid' => '1000']);
        $this->assertSame(200, $res['status']);
        $r = $this->row((int) $res['json']['rental_id']);
        $this->assertStringStartsWith('1970-08-23', (string) $r->start_date);
    }

    /**
     * B6 (Low, needs MySQL): rentals.down_payment_amount is decimal(10,2) (max 99.999.999,99) but amount_paid is validated up to
     * 999.999.999,99. On sqlite the 100.000.000 down payment is stored; under MySQL strict mode it is an "out of range" SQL error.
     */
    public function test_b6_down_payment_above_the_down_payment_column_is_accepted_by_the_validator(): void
    {
        $res = $this->rawCreate(['amount_paid' => '100.000.000', 'extra_charge' => '1'], [$this->outfit(6)], 3 * 1000 + 1);
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertEquals(100000000.0, (float) DB::table('rentals')->value('down_payment_amount'));
        $this->assertMatchesRegularExpression("/decimal\\('down_payment_amount', 10, 2\\)/", file_get_contents(base_path('database/migrations/2026_06_29_000001_add_down_payment_amount_to_rentals_table.php')));
    }

    /**
     * B12 (Info / design question): FREE needs a request by one person and an approval by another, but a supervisor or management
     * can reach the same result alone with Save: order discount 100% on a paid rent. The bill becomes Rp 0 and the whole payment
     * is booked as handed back; no approval is filed. (Admin's Save goes to an approval request, as designed.)
     */
    public function test_b12_a_supervisor_can_zero_a_paid_rent_with_one_save(): void
    {
        $id = $this->create($this->createPayload(['payment_kind' => 'full', 'amount_paid' => '660000'], [$this->outfit(3)]));
        $before = DB::table('rental_approval_requests')->count();
        $p = array_merge($this->w->basePayload($this->row($id)), ['discount' => '100', 'discount_type' => 'percent', 'discount_note' => 'goodwill']);
        $res = $this->w->http('put', route('rentals.update', $id), $p, 'sup1', 1);
        $this->assertSame(200, $res['status'], $res['body']);
        $this->assertEquals(0.0, $this->bill($id));
        $this->assertEqualsWithDelta(660000.0, $this->cashBack($id), 0.01, 'the whole payment is booked as handed back');
        $this->assertSame($before, DB::table('rental_approval_requests')->count(), 'no approval request was filed');

        // admin: the same Save is only a request
        $id2 = $this->create($this->createPayload(['payment_kind' => 'full', 'amount_paid' => '550000', 'start_date' => Carbon::now()->addDays(10)->toDateString(), 'end_date' => Carbon::now()->addDays(12)->toDateString()], [$this->outfit(2)]));
        $p2 = array_merge($this->w->basePayload($this->row($id2)), ['discount' => '100', 'discount_type' => 'percent', 'discount_note' => 'goodwill']);
        $this->w->http('put', route('rentals.update', $id2), $p2, 'admin1', 1);
        $this->assertEqualsWithDelta(550000.0, $this->bill($id2), 0.01, 'admin: bill unchanged until approved');
    }
}
