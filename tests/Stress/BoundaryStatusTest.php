<?php

namespace Tests\Stress;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * Boundary / hostile input at cancel / reopen / free / delete / approvals / overdue, plus double submit with odd amounts.
 *
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/BoundaryStatusTest.php
 *   BOUNDARY_VERBOSE=1 BOUNDARY_HITS=1 php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/BoundaryStatusTest.php
 *
 * Known findings left out on purpose: P1 (overdue change counted as paid: overdue payments are never above the fee here
 * except where a case says so), P8 (overdue fee amount is free: rate x days is never cross-checked), P3 (Pay on a cancelled
 * rent), C3-6 / C2-1 (cancel with refund after an earlier refund / pending item removal), C2-3 (free with an extra charge).
 */
class BoundaryStatusTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores, BoundaryHarness;

    protected function setUp(): void
    {
        parent::setUp();
        $this->initWorld();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** A rent on a fresh outfit at $price (3 days, tomorrow) with the given down payment (raw string). */
    private function rentAt(string|float $price, string $dp, string $kind = 'down_payment'): int
    {
        return $this->create($this->createPayload(['amount_paid' => $dp, 'payment_kind' => $kind], [$this->outfitAt($price)]));
    }

    // ================================================================ status values

    public function test_status_values(): void
    {
        $cases = [
            ['cancelled', ['status' => 'cancelled'], 'accept'], ['active (already active)', ['status' => 'active'], 'any'], ['returned (not fully paid)', ['status' => 'returned'], 'reject'],
            ['"foo"', ['status' => 'foo'], 'reject'], ['empty', ['status' => ''], 'reject'], ['null', ['status' => null], 'reject'], ['missing', [], 'reject'],
            ['array', ['status' => ['cancelled']], 'reject'], ['"CANCELLED" (upper case)', ['status' => 'CANCELLED'], 'reject'], ['"cancelled " (space)', ['status' => 'cancelled '], 'any'],
            ['int 1', ['status' => 1], 'reject'], ['bool true', ['status' => true], 'reject'], ['10k chars', ['status' => str_repeat('a', 10000)], 'reject'],
        ];
        foreach ($cases as [$name, $payload, $expect]) {
            $this->inCase("status {$name}", function (string $label) use ($payload, $expect) {
                $id = $this->rentAt(660000, '100000');
                $res = $this->attempt($label, 'post', route('rentals.status', $id), $payload, 'mgr1', 1, $expect);
                if ($res['changed']) {
                    $this->invariants($label);
                }
            });
        }
        $this->assertNoUnexplained();
    }

    public function test_cancel_refund_flag_values(): void
    {
        // refund: management only. Anything that reads as "true" refunds the 100.000, everything else keeps the deposit.
        $cases = [
            ['true', true, 'accept', 100000.0], ['"1"', '1', 'accept', 100000.0], ['"true"', 'true', 'accept', 100000.0], ['"on"', 'on', 'accept', 100000.0], ['"yes"', 'yes', 'accept', 100000.0],
            ['false', false, 'accept', 0.0], ['"0"', '0', 'accept', 0.0], ['"false"', 'false', 'accept', 0.0], ['"abc"', 'abc', 'accept', 0.0], ['empty', '', 'accept', 0.0],
            ['null', null, 'accept', 0.0], ['array [1]', [1], 'accept', null], ['array []', [], 'accept', 0.0],
        ];
        foreach ($cases as [$name, $flag, $expect, $refund]) {
            $this->inCase("cancel refund={$name}", function (string $label) use ($flag, $expect, $refund) {
                $id = $this->rentAt(660000, '100000');
                $res = $this->attempt($label, 'post', route('rentals.status', $id), ['status' => 'cancelled', 'refund' => $flag], 'mgr1', 1, $expect);
                $back = $this->cashBack($id);
                if ($refund !== null && abs($back - $refund) > 0.005) {
                    $this->problem(null, "{$label}: handed back {$back}, expected {$refund}", 'wrong-money');
                }
                $this->log[] = "   -> handed back {$back}";
                $this->invariants($label);
            });
        }
        // non-management asking for a refund must be refused with no change
        foreach (['admin1', 'sup1'] as $actor) {
            $this->inCase("cancel refund=true by {$actor}", function (string $label) use ($actor) {
                $id = $this->rentAt(660000, '100000');
                $this->attempt($label, 'post', route('rentals.status', $id), ['status' => 'cancelled', 'refund' => true], $actor, 1, 'reject');
            });
        }
        $this->assertNoUnexplained();
    }

    public function test_cancel_and_reopen_cycles_with_odd_amounts(): void
    {
        // Paid amounts with cents; every cycle must leave cash handed back <= cash received and the ledger balanced.
        foreach (['100000', '333333,33', '0,01', '99999,99', '1'] as $dp) {
            $this->inCase("cancel x2 (keep deposit), DP {$dp}", function (string $label) use ($dp) {
                $id = $this->rentAt(660000, $dp);
                $this->attempt("{$label}: cancel", 'post', route('rentals.status', $id), ['status' => 'cancelled'], 'mgr1', 1, 'accept');
                $this->attempt("{$label}: cancel again", 'post', route('rentals.status', $id), ['status' => 'cancelled'], 'mgr1', 1, 'nomove');
                $this->invariants($label);
            });
            $this->inCase("cancel WITH refund x2, DP {$dp}", function (string $label) use ($dp) {
                $id = $this->rentAt(660000, $dp);
                $paid = $this->w->paidOf($id);
                $this->attempt("{$label}: cancel + refund", 'post', route('rentals.status', $id), ['status' => 'cancelled', 'refund' => true], 'mgr1', 1, 'accept');
                $this->attempt("{$label}: cancel + refund again", 'post', route('rentals.status', $id), ['status' => 'cancelled', 'refund' => true], 'mgr1', 1, 'nomove');
                if (abs($this->cashBack($id) - $paid) > 0.005) {
                    $this->problem(null, "{$label}: handed back {$this->cashBack($id)} of {$paid}", 'wrong-money');
                }
                $this->invariants($label);
            });
            $this->inCase("cancel+refund -> reopen -> cancel+refund, DP {$dp}", function (string $label) use ($dp) {
                $id = $this->rentAt(660000, $dp);
                $paid = $this->w->paidOf($id);
                $this->attempt("{$label}: cancel + refund", 'post', route('rentals.status', $id), ['status' => 'cancelled', 'refund' => true], 'mgr1', 1, 'accept');
                $this->attempt("{$label}: reopen", 'post', route('rentals.status', $id), ['status' => 'active'], 'mgr1', 1, 'accept');
                $net = $this->cashBack($id);
                $this->attempt("{$label}: cancel + refund 2nd time", 'post', route('rentals.status', $id), ['status' => 'cancelled', 'refund' => true], 'mgr1', 1, 'any');
                $this->log[] = "   -> paid {$paid}, handed back after reopen {$net}, after 2nd cancel {$this->cashBack($id)}";
                if ($this->cashBack($id) - $paid > 0.005) {
                    $this->problem(null, "{$label}: handed back {$this->cashBack($id)} of only {$paid} received", 'wrong-money');
                }
                $this->invariants($label);
            });
        }
        $this->assertNoUnexplained();
    }

    public function test_cancel_by_admin_after_a_pay(): void
    {
        // admin cancel of a paid rent: keeps the deposit; double submit changes nothing more
        $this->inCase('admin cancel x2 after pay', function (string $label) {
            $id = $this->rentAt(660000, '100000');
            $this->attempt("{$label}: Pay 100.000 (admin)", 'put', route('rentals.update', $id), $this->payPayload($id, '100000'), 'admin1', 1, 'accept');
            $this->attempt("{$label}: cancel", 'post', route('rentals.status', $id), ['status' => 'cancelled'], 'admin1', 1, 'any');
            $this->attempt("{$label}: cancel again", 'post', route('rentals.status', $id), ['status' => 'cancelled'], 'admin1', 1, 'nomove');
            $this->invariants($label);
        });
        $this->assertNoUnexplained();
    }

    // ================================================================ free requests

    public function test_free_request_values(): void
    {
        $cases = [
            ['"abc" (3 chars)', 'abc', 'accept'], ['"ab" (2 chars)', 'ab', 'reject'], ['empty', '', 'reject'], ['null', null, 'reject'], ['spaces only', '     ', 'reject'],
            ['500 chars', str_repeat('r', 500), 'accept'], ['501 chars', str_repeat('r', 501), 'reject'], ['10k chars', str_repeat('r', 10000), 'reject'],
            ['500 emoji', str_repeat("\u{1F600}", 500), 'accept'], ['array', ['endorse'], 'reject'], ['int', 12345, 'any'], ['html', '<b>x</b><script>1</script>', 'accept'],
            ['NUL byte', "ab\0cd", 'any'], ['missing', null, 'reject'],
        ];
        foreach ($cases as [$name, $reason, $expect]) {
            $this->inCase("free-request reason {$name}", function (string $label) use ($reason, $expect) {
                $id = $this->rentAt(660000, '100000');
                $res = $this->attempt($label, 'post', route('rentals.free-request', $id), ['reason' => $reason], 'admin1', 1, $expect);
                if ($res['changed']) {
                    $this->invariants($label);
                }
            });
        }
        $this->assertNoUnexplained();
    }

    public function test_free_approval_with_odd_amounts_refunds_exactly_once(): void
    {
        foreach (['100000', '333333,33', '0,01', '659999,99', '1'] as $dp) {
            $this->inCase("free request + approve x2, DP {$dp}", function (string $label) use ($dp) {
                $id = $this->rentAt(660000, $dp);
                $paid = $this->w->paidOf($id);
                $this->attempt("{$label}: request", 'post', route('rentals.free-request', $id), ['reason' => 'endorse'], 'admin1', 1, 'accept');
                $this->attempt("{$label}: request again", 'post', route('rentals.free-request', $id), ['reason' => 'endorse'], 'admin1', 1, 'nomove');
                $a = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'free')->where('payload->rental_id', $id)->first();
                if (! $a) {
                    $this->problem(null, "{$label}: no pending free approval", 'setup');

                    return;
                }
                $this->attempt("{$label}: approve", 'post', route('approvals.rentals.approve', $a->id), [], 'sup1', 1, 'accept');
                $this->attempt("{$label}: approve again", 'post', route('approvals.rentals.approve', $a->id), [], 'sup1', 1, 'nomove');
                $this->attempt("{$label}: reject after approve", 'post', route('approvals.rentals.reject', $a->id), [], 'sup1', 1, 'nomove');
                if (abs($this->cashBack($id) - $paid) > 0.005) {
                    $this->problem(null, "{$label}: FREE handed back {$this->cashBack($id)}, paid {$paid}", 'wrong-money');
                }
                if (abs($this->bill($id)) > 0.005) {
                    $this->problem(null, "{$label}: bill after FREE is {$this->bill($id)}", 'wrong-money');
                }
                $this->invariants($label);
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ delete (wrong input)

    public function test_delete_values(): void
    {
        $cases = [
            ['paid rent, plain delete by management', 'mgr1', [], 'reject'], ['wrong_input without reason', 'mgr1', ['wrong_input' => true], 'reject'],
            ['wrong_input reason 2 chars', 'mgr1', ['wrong_input' => true, 'reason' => 'ab'], 'reject'], ['wrong_input reason 501 chars', 'mgr1', ['wrong_input' => true, 'reason' => str_repeat('r', 501)], 'reject'],
            ['wrong_input reason 10k chars', 'mgr1', ['wrong_input' => true, 'reason' => str_repeat('r', 10000)], 'reject'], ['wrong_input reason array', 'mgr1', ['wrong_input' => true, 'reason' => ['x']], 'reject', 'B2'],
            ['wrong_input array', 'mgr1', ['wrong_input' => [1], 'reason' => 'typed twice'], 'reject'], ['wrong_input "abc"', 'mgr1', ['wrong_input' => 'abc', 'reason' => 'typed twice'], 'reject'],
            ['wrong_input valid by management', 'mgr1', ['wrong_input' => true, 'reason' => 'typed twice'], 'accept'], ['wrong_input valid by supervisor (request)', 'sup1', ['wrong_input' => true, 'reason' => 'typed twice'], 'accept'],
            ['admin plain delete of a paid rent', 'admin1', [], 'reject'], ['admin wrong_input', 'admin1', ['wrong_input' => true, 'reason' => 'typed twice'], 'reject'],
            ['accounting', 'acc', ['wrong_input' => true, 'reason' => 'typed twice'], 'reject'],
        ];
        foreach ($cases as $case) {
            [$name, $actor, $payload, $expect] = $case;
            $finding = $case[4] ?? null;
            $this->inCase("delete {$name}", function (string $label) use ($actor, $payload, $expect, $finding) {
                $id = $this->rentAt(660000, '100000');
                $res = $this->attempt($label, 'delete', route('rentals.destroy', $id), $payload, $actor, 1, $expect, $finding);
                if ($res['changed']) {
                    $this->attempt("{$label}: same request again", 'delete', route('rentals.destroy', $id), $payload, $actor, 1, 'nomove');
                    $this->invariants($label);
                }
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ approval routes with garbage

    public function test_approval_routes_with_garbage_ids(): void
    {
        $ids = ['abc', '0', '-1', '99999999999999999999', '1%20OR%201=1', '%00', '1.5', '1e3', "1'", '9999999'];
        foreach (['approve', 'reject', 'cancel'] as $action) {
            foreach ($ids as $raw) {
                $this->inCase("{$action} approval id {$raw}", function (string $label) use ($action, $raw) {
                    $id = $this->rentAt(660000, '100000');
                    $this->attempt($label, 'post', "/approvals/rentals/{$raw}/{$action}", [], 'mgr1', 1, 'reject');
                });
            }
        }
        foreach (['abc', '0', '-1', '99999999999999999999', '1%20OR%201=1', '%00', '9999999'] as $raw) {
            foreach (['status', 'pickup', 'free-request', 'late-done-request'] as $action) {
                $this->inCase("rental id {$raw} /{$action}", function (string $label) use ($raw, $action) {
                    $this->attempt($label, 'post', "/rentals/{$raw}/{$action}", ['status' => 'cancelled', 'reason' => 'abc', 'picked_up' => true], 'mgr1', 1, 'reject');
                });
            }
            $this->inCase("rental id {$raw} PUT/DELETE", function (string $label) use ($raw) {
                $this->attempt($label.' put', 'put', "/rentals/{$raw}", $this->createPayload(), 'mgr1', 1, 'reject');
                $this->attempt($label.' delete', 'delete', "/rentals/{$raw}", [], 'mgr1', 1, 'reject');
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ edit request by admin: approve twice with odd values

    public function test_admin_edit_request_fractional_discount_approve_twice(): void
    {
        // lowering changes on a rent paid in full (refund once), raising changes on a down-payment rent
        foreach ([['discount 33.333,33 fixed', ['discount' => '33333,33', 'discount_type' => 'fixed', 'discount_note' => 'promo'], true],
            ['discount 12,5 percent', ['discount' => '12.5', 'discount_type' => 'percent', 'discount_note' => 'promo'], true],
            ['extra charge 0,01', ['extra_charge' => '0,01'], false],
            ['dates +3 days', ['__extend' => 3], false]] as [$name, $change, $full]) {
            $this->inCase("admin edit ({$name}) -> approve x2", function (string $label) use ($change, $full) {
                $id = $full ? $this->rentAt(660000, '660000', 'full') : $this->rentAt(660000, '100000');
                $p = $this->w->basePayload($this->row($id));
                if (isset($change['__extend'])) {
                    $p['end_date'] = Carbon::parse($p['end_date'])->addDays($change['__extend'])->toDateString();
                } else {
                    $p = array_merge($p, $change);
                }
                $this->attempt("{$label}: admin saves", 'put', route('rentals.update', $id), $p, 'admin1', 1, 'accept');
                $a = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'edit')->where('payload->rental_id', $id)->first();
                if (! $a) {
                    $this->problem(null, "{$label}: no pending edit approval (admin Save went direct?)", 'setup');

                    return;
                }
                $this->attempt("{$label}: approve", 'post', route('approvals.rentals.approve', $a->id), [], 'mgr1', 1, 'accept');
                $this->attempt("{$label}: approve again", 'post', route('approvals.rentals.approve', $a->id), [], 'mgr1', 1, 'nomove');
                $this->log[] = "   -> bill {$this->bill($id)} paid {$this->w->paidOf($id)} back {$this->cashBack($id)}";
                $this->invariants($label);
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ overdue fee and overdue payment

    /** A rent that ended 8 days ago (down payment 100.000 of 660.000), the shape the overdue modal works on. */
    private function lateRent(): array
    {
        $id = $this->create($this->createPayload(['amount_paid' => '100000'], [$this->outfit(3)], 3, -10), 'setup late rent');
        $r = $this->row($id);
        $end = substr((string) $r->end_date, 0, 10);

        return [$id, $end];
    }

    private function feePayload(int $id, string $end, array $over = []): array
    {
        return array_merge($this->w->basePayload($this->row($id)), [
            'overdue_fee_amount' => '200000', 'overdue_fee_rate' => '100000', 'overdue_fee_days' => '2',
            'overdue_base_end_date' => $end, 'overdue_charge_through_date' => Carbon::parse($end)->addDays(2)->toDateString(),
        ], $over);
    }

    public function test_overdue_fee_values(): void
    {
        // The fee amount is free text by design (P8, known): only shape and storage are judged here.
        $cases = [
            ['amount "200000"', ['overdue_fee_amount' => '200000'], 'accept', 200000.0], ['amount "200.000"', ['overdue_fee_amount' => '200.000'], 'any', null],
            ['amount "200000,50"', ['overdue_fee_amount' => '200000,50'], 'any', null], ['amount 0', ['overdue_fee_amount' => '0'], 'nomove', 0.0],
            ['amount -1', ['overdue_fee_amount' => '-1'], 'reject', null], ['amount "abc"', ['overdue_fee_amount' => 'abc'], 'reject', null], ['amount array', ['overdue_fee_amount' => [1]], 'reject', null],
            ['amount 999999999,99', ['overdue_fee_amount' => '999999999.99'], 'any', null], ['amount 1e15', ['overdue_fee_amount' => '1e15'], 'reject', null],
            ['amount PHP_INT_MAX', ['overdue_fee_amount' => (string) PHP_INT_MAX], 'reject', null],
            ['rate -1', ['overdue_fee_rate' => '-1'], 'reject', null], ['rate "abc"', ['overdue_fee_rate' => 'abc'], 'reject', null], ['rate 1e15', ['overdue_fee_rate' => '1e15'], 'reject', null],
            ['days -1', ['overdue_fee_days' => '-1'], 'reject', null], ['days 0', ['overdue_fee_days' => '0'], 'any', null], ['days 36500', ['overdue_fee_days' => '36500'], 'any', null],
            ['days 36501', ['overdue_fee_days' => '36501'], 'reject', null], ['days 1.5', ['overdue_fee_days' => '1.5'], 'reject', null], ['days "abc"', ['overdue_fee_days' => 'abc'], 'reject', null],
            ['days array', ['overdue_fee_days' => [1]], 'reject', null],
            ['through date before the end date', ['overdue_charge_through_date' => '2020-01-01'], 'any', null], ['through date 9999-12-31', ['overdue_charge_through_date' => '9999-12-31'], 'any', null],
            ['through date 2026-02-30', ['overdue_charge_through_date' => '2026-02-30'], 'reject', null], ['through date array', ['overdue_charge_through_date' => ['2026-10-01']], 'reject', null],
            ['base date unrelated', ['overdue_base_end_date' => '2020-01-01'], 'any', null], ['base date "abc"', ['overdue_base_end_date' => 'abc'], 'reject', null],
        ];
        foreach ($cases as [$name, $over, $expect, $stored]) {
            $this->inCase("overdue fee {$name}", function (string $label) use ($over, $expect, $stored) {
                [$id, $end] = $this->lateRent();
                $res = $this->attempt($label, 'put', route('rentals.update', $id), $this->feePayload($id, $end, $over), 'mgr1', 1, $expect);
                $fee = (float) $this->row($id)->overdue_fee_amount;
                $this->log[] = "   -> stored fee {$fee}, bill {$this->bill($id)}, paid {$this->w->paidOf($id)}";
                if ($res['ok'] && $stored !== null && abs($fee - $stored) > 0.005) {
                    $this->problem(null, "{$label}: stored fee {$fee}, expected {$stored}", 'wrong-money');
                }
                if ($res['changed']) {
                    $this->overdueInvariants($label, $id);
                }
            });
        }
        $this->assertNoUnexplained();
    }

    /** Shared invariants, with the bill checked against pivots + overdue fee (the shared I13 does not know the overdue fee). */
    private function overdueInvariants(string $label, int $id): void
    {
        $this->invariants($label, null, ['I13-bill-math']);
        $r = $this->row($id);
        $fee = max(0.0, (float) ($r->overdue_fee_amount ?? 0));
        $expected = round($this->w->billOf($r) + $fee, 2);
        $app = round((float) $this->appSummary($id)['net_incl_tax'], 2);
        $this->log[] = "   -> overdue bill check: app {$app} vs pivots+fee {$expected}";
        if (abs($app - $expected) > 0.01) {
            $this->problem(null, "{$label}: app bill with overdue {$app}, pivots + fee {$expected}", 'wrong-money');
        }
    }

    public function test_overdue_payment_values(): void
    {
        // fee 200.000 applied; the overdue payment must cover the whole fee (and is paid with the rent balance, see P1: amounts
        // ABOVE the fee are the known "change counted as paid" finding, so they are only recorded here).
        $cases = [
            ['0', '0', 'nomove', null], ['-1', '-1', 'reject', null], ['"abc"', 'abc', 'reject', null], ['array', [1], 'reject', null], ['empty', '', 'nomove', null],
            ['1 below the fee', '199999', 'reject', null], ['1 cent below the fee', '199999,99', 'reject', null], ['exactly the fee "200000"', '200000', 'accept', 200000.0],
            ['exactly the fee "200.000"', '200.000', 'accept', 200000.0], ['"Rp 200.000"', 'Rp 200.000', 'reject', null], ['1e15', '1e15', 'reject', null], ['PHP_INT_MAX', (string) PHP_INT_MAX, 'reject', null],
            ['"200000.50" (dot as decimal)', '200000.50', 'any', null],
        ];
        foreach ($cases as [$name, $raw, $expect, $delta]) {
            $this->inCase("overdue payment {$name}", function (string $label) use ($raw, $expect, $delta) {
                [$id, $end] = $this->lateRent();
                $this->attempt("{$label}: apply fee 200.000", 'put', route('rentals.update', $id), $this->feePayload($id, $end), 'mgr1', 1, 'accept');
                $r = $this->row($id);
                $p = $this->w->basePayload($r);
                $p += ['overdue_payment_entry' => 'increment', 'overdue_payment_amount_add' => $raw, 'overdue_payment_method' => 'cash'];
                $before = (float) DB::table('rental_payment_records')->where('rental_id', $id)->where('payment_kind', 'overdue')->sum('amount');
                $res = $this->attempt($label, 'put', route('rentals.update', $id), $p, 'mgr1', 1, $expect);
                $after = (float) DB::table('rental_payment_records')->where('rental_id', $id)->where('payment_kind', 'overdue')->sum('amount');
                $this->log[] = "   -> overdue records {$before} -> {$after}";
                if ($res['ok'] && $delta !== null && abs($after - $before - $delta) > 0.005) {
                    $this->problem(null, "{$label}: overdue records moved ".($after - $before).", expected {$delta}", 'wrong-money');
                }
                if ($res['changed']) {
                    $this->overdueInvariants($label, $id);
                }
            });
        }
        // the same exact overdue payment twice
        $this->inCase('overdue payment exact fee, sent twice', function (string $label) {
            [$id, $end] = $this->lateRent();
            $this->attempt("{$label}: apply fee", 'put', route('rentals.update', $id), $this->feePayload($id, $end), 'mgr1', 1, 'accept');
            $p = $this->w->basePayload($this->row($id)) + ['overdue_payment_entry' => 'increment', 'overdue_payment_amount_add' => '200000', 'overdue_payment_method' => 'cash'];
            $this->attempt("{$label}: first", 'put', route('rentals.update', $id), $p, 'mgr1', 1, 'accept');
            $p2 = $this->w->basePayload($this->row($id)) + ['overdue_payment_entry' => 'increment', 'overdue_payment_amount_add' => '200000', 'overdue_payment_method' => 'cash'];
            $this->attempt("{$label}: second identical", 'put', route('rentals.update', $id), $p2, 'mgr1', 1, 'nomove');
            $this->overdueInvariants($label, $id);
        });
        $this->assertNoUnexplained();
    }

    // ================================================================ decision notes and the refund account of an item removal

    public function test_decision_note_values(): void
    {
        foreach ([['text', 'looks fine'], ['10k chars', str_repeat('n', 10000)], ['array', ['x']], ['null', null], ['emoji', "ok \u{1F44D}"], ['int', 12345], ['NUL byte', "a\0b"], ['html', '<script>1</script>']] as [$name, $note]) {
            foreach (['approve', 'reject'] as $decision) {
                $this->inCase("{$decision} edit with decision_note = {$name}", function (string $label) use ($note, $decision) {
                    $id = $this->rentAt(660000, '100000');
                    $p = $this->w->basePayload($this->row($id));
                    $p['customer_name'] = 'Renamed';
                    $this->attempt("{$label}: admin edit", 'put', route('rentals.update', $id), $p, 'admin1', 1, 'accept');
                    $a = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'edit')->where('payload->rental_id', $id)->first();
                    if (! $a) {
                        $this->problem(null, "{$label}: no pending edit request", 'setup');

                        return;
                    }
                    $res = $this->attempt($label, 'post', route("approvals.rentals.{$decision}", $a->id), ['decision_note' => $note], 'mgr1', 1, 'any', is_array($note) ? 'B2' : null);
                    if ($res['changed']) {
                        $this->invariants($label);
                    }
                });
            }
        }
        $this->assertNoUnexplained();
    }

    public function test_item_removal_refund_account_values(): void
    {
        // refund_payment_account_id is used as the credit account of the refund journal entry. It should be a cash / bank account.
        $codes = ['1100' => 'cash', '1200' => 'bank', '1300' => 'receivable', '2300' => 'deposit liability', '4100' => 'rental revenue', '4910' => 'returns'];
        $values = ['none' => null, 'empty' => '', 'abc' => 'abc', 'negative' => -1, 'zero' => 0, 'unknown id' => 999999, 'array' => [1], 'huge' => '99999999999999999999'];
        foreach ($codes as $code => $name) {
            $values["account {$code} ({$name})"] = 'CODE:'.$code;
        }
        foreach ($values as $name => $value) {
            $this->inCase("item removal approved with refund account = {$name}", function (string $label) use ($value, $name) {
                $a1 = $this->outfitAt(330000);
                $a2 = $this->outfitAt(440000);
                $id = $this->create($this->createPayload(['payment_kind' => 'full', 'amount_paid' => '770000'], [$a1, $a2]));
                $p = $this->w->basePayload($this->row($id));
                $p['outfit_ids'] = [$a1];
                unset($p['item_discounts'][$a2], $p['item_discount_types'][$a2], $p['item_discount_notes'][$a2]);
                $this->attempt("{$label}: remove 1 of 2 items", 'put', route('rentals.update', $id), $p, 'mgr1', 1, 'accept');
                $req = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'item_removal')->where('payload->rental_id', $id)->first();
                if (! $req) {
                    $this->problem(null, "{$label}: no pending item_removal request", 'setup');

                    return;
                }
                $accountId = is_string($value) && str_starts_with($value, 'CODE:') ? (int) DB::table('accounts')->where('code', substr($value, 5))->value('id') : $value;
                $isCashLike = is_string($value) && in_array(substr($value, 5), ['1100', '1200'], true);
                $payload = $value === null ? [] : ['refund_payment_account_id' => $accountId];
                $res = $this->attempt($label, 'post', route('approvals.item-removal.approve', $req->id), $payload, 'sup1', 1, 'any', null);
                $credit = DB::table('journal_entries as e')->join('journal_entry_lines as l', 'l.journal_entry_id', '=', 'e.id')->join('accounts as a', 'a.id', '=', 'l.account_id')
                    ->where('e.reference_type', 'item_removal_refund')->where('e.reference_id', $id)->where('l.credit', '>', 0)->pluck('a.code')->all();
                $status = DB::table('rental_approval_requests')->where('id', $req->id)->value('status');
                $this->log[] = "   -> approval {$status}, refund credited to account(s): ".json_encode($credit)." (cash handed back {$this->cashBack($id)})";
                $nonCash = array_diff($credit, ['1100', '1200']);
                if ($res['ok'] && $nonCash !== []) {
                    $this->problem('B9', "{$label}: refund of 440.000 (the removed outfit) was credited to ".implode(',', $nonCash).' instead of a cash / bank account');
                }
                if ($res['ok'] && $credit === [] && $status === 'approved') {
                    $this->problem('B9', "{$label}: removal approved ({$status}) but NO refund entry exists (refund of 440.000 not booked)");
                }
                $this->invariants($label, 'B9');
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ late "pickup / return" reports

    public function test_late_done_values(): void
    {
        $now = Carbon::now();
        $cases = [
            ['pickup 2 days ago', ['type' => 'pickup', 'picked_up_at' => $now->copy()->subDays(2)->toDateTimeString(), 'picked_up_by' => 'U'], 'accept'],
            ['pickup in the future', ['type' => 'pickup', 'picked_up_at' => $now->copy()->addDay()->toDateTimeString(), 'picked_up_by' => 'U'], 'reject'],
            ['pickup year 1900', ['type' => 'pickup', 'picked_up_at' => '1900-01-01 00:00:00', 'picked_up_by' => 'U'], 'any', 'B10'],
            ['pickup 2026-02-30', ['type' => 'pickup', 'picked_up_at' => '2026-02-30 10:00:00', 'picked_up_by' => 'U'], 'reject'],
            ['pickup array', ['type' => 'pickup', 'picked_up_at' => ['x'], 'picked_up_by' => 'U'], 'reject'], ['pickup missing time', ['type' => 'pickup', 'picked_up_by' => 'U'], 'reject'],
            ['pickup unknown user', ['type' => 'pickup', 'picked_up_at' => $now->copy()->subDay()->toDateTimeString(), 'picked_up_by' => 999999], 'reject'],
            ['pickup user array', ['type' => 'pickup', 'picked_up_at' => $now->copy()->subDay()->toDateTimeString(), 'picked_up_by' => ['U']], 'reject'],
            ['type "abc"', ['type' => 'abc', 'picked_up_at' => $now->copy()->subDay()->toDateTimeString(), 'picked_up_by' => 'U'], 'reject'], ['type array', ['type' => ['pickup']], 'reject'],
            ['return before pickup', ['type' => 'return', 'picked_up_at' => $now->copy()->subDay()->toDateTimeString(), 'picked_up_by' => 'U', 'returned_at' => $now->copy()->subDays(3)->toDateTimeString(), 'returned_by' => 'U'], 'reject'],
            ['return after pickup', ['type' => 'return', 'picked_up_at' => $now->copy()->subDays(2)->toDateTimeString(), 'picked_up_by' => 'U', 'returned_at' => $now->copy()->subDay()->toDateTimeString(), 'returned_by' => 'U'], 'accept'],
            ['note 301 chars', ['type' => 'pickup', 'picked_up_at' => $now->copy()->subDay()->toDateTimeString(), 'picked_up_by' => 'U', 'note' => str_repeat('n', 301)], 'reject'],
            ['note array', ['type' => 'pickup', 'picked_up_at' => $now->copy()->subDay()->toDateTimeString(), 'picked_up_by' => 'U', 'note' => ['x']], 'reject'],
        ];
        foreach ($cases as $case) {
            [$name, $payload, $expect] = $case;
            $finding = $case[3] ?? null;
            $this->inCase("late-done {$name}", function (string $label) use ($payload, $expect, $finding) {
                $id = $this->rentAt(660000, '660000', 'full');
                $uid = $this->w->users['admin1']->id;
                $payload = array_map(fn ($v) => $v === 'U' ? $uid : $v, $payload);
                $res = $this->attempt($label, 'post', route('rentals.late-done-request', $id), $payload, 'admin1', 1, $expect);
                if ($finding !== null && $res['ok']) {
                    $this->hits[$finding][] = "{$label}: accepted ({$payload['picked_up_at']}) - rent starts ".substr((string) $this->row($id)->start_date, 0, 10);
                }
                if ($res['changed']) {
                    $a = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'late_done')->where('payload->rental_id', $id)->first();
                    if ($a) {
                        $this->attempt("{$label}: approve", 'post', route('approvals.rentals.approve', $a->id), [], 'sup1', 1, 'any');
                        $this->attempt("{$label}: approve again", 'post', route('approvals.rentals.approve', $a->id), [], 'sup1', 1, 'nomove');
                    }
                    $this->invariants($label);
                }
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ calibration: the harness must see a known problem

    public function test_calibration_a_mismatch_is_noticed(): void
    {
        // P3 is fixed: Pay on a CANCELLED rent is refused. Deliberately expecting 'accept' here must produce a verdict,
        // proving the harness notices when reality differs from the expectation. The verdict is booked under CALIBRATION, not failed.
        $this->inCase('calibration: Pay on a cancelled rent (P3 fixed, expectation reversed on purpose)', function (string $label) {
            $id = $this->rentAt(660000, '100000');
            $this->attempt("{$label}: cancel", 'post', route('rentals.status', $id), ['status' => 'cancelled'], 'mgr1', 1, 'accept');
            $res = $this->attempt($label, 'put', route('rentals.update', $id), $this->payPayload($id, '1000'), 'mgr1', 1, 'accept', 'CALIBRATION');
            $this->assertFalse($res['ok'], 'P3 stays fixed: the Pay on a cancelled rent is refused');
        });
        $this->assertArrayHasKey('CALIBRATION', $this->hits, 'the harness flagged the mismatch between expectation and answer');
        $this->assertNoUnexplained();
    }
}
