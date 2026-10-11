<?php

namespace Tests\Stress;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * Minimal, named repros of the NEW problems the stress walk / replay tests found
 * (docs/overnight-2026-10-09/04a-stress-walk.md). Same convention as tests/Repro: each test asserts the
 * CURRENT (wrong) behaviour, so a passing test = problem confirmed. When a problem is fixed the test
 * fails on purpose: flip or delete it then.
 *
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/NewFindingsReproTest.php
 */
class NewFindingsReproTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private StressWorld $w;

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = new StressWorld($this, new Rng(3));
        $this->w->build();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function dpRent(): int
    {
        $start = Carbon::now()->startOfDay()->addDay();
        $res = $this->w->http('post', route('rentals.store'), [
            'outfit_ids' => [$this->w->outfits[1][0]], 'customer_name' => 'Repro', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000',
        ], 'admin1', 1);

        return (int) $res['json']['rental_id'];
    }

    /**
     * N1: the onboarding tour's practice "create rent" step, run by someone who has a REAL shift open
     * (the normal state of an admin at work), answers 500: store() stamps the real shift id on the practice
     * rent, but tutorial_rentals.shift_session_id points at tutorial_shift_sessions (FK).
     */
    public function test_n1_practice_create_while_a_real_shift_is_open_answers_500(): void
    {
        $this->assertSame(1, DB::table('shift_sessions')->where('opened_by_user_id', $this->w->users['admin1']->id)->whereNull('closed_at')->count());
        $start = Carbon::now()->startOfDay()->addDays(20);

        $res = $this->w->http('post', route('rentals.store'), [
            'tutorial_practice' => true, 'outfit_ids' => [$this->w->outfits[1][0]], 'customer_name' => 'PRACTICE', 'phone' => '0811',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000',
        ], 'admin1', 1);

        $this->assertSame(500, $res['status'], 'practice create by an admin with a real open shift');
        $this->assertStringContainsString('FOREIGN KEY', $res['body']);
        $this->assertSame(0, DB::table('tutorial_rentals')->count());
    }

    /**
     * N2: a Pay request that is replayed after the balance is already paid (double click on a slow link,
     * second tab, retry) does nothing for management (known P12), but for ADMIN it files an EMPTY edit
     * request ("Changes submitted for approval"). While it waits, the rent cannot be picked up, returned,
     * cancelled or paid. No money moves; approving it changes nothing.
     */
    public function test_n2_replayed_admin_pay_files_an_empty_edit_request_that_blocks_the_rent(): void
    {
        $id = $this->dpRent();
        $pay = $this->w->basePayload(DB::table('rentals')->where('id', $id)->first());
        $pay['payment_amount_add'] = '230000';
        $pay['pay_action'] = '1';
        $pay['payment_kind'] = 'final_payment';

        $first = $this->w->http('put', route('rentals.update', $id), $pay, 'admin1', 1);
        $this->assertSame('Rental updated successfully.', $first['json']['message']);
        $second = $this->w->http('put', route('rentals.update', $id), $pay, 'admin1', 1);

        $this->assertSame(200, $second['status']);
        $this->assertSame('edit_approval_request_sent', $second['json']['mode'], 'the replay is not ignored: it becomes an approval request');
        $approval = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'edit')->first();
        $this->assertNotNull($approval);
        $payload = json_decode($approval->payload, true);
        $this->assertEquals(330000, $payload['rental']['amount_paid'], 'the request changes nothing: same total as already saved');
        $this->assertEquals(330000.0, (float) DB::table('rentals')->where('id', $id)->value('amount_paid'));

        $pickup = $this->w->http('post', route('rentals.pickup', $id), ['picked_up' => true], 'admin1', 1);
        $this->assertSame(422, $pickup['status']);
        $this->assertStringContainsString('edit request waiting', $pickup['body']);
        $cancel = $this->w->http('post', route('rentals.status', $id), ['status' => 'cancelled'], 'admin1', 1);
        $this->assertSame(422, $cancel['status']);

        // Management replaying the same Pay is simply ignored (P12): no request, nothing blocked.
        $id2 = $this->dpRent2();
        $p2 = $this->w->basePayload(DB::table('rentals')->where('id', $id2)->first());
        $p2['payment_amount_add'] = '340000';
        $p2['pay_action'] = '1';
        $p2['payment_kind'] = 'final_payment';
        $this->w->http('put', route('rentals.update', $id2), $p2, 'mgr1', 1);
        $before = DB::table('rental_approval_requests')->count();
        $again = $this->w->http('put', route('rentals.update', $id2), $p2, 'mgr1', 1);
        $this->assertSame(200, $again['status']);
        $this->assertNotSame('edit_approval_request_sent', $again['json']['mode'] ?? null);
        $this->assertSame($before, DB::table('rental_approval_requests')->count());
        $this->assertEquals(440000.0, $this->w->paidOf($id2));
    }

    /**
     * N3: a rent made FREE had its paid money handed back (journal-only refund; rentals.amount_paid and the payment
     * records keep the full amount). A later ordinary edit of the order discount (direct as supervisor / management,
     * or an admin request that gets approved) turns the price back on, and every "is it paid?" gate reads
     * amount_paid - which still contains the refunded money - so the rent looks fully paid.
     * The outfit is handed over and returned although the shop holds Rp 0 for it. Same root as C2-2, other door.
     */
    public function test_n3_editing_the_discount_of_a_free_refunded_rent_makes_it_look_paid_again(): void
    {
        $start = Carbon::now()->startOfDay()->addDay();
        $id = (int) $this->w->http('post', route('rentals.store'), [
            'outfit_ids' => [$this->w->outfits[1][0]], 'customer_name' => 'Free', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '330000',
        ], 'admin1', 1)['json']['rental_id'];

        // FREE: requested by one supervisor, approved by another; all 330.000 is recorded as handed back.
        $this->w->http('post', route('rentals.free-request', $id), ['reason' => 'endorse'], 'sup1', 1);
        $approval = DB::table('rental_approval_requests')->where('payload->kind', 'free')->first();
        $this->assertSame(200, $this->w->http('post', route('approvals.rentals.approve', $approval->id), [], 'sup1b', 1)['status']);
        $this->assertEquals(0.0, $this->w->billOf(DB::table('rentals')->where('id', $id)->first()));
        $handedBack = (float) DB::table('journal_entries as e')->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->where('e.reference_type', 'rental_refund')->where('e.reference_id', $id)->where('l.account_id', DB::table('accounts')->where('code', '1100')->value('id'))->sum('l.credit');
        $this->assertEquals(330000.0, $handedBack);

        // Supervisor edits the order discount from 100% to 20%.
        $payload = $this->w->basePayload(DB::table('rentals')->where('id', $id)->first());
        $payload['discount'] = '20';
        $payload['discount_type'] = 'percent';
        $payload['discount_note'] = 'changed my mind';
        $edit = $this->w->http('put', route('rentals.update', $id), $payload, 'sup1b', 1);
        $this->assertSame(200, $edit['status'], $edit['body']);

        $r = DB::table('rentals')->where('id', $id)->first();
        $this->assertEquals(264000.0, $this->w->billOf($r), 'bill is back to 264.000');
        $this->assertEquals(330000.0, (float) $r->amount_paid, 'amount_paid still counts the refunded 330.000');
        $net = $this->w->paidOf($id) - $handedBack;
        $this->assertEquals(0.0, $net, 'money really held for this rent is Rp 0');

        // ...and the gates agree it is paid: pick up and return go through.
        $this->assertSame(200, $this->w->http('post', route('rentals.pickup', $id), ['picked_up' => true], 'admin1', 1)['status']);
        $this->assertSame(200, $this->w->http('post', route('rentals.status', $id), ['status' => 'returned'], 'admin1', 1)['status']);
        $this->assertSame('returned', DB::table('rentals')->where('id', $id)->value('status'));
    }

    /**
     * N3b (general form of N3): any refund booked by an edit (discount raised after full payment) is a journal entry only;
     * rentals.amount_paid and the payment records keep the old total. Undoing the discount later puts the bill back to the old
     * total, which "amount_paid" still covers, so the refunded money is never asked for again.
     */
    public function test_n3b_refund_after_a_discount_is_not_collected_again_when_the_discount_is_removed(): void
    {
        $start = Carbon::now()->startOfDay()->addDay();
        $id = (int) $this->w->http('post', route('rentals.store'), [
            'outfit_ids' => [$this->w->outfits[1][0]], 'customer_name' => 'Refund', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '330000',
        ], 'admin1', 1)['json']['rental_id'];

        $p = $this->w->basePayload(DB::table('rentals')->where('id', $id)->first());
        $p['discount'] = '50000';
        $p['discount_note'] = 'goodwill';
        $first = $this->w->http('put', route('rentals.update', $id), $p, 'mgr1', 1);
        $this->assertSame(200, $first['status'], $first['body']);
        $this->assertNotNull($first['json']['refund'] ?? null, 'a Rp 50.000 refund was booked: '.json_encode($first['json']));

        $p2 = $this->w->basePayload(DB::table('rentals')->where('id', $id)->first());
        $p2['discount'] = '';
        $p2['discount_note'] = null;
        $second = $this->w->http('put', route('rentals.update', $id), $p2, 'mgr1', 1);

        $r = DB::table('rentals')->where('id', $id)->first();
        $this->assertSame(200, $second['status'], 'removing the discount is accepted: '.$second['body']);
        $this->assertEquals(330000.0, $this->w->billOf($r));
        $this->assertEquals(330000.0, (float) $r->amount_paid);
        $handedBack = (float) DB::table('journal_entries as e')->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->where('e.reference_type', 'rental_refund')->where('e.reference_id', $id)->where('l.account_id', DB::table('accounts')->where('code', '1100')->value('id'))->sum('l.credit');
        $this->assertEquals(50000.0, $handedBack);
        $this->assertSame(200, $this->w->http('post', route('rentals.pickup', $id), ['picked_up' => true], 'admin1', 1)['status'], 'picked up as "fully paid" although only 280.000 is held');
        $this->assertSame(200, $this->w->http('post', route('rentals.status', $id), ['status' => 'returned'], 'admin1', 1)['status']);
    }

    private function dpRent2(): int
    {
        $start = Carbon::now()->startOfDay()->addDays(8);
        $res = $this->w->http('post', route('rentals.store'), [
            'outfit_ids' => [$this->w->outfits[1][1]], 'customer_name' => 'Repro2', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays(2)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000',
        ], 'admin1', 1);
        // bill is 440.000 for outfit idx 1: the manager pays the 340.000 balance
        return (int) $res['json']['rental_id'];
    }
}
