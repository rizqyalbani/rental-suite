<?php

namespace Tests\Stress;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * "Send the same request twice": after the first request, an identical second one must not change
 * anything (payments, journal, rents, approvals, shifts).
 *
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/IdempotencyTest.php
 *
 * Pay is the one deliberate exception: the Pay button ADDS an amount (payment_entry=increment),
 * so a partial payment sent twice is two payments. That is documented by its own test.
 */
class IdempotencyTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private StressWorld $w;

    /** @var list<string> */
    private array $problems = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->w = new StressWorld($this, new Rng(7));
        $this->w->build();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ---------------------------------------------------------------- helpers

    /** Creates a rent over HTTP and returns its id. $outfitIdx 0..7 = store-1 outfits (330k, 440k, 550k, 660k, 770k, 880k, 1.1M, 275k). */
    private function rent(string $actor, int $outfitIdx, string $kind, string $paid, int $startOffset = 1, int $days = 3, int $store = 1, array $extraOutfits = []): int
    {
        $start = Carbon::now()->startOfDay()->addDays($startOffset);
        $outfits = array_map(fn ($i) => $this->w->outfits[$store][$i], array_merge([$outfitIdx], $extraOutfits));
        $res = $this->w->http('post', route('rentals.store'), [
            'outfit_ids' => $outfits, 'customer_name' => 'Idem', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => $kind, 'amount_paid' => $paid,
        ], $actor, $store);
        $this->assertSame(200, $res['status'], 'setup rent: '.$res['body']);

        return (int) $res['json']['rental_id'];
    }

    private function row(int $id): object
    {
        return DB::table('rentals')->where('id', $id)->first();
    }

    private function approvalOf(string $kind, int $rentalId, string $status = 'pending'): object
    {
        $a = DB::table('rental_approval_requests')->where('status', $status)->where('payload->kind', $kind)->where('payload->rental_id', $rentalId)->orderByDesc('id')->first();
        $this->assertNotNull($a, "no {$status} {$kind} approval for rental {$rentalId}");

        return $a;
    }

    /**
     * Sends the request, then the very same request again; the second must change nothing.
     *
     * @return array{0: array, 1: array}
     */
    private function twice(string $label, callable $request, bool $expectFirstOk = true): array
    {
        $first = $request();
        if ($expectFirstOk && $first['status'] >= 400) {
            $this->problems[] = "{$label}: FIRST request failed ({$first['status']}) - scenario setup wrong: ".mb_substr($first['body'], 0, 160);
        }
        $afterFirst = $this->w->snapshot();
        $second = $request();
        $afterSecond = $this->w->snapshot();
        if ($afterFirst !== $afterSecond) {
            $changed = implode(', ', array_keys(array_filter($afterSecond, fn ($v, $k) => $afterFirst[$k] !== $v, ARRAY_FILTER_USE_BOTH)));
            $this->problems[] = "{$label}: second identical request ({$second['status']}) changed: {$changed}";
        }

        return [$first, $second];
    }

    private function assertNoProblems(): void
    {
        $this->assertSame([], $this->problems, "Replays that changed state:\n - ".implode("\n - ", $this->problems));
    }

    // ---------------------------------------------------------------- scenarios

    public function test_pay_exact_balance_twice_second_is_refused(): void
    {
        // N2 (new, see NewFindingsReproTest): when ADMIN replays a completed Pay the server files an empty edit request
        // instead of ignoring it, so the admin variant is covered there; this test covers management.
        $ids = ['mgr1' => $this->rent('admin1', 1, 'down_payment', '100000')];
        foreach ($ids as $actor => $id) {
            $r = $this->row($id);
            $payload = $this->w->basePayload($r);
            $payload['payment_amount_add'] = (string) round($this->w->billOf($r) - $this->w->paidOf($id), 2);
            $payload['pay_action'] = '1';
            $payload['payment_kind'] = 'final_payment';
            $this->twice("pay exact balance by {$actor}", fn () => $this->w->http('put', route('rentals.update', $id), $payload, $actor, 1));
            $this->assertEqualsWithDelta($this->w->billOf($this->row($id)), $this->w->paidOf($id), 0.01, 'paid exactly the bill, once');
        }
        $this->assertNoProblems();
    }

    public function test_pay_partial_twice_is_two_payments_by_design_no_server_idempotency_key(): void
    {
        // Characterisation (Info): "Pay" sends payment_entry=increment + an amount, so the server cannot tell a
        // double click from a second instalment of the same size. The browser guards the double click; the server does not.
        $id = $this->rent('admin1', 2, 'down_payment', '100000'); // bill 550.000
        $r = $this->row($id);
        $payload = $this->w->basePayload($r);
        $payload['payment_amount_add'] = '100000';
        $payload['pay_action'] = '1';
        $before = $this->w->paidOf($id);
        $this->w->http('put', route('rentals.update', $id), $payload, 'admin1', 1);
        $this->w->http('put', route('rentals.update', $id), $payload, 'admin1', 1);
        $this->assertEqualsWithDelta($before + 200000, $this->w->paidOf($id), 0.01, 'two identical partial Pay requests = two payments');
    }

    public function test_edit_request_approve_reject_and_cancel_twice(): void
    {
        $id = $this->rent('admin1', 3, 'down_payment', '200000'); // bill 660.000
        $payload = $this->w->basePayload($this->row($id));
        $payload['discount'] = '30000';
        $payload['discount_note'] = 'promo';
        $send = fn () => $this->w->http('put', route('rentals.update', $id), $payload, 'admin1', 1);
        $this->twice('admin edit request (filed twice)', $send);
        $approval = $this->approvalOf('edit', $id);

        $this->twice('approve edit twice', fn () => $this->w->http('post', route('approvals.rentals.approve', $approval->id), [], 'mgr1', 1));
        $this->assertSame('approved', DB::table('rental_approval_requests')->where('id', $approval->id)->value('status'));
        $this->twice('reject after approve', fn () => $this->w->http('post', route('approvals.rentals.reject', $approval->id), [], 'mgr1', 1), expectFirstOk: false);
        $this->assertSame('approved', DB::table('rental_approval_requests')->where('id', $approval->id)->value('status'), 'a decided request cannot be flipped by a later reject');

        // reject twice, approve after reject, cancel twice
        $id2 = $this->rent('admin1', 4, 'down_payment', '200000');
        $payload2 = $this->w->basePayload($this->row($id2));
        $payload2['customer_name'] = 'Changed';
        $this->w->http('put', route('rentals.update', $id2), $payload2, 'admin1', 1);
        $a2 = $this->approvalOf('edit', $id2);
        $this->twice('reject twice', fn () => $this->w->http('post', route('approvals.rentals.reject', $a2->id), [], 'sup1', 1));
        $this->twice('approve after reject', fn () => $this->w->http('post', route('approvals.rentals.approve', $a2->id), [], 'sup1', 1), expectFirstOk: false);
        $this->assertSame('rejected', DB::table('rental_approval_requests')->where('id', $a2->id)->value('status'));

        $id3 = $this->rent('admin1', 5, 'down_payment', '200000');
        $payload3 = $this->w->basePayload($this->row($id3));
        $payload3['customer_name'] = 'Changed again';
        $this->w->http('put', route('rentals.update', $id3), $payload3, 'admin1', 1);
        $a3 = $this->approvalOf('edit', $id3);
        $this->twice('requester cancels own request twice', fn () => $this->w->http('post', route('approvals.rentals.cancel', $a3->id), [], 'admin1', 1));
        $this->twice('approve a cancelled request', fn () => $this->w->http('post', route('approvals.rentals.approve', $a3->id), [], 'mgr1', 1), expectFirstOk: false);
        $this->assertNoProblems();
    }

    public function test_edit_approval_that_lowers_the_bill_refunds_once(): void
    {
        $id = $this->rent('admin1', 6, 'full', '1100000'); // paid in full 1.100.000
        $payload = $this->w->basePayload($this->row($id));
        $payload['discount'] = '100000';
        $payload['discount_note'] = 'goodwill';
        $this->w->http('put', route('rentals.update', $id), $payload, 'admin1', 1);
        $approval = $this->approvalOf('edit', $id);
        $this->twice('approve a bill-lowering edit twice', fn () => $this->w->http('post', route('approvals.rentals.approve', $approval->id), [], 'mgr1', 1));
        $refunds = DB::table('journal_entries')->where('reference_type', 'rental_refund')->where('reference_id', $id)->count();
        $this->assertLessThanOrEqual(1, $refunds, 'one overpay refund entry at most');
        $this->assertNoProblems();
    }

    public function test_free_request_and_free_approval_twice(): void
    {
        $id = $this->rent('admin1', 0, 'down_payment', '100000');
        $this->twice('free-request twice', fn () => $this->w->http('post', route('rentals.free-request', $id), ['reason' => 'endorse'], 'admin1', 1));
        $approval = $this->approvalOf('free', $id);
        $this->twice('approve free twice', fn () => $this->w->http('post', route('approvals.rentals.approve', $approval->id), [], 'mgr1', 1));
        $refund = (float) DB::table('journal_entries as e')->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->where('e.reference_type', 'rental_refund')->where('e.reference_id', $id)->where('l.account_id', DB::table('accounts')->where('code', '1100')->value('id'))->sum('l.credit');
        $this->assertEqualsWithDelta(100000, $refund, 0.01, 'the FREE refund hands back what was paid exactly once');
        $this->twice('reject after free approved', fn () => $this->w->http('post', route('approvals.rentals.reject', $approval->id), [], 'mgr1', 1), expectFirstOk: false);
        $this->assertNoProblems();
    }

    public function test_item_removal_approval_twice_refunds_once(): void
    {
        $id = $this->rent('admin1', 0, 'full', '770000', extraOutfits: [1]); // 330.000 + 440.000 = 770.000 for 2 outfits at 3 days
        $r = $this->row($id);
        $paid = $this->w->paidOf($id);
        $payload = $this->w->basePayload($r);
        $payload['outfit_ids'] = [$r ? $this->w->outfits[1][0] : 0]; // keep the first outfit only
        unset($payload['item_discounts'][$this->w->outfits[1][1]], $payload['item_discount_types'][$this->w->outfits[1][1]]);
        $this->w->http('put', route('rentals.update', $id), $payload, 'sup1', 1);
        $approval = $this->approvalOf('item_removal', $id);
        $this->twice('approve item removal twice', fn () => $this->w->http('post', route('approvals.item-removal.approve', $approval->id), [], 'mgr1', 1));
        $this->twice('reject item removal after approval', fn () => $this->w->http('post', route('approvals.item-removal.reject', $approval->id), [], 'mgr1', 1), expectFirstOk: false);
        $back = (float) DB::table('journal_entries as e')->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')
            ->whereIn('e.reference_type', ['item_removal_refund', 'rental_refund'])->where('l.account_id', DB::table('accounts')->where('code', '1100')->value('id'))->sum('l.credit');
        $this->assertLessThanOrEqual($paid + 0.01, $back, 'refund never exceeds what was paid');
        $this->assertNoProblems();
    }

    public function test_late_done_report_and_approval_twice(): void
    {
        $id = $this->rent('admin1', 0, 'full', '330000');
        $send = fn () => $this->w->http('post', route('rentals.late-done-request', $id), [
            'type' => 'pickup', 'picked_up_at' => Carbon::now()->subHours(2)->toDateTimeString(), 'picked_up_by' => $this->w->users['admin1']->id, 'note' => 'forgot',
        ], 'admin1', 1);
        $this->twice('late-done report twice', $send);
        $approval = $this->approvalOf('late_done', $id);
        $this->twice('approve late-done twice', fn () => $this->w->http('post', route('approvals.rentals.approve', $approval->id), [], 'mgr1', 1));
        $this->assertNoProblems();
    }

    public function test_cool_down_create_request_approved_twice_creates_one_rent(): void
    {
        $first = $this->rent('admin1', 0, 'full', '330000', startOffset: 1);
        $end = Carbon::parse(substr($this->row($first)->end_date, 0, 10));
        $payload = [
            'outfit_ids' => [$this->w->outfits[1][0]], 'customer_name' => 'Next', 'phone' => '0813',
            'start_date' => $end->copy()->addDay()->toDateString(), 'end_date' => $end->copy()->addDays(3)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '330000',
        ];
        $res = $this->w->http('post', route('rentals.store'), $payload, 'admin1', 1);
        $this->assertSame('approval_request_sent', $res['json']['mode'] ?? null, $res['body']);
        $this->twice('same cool-down request filed twice', fn () => $this->w->http('post', route('rentals.store'), $payload, 'admin1', 1));
        $approval = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'create')->first();
        $before = DB::table('rentals')->count();
        $this->twice('approve create twice', fn () => $this->w->http('post', route('approvals.rentals.approve', $approval->id), [], 'mgr1', 1));
        $this->assertSame($before + 1, DB::table('rentals')->count(), 'approving the same create request twice creates exactly one rent');
        $this->assertNoProblems();
    }

    public function test_cancel_refund_reopen_twice(): void
    {
        $id = $this->rent('admin1', 0, 'down_payment', '100000');
        $this->twice('cancel (deposit forfeited) twice', fn () => $this->w->http('post', route('rentals.status', $id), ['status' => 'cancelled'], 'admin1', 1));
        $this->twice('reopen twice', fn () => $this->w->http('post', route('rentals.status', $id), ['status' => 'active'], 'mgr1', 1));
        $this->twice('cancel WITH refund twice', fn () => $this->w->http('post', route('rentals.status', $id), ['status' => 'cancelled', 'refund' => true], 'mgr1', 1));
        $this->twice('reopen after refund twice', fn () => $this->w->http('post', route('rentals.status', $id), ['status' => 'active'], 'mgr1', 1));
        $this->assertNoProblems();
    }

    public function test_pickup_and_return_twice(): void
    {
        $id = $this->rent('admin1', 0, 'full', '330000');
        $this->twice('pickup twice', fn () => $this->w->http('post', route('rentals.pickup', $id), ['picked_up' => true], 'admin1', 1));
        $this->twice('return twice', fn () => $this->w->http('post', route('rentals.status', $id), ['status' => 'returned'], 'admin1', 1));
        $this->assertNoProblems();
    }

    public function test_wrong_input_delete_request_and_approval_twice(): void
    {
        $id = $this->rent('admin1', 0, 'down_payment', '100000');
        $send = fn () => $this->w->http('delete', route('rentals.destroy', $id), ['wrong_input' => true, 'reason' => 'typed twice'], 'sup1', 1);
        $this->twice('supervisor wrong-input delete request twice', $send);
        $approval = $this->approvalOf('delete', $id);
        $this->twice('approve wrong-input delete twice', fn () => $this->w->http('post', route('approvals.rentals.approve', $approval->id), [], 'mgr1', 1));

        $id2 = $this->rent('admin1', 1, 'down_payment', '100000');
        $this->twice('management wrong-input delete twice', fn () => $this->w->http('delete', route('rentals.destroy', $id2), ['wrong_input' => true, 'reason' => 'typed twice'], 'mgr1', 1));
        $this->assertNoProblems();
    }

    public function test_payment_assignment_send_accept_reject_twice(): void
    {
        $id = $this->rent('mgr1', 0, 'down_payment', '100000'); // management has no shift -> shiftless payment
        $payment = DB::table('rental_payment_records')->where('rental_id', $id)->first();
        $this->assertNull($payment->shift_session_id);
        $shift = DB::table('shift_sessions')->where('opened_by_user_id', $this->w->users['admin1']->id)->whereNull('closed_at')->first();
        $send = fn () => $this->w->http('post', route('payments.assign-shift', $payment->id), ['shift_session_id' => $shift->id, 'note' => 'x'], 'mgr1', 1);
        $this->twice('assignment request twice', $send);
        $approval = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'payment_assign')->first();
        $this->twice('accept twice', fn () => $this->w->http('post', route('payment-assign.accept', $approval->id), [], 'admin1', 1));
        $this->twice('reject after accept', fn () => $this->w->http('post', route('payment-assign.reject', $approval->id), [], 'admin1', 1), expectFirstOk: false);
        $this->assertSame(1, DB::table('rental_payment_records')->where('id', $payment->id)->where('shift_session_id', $shift->id)->count());

        $id2 = $this->rent('mgr1', 1, 'down_payment', '100000');
        $payment2 = DB::table('rental_payment_records')->where('rental_id', $id2)->first();
        $this->w->http('post', route('payments.assign-shift', $payment2->id), ['shift_session_id' => $shift->id], 'mgr1', 1);
        $approval2 = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'payment_assign')->first();
        $this->twice('reject twice', fn () => $this->w->http('post', route('payment-assign.reject', $approval2->id), [], 'admin1', 1));
        $this->twice('accept after reject', fn () => $this->w->http('post', route('payment-assign.accept', $approval2->id), [], 'admin1', 1), expectFirstOk: false);
        $this->assertNoProblems();
    }

    public function test_shift_open_and_close_twice(): void
    {
        $this->twice('close shift twice', fn () => $this->w->http('post', route('shifts.close'), [], 'admin1', 1));
        $this->twice('open shift twice', fn () => $this->w->http('post', route('shifts.open'), ['period' => 'morning'], 'admin1', 1));
        $this->assertNoProblems();
    }

    public function test_create_rent_twice_never_double_books_the_outfit(): void
    {
        $payload = fn () => [
            'outfit_ids' => [$this->w->outfits[1][7]], 'customer_name' => 'Twice', 'phone' => '0814',
            'start_date' => Carbon::now()->addDays(2)->toDateString(), 'end_date' => Carbon::now()->addDays(4)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'full', 'amount_paid' => '275000',
        ];
        $this->twice('same create request twice', fn () => $this->w->http('post', route('rentals.store'), $payload(), 'admin1', 1));
        $this->assertSame(1, DB::table('rentals')->where('customer_name', 'Twice')->count());
        $this->assertNoProblems();
    }
}
