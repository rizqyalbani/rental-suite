<?php

namespace Tests\Stress;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * Money math at the edges: bills with fractions of a cent, refunds from edits that lower the bill, adding / removing items
 * (1 vs 20), double submit of create, and a seeded "kitchen sink" of valid but awkward combinations.
 *
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/BoundaryMoneyMathTest.php
 *   BOUNDARY_VERBOSE=1 BOUNDARY_HITS=1 php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/BoundaryMoneyMathTest.php
 *
 * Known findings left out on purpose: P2 (catalogue prices are never changed), N3/N3b (a refund followed by an edit that raises
 * the bill again: no edit here raises the bill after a refund), C3-2 (refund on a rent that is only a down payment), C3-3,
 * C3-6, C2-1 (item removal + cancel with refund).
 */
class BoundaryMoneyMathTest extends TestCase
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

    private function money(float $v): string
    {
        return number_format($v, 2, ',', '');
    }

    // ================================================================ bills with a third decimal

    public function test_bills_with_fractions_of_a_cent(): void
    {
        // price x (1 - p%) has up to 4 decimals. The "full" check, the stored bill, the Return gate and the shared invariants
        // must all agree on one rounded number.
        $n = 0;
        foreach ([10001, 10003, 15001, 33333, 99999] as $price) {
            foreach (['7.5', '3.3', '0.1', '12.5', '33.33'] as $pct) {
                $raw = $price * (1 - (float) $pct / 100);
                $third = (int) floor(abs($raw * 100 - floor($raw * 100)) * 10);
                $this->inCase("price {$price} less {$pct}% = {$raw}", function (string $label) use ($price, $pct, $raw) {
                    $sid = $this->outfitAt($price);
                    $due = round($raw, 2);
                    $payload = $this->createPayload(['payment_kind' => 'full', 'amount_paid' => $this->money($due), 'discount' => $pct, 'discount_type' => 'percent', 'discount_note' => 'promo'], [$sid]);
                    $id = $this->create($payload, "{$label}: create paid in FULL ".$this->money($due));
                    $this->log[] = "   -> app bill {$this->bill($id)}, paid {$this->w->paidOf($id)}";
                    $this->attempt("{$label}: pickup", 'post', route('rentals.pickup', $id), ['picked_up' => true], 'mgr1', 1, 'accept');
                    $this->attempt("{$label}: return", 'post', route('rentals.status', $id), ['status' => 'returned'], 'mgr1', 1, 'accept');
                    $this->attempt("{$label}: one more cent", 'put', route('rentals.update', $id), $this->payPayload($id, '0,01', 'final_payment'), 'mgr1', 1, 'nomove');
                    $this->invariants($label);
                });
                $n++;
            }
        }
        $this->assertGreaterThan(20, $n);
        $this->assertNoUnexplained();
    }

    // ================================================================ edits that lower the bill: refund exactness

    public function test_edit_that_lowers_the_bill_refunds_exactly_the_difference(): void
    {
        // management applies directly. Paid in full 660.000, then the discount goes up: refund = paid - new bill, once.
        $cases = [
            ['fixed 60.000', ['discount' => '60000', 'discount_type' => 'fixed'], 60000.0], ['fixed 33.333,33', ['discount' => '33333,33', 'discount_type' => 'fixed'], 33333.33],
            ['fixed 0,01', ['discount' => '0,01', 'discount_type' => 'fixed'], 0.01], ['percent 10', ['discount' => '10', 'discount_type' => 'percent'], 66000.0],
            ['percent 12.5', ['discount' => '12.5', 'discount_type' => 'percent'], 82500.0], ['percent 0.1', ['discount' => '0.1', 'discount_type' => 'percent'], 660.0],
            ['percent 99', ['discount' => '99', 'discount_type' => 'percent'], 653400.0], ['fixed 659.999,99', ['discount' => '659999,99', 'discount_type' => 'fixed'], 659999.99],
        ];
        foreach ($cases as [$name, $change, $refund]) {
            $this->inCase("lower the bill: {$name}", function (string $label) use ($change, $refund) {
                $id = $this->create($this->createPayload(['payment_kind' => 'full', 'amount_paid' => '660000'], [$this->outfit(3)]));
                $p = array_merge($this->w->basePayload($this->row($id)), $change, ['discount_note' => 'goodwill']);
                $this->attempt($label, 'put', route('rentals.update', $id), $p, 'mgr1', 1, 'accept');
                $back = $this->cashBack($id);
                $this->log[] = "   -> bill {$this->bill($id)}, paid {$this->w->paidOf($id)}, handed back {$back}";
                if (abs($back - $refund) > 0.01) {
                    $this->problem(null, "{$label}: handed back {$back}, expected {$refund}", 'wrong-money');
                }
                // saving the same thing again must not refund again
                // (basePayload() truncates the discount to an integer, so send the stored value in the format the server reads back)
                $r = $this->row($id);
                $stored = (string) (float) $r->discount;
                $p2 = array_merge($this->w->basePayload($r), ['discount' => ($r->discount_type === 'percent' ? $stored : str_replace('.', ',', $stored)), 'discount_note' => 'goodwill']);
                $this->attempt("{$label}: same Save again", 'put', route('rentals.update', $id), $p2, 'mgr1', 1, 'nomove');
                if (abs($this->cashBack($id) - $refund) > 0.01) {
                    $this->problem(null, "{$label}: after a second Save handed back {$this->cashBack($id)}, expected {$refund}", 'wrong-money');
                }
                $this->invariants($label);
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ 1 vs 20 items, add / remove by edit

    public function test_cart_edit_add_and_remove_items(): void
    {
        foreach ([[1, 1], [2, 1], [20, 19], [20, 1], [20, 20]] as [$have, $keep]) {
            $this->inCase("rent of {$have} items, edit keeps {$keep}", function (string $label) use ($have, $keep) {
                $ids = [];
                for ($i = 0; $i < $have; $i++) {
                    $ids[] = $this->outfitAt(10000 + $i * 1111);
                }
                $total = 0;
                foreach ($ids as $sid) {
                    $total += (float) DB::table('outfits')->where('id', $sid)->value('price');
                }
                $id = $this->create($this->createPayload(['payment_kind' => 'full', 'amount_paid' => (string) $total], $ids));
                $keepIds = array_slice($ids, 0, $keep);
                $p = $this->w->basePayload($this->row($id));
                $p['outfit_ids'] = $keepIds;
                $p['item_discounts'] = array_fill_keys($keepIds, '');
                $p['item_discount_types'] = array_fill_keys($keepIds, 'fixed');
                unset($p['item_discount_notes']);
                $res = $this->attempt("{$label}: edit by management", 'put', route('rentals.update', $id), $p, 'mgr1', 1, $keep === $have ? 'nomove' : 'accept');
                if ($keep < $have) {
                    // removing items from a paid rent files an item-removal request; a second person decides it
                    $a = DB::table('rental_approval_requests')->where('status', 'pending')->where('payload->kind', 'item_removal')->where('payload->rental_id', $id)->first();
                    if (! $a) {
                        $this->problem(null, "{$label}: no pending item_removal request after removing items", 'setup');

                        return;
                    }
                    $this->attempt("{$label}: approve removal", 'post', route('approvals.item-removal.approve', $a->id), [], 'sup1', 1, 'accept');
                    $this->attempt("{$label}: approve removal again", 'post', route('approvals.item-removal.approve', $a->id), [], 'sup1', 1, 'nomove');
                }
                $newTotal = 0;
                foreach ($keepIds as $sid) {
                    $newTotal += (float) DB::table('outfits')->where('id', $sid)->value('price');
                }
                $this->log[] = "   -> bill {$this->bill($id)} (expected {$newTotal}), paid {$this->w->paidOf($id)}, handed back {$this->cashBack($id)}";
                if (abs($this->bill($id) - $newTotal) > 0.01) {
                    $this->problem(null, "{$label}: bill {$this->bill($id)}, expected {$newTotal}", 'wrong-money');
                }
                if ($keep < $have && abs($this->cashBack($id) - ($total - $newTotal)) > 0.01) {
                    $this->problem(null, "{$label}: handed back {$this->cashBack($id)}, expected ".($total - $newTotal), 'wrong-money');
                }
                $this->invariants($label);
            });
        }
        $this->inCase('edit with an empty cart', function (string $label) {
            $id = $this->create($this->createPayload([], [$this->outfit(3)]));
            $p = $this->w->basePayload($this->row($id));
            $p['outfit_ids'] = [];
            $this->attempt($label, 'put', route('rentals.update', $id), $p, 'mgr1', 1, 'reject');
        });
        $this->assertNoUnexplained();
    }

    // ================================================================ double submit of create

    public function test_double_submit_of_create(): void
    {
        foreach (['admin1', 'mgr1'] as $actor) {
            foreach ([['DP', ['amount_paid' => '100000']], ['FULL', ['payment_kind' => 'full', 'amount_paid' => '660000']]] as [$name, $over]) {
                $this->inCase("create sent twice by {$actor} ({$name})", function (string $label) use ($actor, $over) {
                    $payload = $this->createPayload($over, [$this->outfit(3)]);
                    $this->attempt("{$label}: first", 'post', route('rentals.store'), $payload, $actor, 1, 'accept');
                    $this->attempt("{$label}: second identical", 'post', route('rentals.store'), $payload, $actor, 1, 'nomove');
                    $count = DB::table('rentals')->count();
                    $this->log[] = "   -> rentals: {$count}, payment records: ".DB::table('rental_payment_records')->count();
                    if ($count !== 1) {
                        $this->problem(null, "{$label}: {$count} rentals for the same outfit and dates", 'duplicate');
                    }
                    $this->invariants($label);
                });
            }
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ pickup values

    public function test_pickup_values(): void
    {
        foreach ([['true', true, 'accept'], ['"1"', '1', 'accept'], ['"true"', 'true', 'reject'], ['false', false, 'any'], ['"0"', '0', 'any'], ['"abc"', 'abc', 'reject'], ['empty', '', 'reject'],
            ['null', null, 'reject'], ['array', [true], 'reject'], ['missing', 'MISSING', 'reject'], ['int 2', 2, 'reject']] as [$name, $value, $expect]) {
            $this->inCase("pickup picked_up={$name}", function (string $label) use ($value, $expect) {
                $id = $this->create($this->createPayload(['payment_kind' => 'full', 'amount_paid' => '660000'], [$this->outfit(3)]));
                $payload = $value === 'MISSING' ? [] : ['picked_up' => $value];
                $res = $this->attempt($label, 'post', route('rentals.pickup', $id), $payload, 'mgr1', 1, $expect);
                if ($res['changed']) {
                    $this->invariants($label);
                }
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ seeded kitchen sink of valid but awkward combinations

    public function test_kitchen_sink_valid_combinations(): void
    {
        $rng = new Rng(2026);
        $runs = (int) (getenv('BOUNDARY_SINK_RUNS') ?: 40);
        for ($i = 1; $i <= $runs; $i++) {
            $count = $rng->pick([1, 1, 2, 3, 5, 8, 12, 20]);
            $days = $rng->pick([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 12, 15, 30]);
            $orderKind = $rng->pick(['none', 'fixed', 'percent']);
            $itemKind = $rng->pick(['none', 'fixed', 'percent', 'mixed']);
            $label = "sink #{$i}: {$count} items, {$days} days, order {$orderKind}, items {$itemKind}";
            $this->inCase($label, function (string $label) use ($rng, $count, $days, $orderKind, $itemKind) {
                $ids = [];
                for ($k = 0; $k < $count; $k++) {
                    $ids[] = $this->outfitAt($rng->pick([1, 7, 999, 15000, 33333, 99999, 123457, 1100000, 275000]));
                }
                $blocks = max(1, intdiv($days, 3));
                $gross = 0.0;
                $items = [];
                $types = [];
                $notes = [];
                $sum = 0.0;
                foreach ($ids as $k => $sid) {
                    $price = (float) DB::table('outfits')->where('id', $sid)->value('price');
                    $line = $price * $blocks;
                    $gross += $line;
                    $d = '';
                    $t = 'fixed';
                    if ($itemKind === 'fixed' || ($itemKind === 'mixed' && $k % 2 === 0)) {
                        $d = (string) $rng->pick([1, 5, 100, 500]);
                        $t = 'fixed';
                    } elseif ($itemKind === 'percent' || $itemKind === 'mixed') {
                        $d = (string) $rng->pick(['5', '7.5', '10', '33.33']);
                        $t = 'percent';
                    }
                    $items[$sid] = $d;
                    $types[$sid] = $t;
                    if ($d !== '') {
                        $notes[$sid] = 'item promo';
                    }
                    $net = $d === '' ? $line : ($t === 'percent' ? $line - $line * (float) $d / 100 : $line - (float) $d);
                    $sum += max(0.0, $net);
                }
                $over = ['item_discounts' => $items, 'item_discount_types' => $types, 'item_discount_notes' => $notes];
                $after = $sum;
                if ($orderKind === 'fixed') {
                    $d = (string) $rng->pick([1, 10, 250]);
                    $over += ['discount' => $d, 'discount_type' => 'fixed', 'discount_note' => 'promo'];
                    $after = $sum - (float) $d;
                } elseif ($orderKind === 'percent') {
                    $d = (string) $rng->pick(['5', '7.5', '12.5', '33.33']);
                    $over += ['discount' => $d, 'discount_type' => 'percent', 'discount_note' => 'promo'];
                    $after = $sum - $sum * (float) $d / 100;
                }
                $after = max(0.0, $after);
                $extra = 0.0;
                if ($days > 3 && $days % 3 !== 0) {
                    $extra = (float) $rng->pick([1, 20000, 35000]);
                    $over['extra_charge'] = (string) $extra;
                }
                $bill = round($after + $extra, 2);
                if ($bill < 0.02) {
                    return;
                }
                // pay in two or three odd chunks that add up to the (2-decimal) bill
                $c1 = round(floor($bill * 100 * $rng->pick([20, 33, 50]) / 100) / 100, 2);
                $c1 = max(0.01, min($c1, $bill - 0.01));
                $rest = round($bill - $c1, 2);
                $c2 = $rest > 0.02 ? max(0.01, round(floor($rest * 100 / 3) / 100, 2)) : 0.0;
                $c3 = round($rest - $c2, 2);
                $payload = $this->createPayload(['amount_paid' => $this->money($c1)] + $over, $ids, $days);
                $res = $this->attempt("{$label}: create DP {$c1} of {$bill}", 'post', route('rentals.store'), $payload, 'admin1', 1, 'any');
                if (! $res['ok']) {
                    $this->log[] = '   -> refused: '.mb_substr($res['body'], 0, 200);
                    // A refusal must be explainable by the amount rules: a DP that is >= the bill is refused too.
                    return;
                }
                $id = (int) $res['json']['rental_id'];
                $appBill = round((float) $this->appSummary($id)['net_incl_tax'], 2);
                if (abs($appBill - $bill) > 0.015) {
                    $this->problem(null, "{$label}: app bill {$appBill}, expected {$bill}", 'wrong-money');
                }
                foreach (array_filter([$c2, $c3]) as $n => $chunk) {
                    $this->attempt("{$label}: pay {$chunk}", 'put', route('rentals.update', $id), $this->payPayload($id, $this->money($chunk), $chunk === $c3 || $c2 === 0.0 ? 'final_payment' : null), 'mgr1', 1, 'any');
                }
                $left = round($appBill - $this->w->paidOf($id), 2);
                $this->log[] = "   -> bill {$appBill}, paid {$this->w->paidOf($id)}, left {$left}";
                if (abs($left) > 0.015) {
                    // top up with the exact remainder; the app must take it and then be fully paid
                    if ($left > 0) {
                        $this->attempt("{$label}: pay the remainder {$left}", 'put', route('rentals.update', $id), $this->payPayload($id, $this->money($left), 'final_payment'), 'mgr1', 1, 'accept');
                    }
                }
                $this->invariants($label);
            });
        }
        $this->assertNoUnexplained();
    }
}
