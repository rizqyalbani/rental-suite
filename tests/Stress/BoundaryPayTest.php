<?php

namespace Tests\Stress;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * Boundary / hostile input at PAY and EDIT (PUT rentals.update): the Pay button, odd-sized partial payments, the 1-cent
 * boundary of the Return gate, payment_entry=replace, hostile edit fields.
 *
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/BoundaryPayTest.php
 *   BOUNDARY_VERBOSE=1 BOUNDARY_HITS=1 php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/BoundaryPayTest.php
 *
 * Known findings left out on purpose: P2 (every Save re-prices at today's catalogue price: catalogue prices are never
 * changed here), P1 (overdue change counted as paid: overdue payments never exceed the fee here), P3 (Pay on a cancelled
 * rent: never done), C3-3 (DP covering the whole bill), P12 (Pay on a frozen rent answers 200 and records nothing).
 */
class BoundaryPayTest extends TestCase
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

    /** A down-payment rent: outfit 3 (Rp 660.000), 3 days, DP 100.000 -> Rp 560.000 still to pay. */
    private function dpRent(string $dp = '100000', int $outfitIdx = 3, int $days = 3, array $over = [], array $outfits = []): int
    {
        return $this->create($this->createPayload(['amount_paid' => $dp] + $over, $outfits ?: [$this->outfit($outfitIdx)], $days));
    }

    /**
     * One Pay-button request inside its own savepoint on a fresh Rp 560.000-to-pay rent.
     *
     * @param  'reject'|'accept'|'any'|'nomove'  $expect
     */
    private function tryPay(string $label, mixed $amount, string $expect, ?float $delta = null, ?string $finding = null, ?string $kind = null, string $actor = 'mgr1', ?callable $after = null): void
    {
        $this->inCase($label, function () use ($label, $amount, $expect, $delta, $finding, $kind, $actor, $after) {
            $id = $this->dpRent();
            $before = $this->w->paidOf($id);
            $res = $this->attempt($label, 'put', route('rentals.update', $id), $this->payPayload($id, $amount, $kind), $actor, 1, $expect, $finding);
            $paid = $this->w->paidOf($id);
            if ($res['ok'] && $delta !== null && abs($paid - $before - $delta) > 0.005) {
                $this->problem($finding, "{$label}: paid moved by ".round($paid - $before, 2).", expected {$delta}", 'wrong-money');
            }
            if ($res['changed']) {
                if ($after) {
                    $after($id);
                }
                $this->invariants($label, $finding);
            }
        });
    }

    // ================================================================ Pay amount table

    public function test_pay_amount_table(): void
    {
        // Rent: bill 660.000, DP 100.000, so 560.000 is still to pay.
        $cases = [
            // nothing / negative / not a number
            ['0', '0', 'nomove', null], ['int 0', 0, 'nomove', null], ['"0,00"', '0,00', 'nomove', null], ['empty', '', 'nomove', null], ['null', null, 'nomove', null],
            ['-1', '-1', 'reject', null], ['-100000', '-100000', 'reject', null], ['int -1', -1, 'reject', null], ['"-0,01"', '-0,01', 'reject', null],
            ['"abc"', 'abc', 'reject', null], ['"Rp 100.000"', 'Rp 100.000', 'reject', null], ['"100 000"', '100 000', 'reject', null], ['"NaN"', 'NaN', 'reject', null],
            ['"INF"', 'INF', 'reject', null], ['hex', '0x10', 'reject', null], ['array []', [], 'nomove', null], ['array [100000]', [100000], 'reject', null],
            ['object', ['a' => 1], 'reject', null], ['arabic digits', '١٠٠٠٠٠', 'reject', null],
            // valid spellings of a partial payment
            ['1', '1', 'accept', 1.0], ['"0,01"', '0,01', 'accept', 0.01], ['"100000"', '100000', 'accept', 100000.0], ['int 100000', 100000, 'accept', 100000.0],
            ['"100.000"', '100.000', 'accept', 100000.0], ['" 100.000 "', ' 100.000 ', 'accept', 100000.0], ['"100000,50"', '100000,50', 'accept', 100000.5],
            ['"100.000,50"', '100.000,50', 'accept', 100000.5], ['"333.333,33"', '333.333,33', 'accept', 333333.33],
            // exactly the balance / 1 cent over / over
            ['balance "560000"', '560000', 'accept', 560000.0], ['balance "560.000"', '560.000', 'accept', 560000.0], ['balance "560000,00"', '560000,00', 'accept', 560000.0],
            ['balance - 1 cent', '559999,99', 'accept', 559999.99], ['balance + 1 cent', '560000,01', 'reject', null], ['balance + 1', '560001', 'reject', null],
            ['bill itself "660000"', '660000', 'reject', null], ['"1.000.000"', '1.000.000', 'reject', null], ['"999999999,99" (validator max)', '999999999,99', 'reject', null],
            ['"1000000000"', '1000000000', 'reject', null], ['int 9999999999999', 9999999999999, 'reject', null], ['int PHP_INT_MAX', PHP_INT_MAX, 'reject', null],
            ['float 1e15', 1e15, 'reject', null], ['"1e15"', '1e15', 'reject', null], ['"1E+15"', '1E+15', 'reject', null], ['400 digits', str_repeat('9', 400), 'reject', null],
            // ambiguous spellings (MoneyInput strips every "." and reads "," as the decimal point)
            ['"100,000" (English thousands = Rp 100)', '100,000', 'any', null], ['"100000.50" (English decimals)', '100000.50', 'any', null],
            ['"1e5" = 100000', '1e5', 'any', null], ['"1.5e5"', '1.5e5', 'any', null], ['"+100000"', '+100000', 'any', null], ['float 100000.5', 100000.5, 'any', null],
            ['"0,009" (below 1 cent)', '0,009', 'any', null], ['"100000,555" (3 decimals)', '100000,555', 'any', null], ['bool true', true, 'any', null],
        ];
        foreach ($cases as [$name, $raw, $expect, $delta]) {
            $this->tryPay("Pay {$name}", $raw, $expect, $delta, null, $name === 'balance "560000"' || $name === 'balance "560.000"' || $name === 'balance "560000,00"' ? 'final_payment' : null);
        }
        $this->assertNoUnexplained();
    }

    public function test_pay_by_other_roles(): void
    {
        // admin / supervisor / management apply a pure payment directly; accounting may not operate rents at all.
        foreach (['admin1', 'sup1', 'mgr1', 'sa'] as $actor) {
            foreach ([['partial "100.000"', '100.000', 'accept', 100000.0, null], ['0', '0', 'nomove', null, null], ['-5', '-5', 'reject', null, null],
                ['array', [1], 'reject', null, null], ['over balance', '560001', 'reject', null, null], ['"Rp 5"', 'Rp 5', 'reject', null, null]] as [$name, $raw, $expect, $delta]) {
                $this->tryPay("Pay by {$actor}: {$name}", $raw, $expect, $delta, null, null, $actor);
            }
        }
        foreach (['acc', 'sup2', 'admin3'] as $actor) {
            // accounting cannot operate rents; users of store 2 are not in store 1's session
            $this->tryPay("Pay by {$actor} (not allowed on this rent): 100000", '100000', 'reject', null, null, null, $actor);
        }
        $this->assertNoUnexplained();
    }

    public function test_pay_kind_and_method_values(): void
    {
        foreach ([['kind final_payment, partial', 'final_payment', '1000', 'any'], ['kind full, partial', 'full', '1000', 'any'], ['kind down_payment, partial', 'down_payment', '1000', 'accept'],
            ['kind overdue (internal)', 'overdue', '1000', 'reject'], ['kind "abc"', 'abc', '1000', 'reject'], ['kind empty', '', '1000', 'reject'], ['kind null', null, '1000', 'reject'],
            ['kind array', ['full'], '1000', 'reject']] as [$name, $kind, $amount, $expect]) {
            $this->inCase("Pay {$name}", function (string $label) use ($kind, $amount, $expect) {
                $id = $this->dpRent();
                $p = $this->payPayload($id, $amount);
                $p['payment_kind'] = $kind;
                $res = $this->attempt($label, 'put', route('rentals.update', $id), $p, 'mgr1', 1, $expect);
                if ($res['changed']) {
                    $kinds = DB::table('rental_payment_records')->where('rental_id', $id)->orderBy('id')->pluck('payment_kind')->all();
                    $this->log[] = '   -> payment record kinds '.json_encode($kinds).', rentals.payment_kind '.$this->row($id)->payment_kind.', paid '.$this->w->paidOf($id).' of bill '.$this->bill($id);
                    $this->invariants($label);
                }
            });
        }
        foreach ([['method 100 chars', str_repeat('m', 100), 'accept'], ['method 101 chars', str_repeat('m', 101), 'reject'], ['method empty', '', 'reject'], ['method array', ['cash'], 'reject'], ['method emoji', "qris \u{1F4B8}", 'accept']] as [$name, $method, $expect]) {
            $this->inCase("Pay {$name}", function (string $label) use ($method, $expect) {
                $id = $this->dpRent();
                $p = $this->payPayload($id, '1000');
                $p['payment_method'] = $method;
                $res = $this->attempt($label, 'put', route('rentals.update', $id), $p, 'mgr1', 1, $expect);
                if ($res['changed']) {
                    $this->invariants($label);
                }
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ partial payments that must add up exactly

    /**
     * Pays $chunks one by one (first = the down payment at creation). After every payment the rent must show exactly the
     * running total; after the last one paid == bill and a further payment of 1 cent is refused. Optionally the rent is
     * then picked up and returned (the Return gate needs "fully paid").
     *
     * @param  list<string>  $chunks  raw strings as typed by staff
     */
    private function oddChunks(string $label, string|float $price, array $chunks, float $expectBill, bool $returnIt = true): void
    {
        $this->inCase($label, function (string $label) use ($price, $chunks, $expectBill, $returnIt) {
            $sid = $this->outfitAt($price);
            $id = $this->create($this->createPayload(['amount_paid' => $chunks[0]], [$sid]), "{$label}: create with DP {$chunks[0]}");
            if (abs($this->bill($id) - $expectBill) > 0.005) {
                $this->problem(null, "{$label}: bill {$this->bill($id)} expected {$expectBill}", 'wrong-money');
            }
            $running = $this->w->paidOf($id);
            foreach (array_slice($chunks, 1) as $n => $raw) {
                $kind = $n === count($chunks) - 2 ? 'final_payment' : null;
                $res = $this->attempt("{$label}: pay #".($n + 2)." {$raw}", 'put', route('rentals.update', $id), $this->payPayload($id, $raw, $kind), 'mgr1', 1, 'accept');
                $newPaid = $this->w->paidOf($id);
                $inc = $this->parse($raw);
                if ($res['ok'] && abs($newPaid - ($running + $inc)) > 0.005) {
                    $this->problem(null, "{$label}: after paying {$raw} total is {$newPaid}, expected ".($running + $inc), 'wrong-money');
                }
                $running = $newPaid;
                if (abs((float) $this->row($id)->amount_paid - $newPaid) > 0.005) {
                    $this->problem(null, "{$label}: rentals.amount_paid {$this->row($id)->amount_paid} != records {$newPaid}", 'wrong-money');
                }
            }
            if (abs($running - $expectBill) > 0.005) {
                $this->problem(null, "{$label}: chunks add up to {$running}, bill is {$expectBill}", 'wrong-money');
            }
            $this->attempt("{$label}: one cent more", 'put', route('rentals.update', $id), $this->payPayload($id, '0,01', 'final_payment'), 'mgr1', 1, 'nomove');
            $this->invariants($label);
            if ($returnIt) {
                $this->attempt("{$label}: pickup", 'post', route('rentals.pickup', $id), ['picked_up' => true], 'mgr1', 1, 'accept');
                $this->attempt("{$label}: return (fully paid)", 'post', route('rentals.status', $id), ['status' => 'returned'], 'mgr1', 1, 'accept');
                $this->invariants("{$label} after return");
            }
        });
    }

    private function parse(string $raw): float
    {
        return (float) str_replace(',', '.', str_replace('.', '', trim($raw)));
    }

    public function test_odd_chunk_payments_add_up_exactly(): void
    {
        // 333.333 x 3 on a Rp 999.999 bill
        $this->oddChunks('3 x 333.333 on 999.999', 999999, ['333333', '333333', '333333'], 999999.0);
        // typed with the thousands dot
        $this->oddChunks('3 x "333.333" on 999.999', 999999, ['333.333', '333.333', '333.333'], 999999.0);
        // 100.000 in cents: 33.333,33 + 33.333,33 + 33.333,34
        $this->oddChunks('33.333,33 + 33.333,33 + 33.333,34 on 100.000', 100000, ['33333,33', '33333,33', '33333,34'], 100000.0);
        // the float trap: 0,1 + 0,2 vs 0,3
        $this->oddChunks('0,1 + 0,2 on 0,3', '0.3', ['0,1', '0,2'], 0.3);
        // 20 small payments
        $this->oddChunks('20 x 50 on 1.000', 1000, array_fill(0, 20, '50'), 1000.0);
        // 1 rupiah at a time: 12 payments on a Rp 12 bill
        $this->oddChunks('12 x 1 on 12', 12, array_fill(0, 12, '1'), 12.0);
        // 7 payments of 14.285,71 + last 14.285,73 = 100.000 (one cent apart)
        $this->oddChunks('6 x 14.285,71 + 14.285,74 on 100.000', 100000, ['14285,71', '14285,71', '14285,71', '14285,71', '14285,71', '14285,71', '14285,74'], 100000.0);
        // bill that is the sum of PPN-awkward prices
        $this->oddChunks('99.999 bill in 2 payments', 99999, ['66666', '33333'], 99999.0);
        $this->assertNoUnexplained();
    }

    public function test_return_gate_at_one_cent(): void
    {
        $this->inCase('return gate: 1 cent short, then exact', function (string $label) {
            $sid = $this->outfitAt(100000);
            $id = $this->create($this->createPayload(['amount_paid' => '99999,99'], [$sid]), "{$label}: DP 99.999,99 of 100.000");
            $this->attempt("{$label}: pickup while 1 cent short", 'post', route('rentals.pickup', $id), ['picked_up' => true], 'mgr1', 1, 'any');
            $this->attempt("{$label}: return while 1 cent short", 'post', route('rentals.status', $id), ['status' => 'returned'], 'mgr1', 1, 'reject');
            $this->attempt("{$label}: pay the last cent", 'put', route('rentals.update', $id), $this->payPayload($id, '0,01', 'final_payment'), 'mgr1', 1, 'accept');
            $this->attempt("{$label}: pickup", 'post', route('rentals.pickup', $id), ['picked_up' => true], 'mgr1', 1, 'any');
            $this->attempt("{$label}: return", 'post', route('rentals.status', $id), ['status' => 'returned'], 'mgr1', 1, 'accept');
            $this->invariants($label);
        });
        $this->assertNoUnexplained();
    }

    // ================================================================ double submit

    public function test_double_submit_pay_with_cents(): void
    {
        // The same exact-balance Pay twice, with a balance that has cents. The second must move nothing.
        foreach (['mgr1', 'admin1', 'sup1'] as $actor) {
            $this->inCase("double Pay exact balance with cents by {$actor}", function (string $label) use ($actor) {
                $sid = $this->outfitAt('0.3');
                $id = $this->create($this->createPayload(['amount_paid' => '0,1'], [$sid]), "{$label}: create");
                $pay = $this->payPayload($id, '0,2', 'final_payment');
                $this->attempt("{$label}: first Pay", 'put', route('rentals.update', $id), $pay, $actor, 1, 'accept');
                $this->attempt("{$label}: second identical Pay", 'put', route('rentals.update', $id), $pay, $actor, 1, 'nomove');
                $this->invariants($label);
                if (abs($this->w->paidOf($id) - 0.3) > 0.005) {
                    $this->problem(null, "{$label}: paid {$this->w->paidOf($id)} expected 0.3", 'wrong-money');
                }
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ Save with payment_entry=replace (API-level path)

    public function test_save_with_payment_entry_replace(): void
    {
        // The edit modal sends payment_entry=increment. 'replace' is the legacy default of update(): what does it do to money?
        foreach ([['replace to 560.000 (up)', '560000'], ['replace to 100.000 (same)', '100000'], ['replace to 50.000 (down)', '50000'], ['replace to 0,01', '0,01'],
            ['replace to 660.000 (= bill)', '660000'], ['replace to 700.000 (over)', '700000'], ['replace to 0', '0'], ['replace to -1', '-1'], ['replace to "abc"', 'abc'], ['replace to array', [1]]] as [$name, $amount]) {
            $this->inCase("Save {$name}", function (string $label) use ($amount) {
                $id = $this->dpRent();
                $p = $this->w->basePayload($this->row($id));
                $p['payment_entry'] = 'replace';
                unset($p['payment_amount_add']);
                $p['amount_paid'] = $amount;
                $before = $this->w->paidOf($id);
                $res = $this->attempt($label, 'put', route('rentals.update', $id), $p, 'mgr1', 1, 'any');
                $paid = $this->w->paidOf($id);
                $col = (float) $this->row($id)->amount_paid;
                $this->log[] = "   -> paid before {$before}, records after {$paid}, rentals.amount_paid {$col}";
                if ($res['changed']) {
                    if (abs($col - $paid) > 0.005) {
                        $this->problem('B7', "{$label}: rentals.amount_paid = {$col} but payment records add up to {$paid}");
                    }
                    $this->invariants($label, 'B7');
                }
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ hostile EDIT fields (management applies directly)

    /** @param  bool  $full  true = the rent is paid in full (660.000); false = down payment 100.000 (management cannot change discounts on a DP rent: the server locks them) */
    private function tryEdit(string $label, array $over, string $expect, ?string $finding = null, string $actor = 'mgr1', ?callable $after = null, int $days = 3, bool $full = false): void
    {
        $this->inCase($label, function (string $label) use ($over, $expect, $finding, $actor, $after, $days, $full) {
            $id = $this->create($this->createPayload($full ? ['payment_kind' => 'full', 'amount_paid' => '660000'] : ['amount_paid' => '100000'], [$this->outfit(3)], $days));
            $p = array_merge($this->w->basePayload($this->row($id)), $over);
            $res = $this->attempt($label, 'put', route('rentals.update', $id), $p, $actor, 1, $expect, $finding);
            if ($res['ok'] && $res['changed']) {
                if ($after) {
                    $after($id);
                }
                $this->invariants($label, $finding);
            }
        });
    }

    public function test_edit_dates(): void
    {
        $today = Carbon::now()->startOfDay();
        $d = fn (int $o) => $today->copy()->addDays($o)->toDateString();
        // rent: tomorrow .. +3 days (3 days). New dates below.
        foreach ([
            ['inverted', $d(5), $d(1), 'reject'], ['2026-02-30', '2026-02-30', '2026-03-04', 'reject'], ['same day (1 day)', $d(1), $d(1), 'accept'],
            ['extend to 4 days without extra charge', $d(1), $d(4), 'reject'], ['extend to 6 days (2 blocks)', $d(1), $d(6), 'accept'],
            ['shrink to 1 day', $d(1), $d(1), 'accept'], ['move to the past', $d(-40), $d(-38), 'accept'], ['far future 2 years', $d(730), $d(732), 'accept'],
            ['year 9999', '9999-12-29', '9999-12-31', 'accept'], ['text', 'abc', 'def', 'reject'], ['null', null, null, 'reject'], ['array', [$d(1)], [$d(3)], 'reject'],
        ] as [$name, $s, $e, $expect]) {
            $this->tryEdit("edit dates: {$name}", ['start_date' => $s, 'end_date' => $e], $expect, null, 'mgr1', function (int $id) use ($name) {
                $this->log[] = "   -> {$name}: bill now {$this->bill($id)}, paid {$this->w->paidOf($id)}";
            });
        }
        $this->assertNoUnexplained();
    }

    public function test_edit_discount_and_extra_charge(): void
    {
        $cases = [
            ['discount -1', ['discount' => '-1', 'discount_note' => 'x'], 'reject', null], ['discount "abc"', ['discount' => 'abc', 'discount_note' => 'x'], 'reject', null],
            ['discount 60.000', ['discount' => '60.000', 'discount_note' => 'x'], 'accept', null], ['discount > bill (1.000.000)', ['discount' => '1.000.000', 'discount_note' => 'x'], 'any', null],
            ['discount = 999999999,99', ['discount' => '999999999,99', 'discount_note' => 'x'], 'any', null], ['discount 1e15', ['discount' => '1e15', 'discount_note' => 'x'], 'reject', null],
            ['percent 101', ['discount' => '101', 'discount_type' => 'percent', 'discount_note' => 'x'], 'any', null], ['percent -5', ['discount' => '-5', 'discount_type' => 'percent', 'discount_note' => 'x'], 'reject', null],
            ['percent 100', ['discount' => '100', 'discount_type' => 'percent', 'discount_note' => 'x'], 'any', null], ['percent 0', ['discount' => '0', 'discount_type' => 'percent'], 'accept', null],
            ['percent 33.33', ['discount' => '33.33', 'discount_type' => 'percent', 'discount_note' => 'x'], 'accept', null],
            ['discount without a note', ['discount' => '60000', 'discount_note' => ''], 'reject', null],
            ['type empty', ['discount' => '60000', 'discount_type' => '', 'discount_note' => 'x'], 'any', 'B1'], ['type array', ['discount' => '60000', 'discount_type' => ['x'], 'discount_note' => 'x'], 'reject', 'B2'],
            ['type "weird"', ['discount' => '60000', 'discount_type' => 'weird', 'discount_note' => 'x'], 'reject', null],
            ['extra_charge -1', ['extra_charge' => '-1'], 'reject', null], ['extra_charge "abc"', ['extra_charge' => 'abc'], 'reject', null], ['extra_charge array', ['extra_charge' => [5]], 'reject', null],
            ['extra_charge 999999999,99', ['extra_charge' => '999999999,99'], 'any', null], ['extra_charge 1e15', ['extra_charge' => '1e15'], 'reject', null],
            ['item discount fixed -50.000', ['item_discounts' => [$this->outfit(3) => '-50000'], 'item_discount_types' => [$this->outfit(3) => 'fixed'], 'item_discount_notes' => [$this->outfit(3) => 'x']], 'reject', 'B3'],
            ['item discount "abc"', ['item_discounts' => [$this->outfit(3) => 'abc'], 'item_discount_types' => [$this->outfit(3) => 'fixed'], 'item_discount_notes' => [$this->outfit(3) => 'x']], 'reject', 'B3'],
            ['item discount array', ['item_discounts' => [$this->outfit(3) => [5]], 'item_discount_types' => [$this->outfit(3) => 'fixed'], 'item_discount_notes' => [$this->outfit(3) => 'x']], 'reject', 'B3'],
            ['item discount percent 101', ['item_discounts' => [$this->outfit(3) => '101'], 'item_discount_types' => [$this->outfit(3) => 'percent'], 'item_discount_notes' => [$this->outfit(3) => 'x']], 'any', null],
            ['item discount 50.000 without note', ['item_discounts' => [$this->outfit(3) => '50000'], 'item_discount_types' => [$this->outfit(3) => 'fixed']], 'reject', null],
        ];
        foreach ($cases as [$name, $over, $expect, $finding]) {
            $this->tryEdit("edit (paid in full): {$name}", $over, $expect, $finding, 'mgr1', function (int $id) use ($name) {
                $bill = $this->bill($id);
                $paid = $this->w->paidOf($id);
                $this->log[] = "   -> {$name}: bill now {$bill}, paid {$paid}";
                if ($bill < 0) {
                    $this->problem(null, "edit {$name}: NEGATIVE bill {$bill}", 'wrong-money');
                }
            }, 3, true);
        }
        $this->assertNoUnexplained();
    }

    public function test_edit_structure(): void
    {
        $sid = $this->outfit(3);
        $cases = [
            ['outfit_ids empty', ['outfit_ids' => []], 'reject', null], ['outfit_ids unknown', ['outfit_ids' => [999999]], 'reject', null], ['outfit_ids scalar', ['outfit_ids' => $sid], 'reject', null],
            ['outfit_ids nested', ['outfit_ids' => [[$sid]]], 'reject', 'B4'], ['outfit_ids another store', ['outfit_ids' => [$this->w->outfits[2][0]]], 'any', null],
            ['outfit_ids duplicate', ['outfit_ids' => [$sid, $sid]], 'any', null], ['name 256', ['customer_name' => str_repeat('n', 256)], 'reject', null], ['name emoji', ['customer_name' => "\u{1F454}"], 'accept', null],
            ['name array', ['customer_name' => ['a']], 'reject', null], ['notes 501', ['notes' => str_repeat('n', 501)], 'reject', null], ['phone 21', ['phone' => str_repeat('1', 21)], 'reject', null],
            ['payment_entry "weird"', ['payment_entry' => 'weird'], 'reject', null], ['payment_entry array', ['payment_entry' => ['increment']], 'reject', null],
            ['takeover_from text', ['takeover_from' => ['abc']], 'reject', null], ['takeover_from unknown id', ['takeover_from' => [999999]], 'reject', null], ['takeover_from scalar', ['takeover_from' => 5], 'reject', null],
            ['pay_action array', ['pay_action' => ['1'], 'payment_amount_add' => '1000'], 'any', null], ['pay_action "abc"', ['pay_action' => 'abc', 'payment_amount_add' => '1000'], 'any', null],
        ];
        foreach ($cases as [$name, $over, $expect, $finding]) {
            $this->tryEdit("edit structure: {$name}", $over, $expect, $finding);
        }
        $this->assertNoUnexplained();
    }
}
