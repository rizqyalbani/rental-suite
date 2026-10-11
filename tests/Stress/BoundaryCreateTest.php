<?php

namespace Tests\Stress;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * Boundary / hostile input at rent CREATE (POST rentals.store), table-driven.
 *
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/BoundaryCreateTest.php
 *   BOUNDARY_VERBOSE=1 BOUNDARY_HITS=1 php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/BoundaryCreateTest.php
 *
 * Rule for every case: either refused with a validation error and NO state change, or accepted and the money equals an
 * independent expectation (and the shared invariants hold). Never a 500. See BoundaryHarness for the verdicts.
 * Findings of earlier reports are excluded on purpose (comments name them).
 */
class BoundaryCreateTest extends TestCase
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

    /**
     * Creates a rent with $over merged into the default payload and judges the answer.
     *
     * @param  'reject'|'accept'|'any'  $expect
     */
    private function tryCreate(string $label, array $over, string $expect, ?float $expectPaid = null, ?string $finding = null, array $outfits = [], int $days = 3, ?callable $after = null): void
    {
        $this->inCase($label, function () use ($label, $over, $expect, $expectPaid, $finding, $outfits, $days, $after) {
            $res = $this->attempt($label, 'post', route('rentals.store'), $this->createPayload($over, $outfits, $days), 'admin1', 1, $expect, $finding);
            if (! $res['ok'] || ! $res['changed']) {
                return;
            }
            $id = (int) ($res['json']['rental_id'] ?? DB::table('rentals')->max('id'));
            if ($expectPaid !== null) {
                $paid = $this->w->paidOf($id);
                if (abs($paid - $expectPaid) > 0.005) {
                    $this->problem($finding, "{$label}: recorded payment {$paid}, expected {$expectPaid}", 'wrong-money');
                }
            }
            if ($after) {
                $after($id);
            }
            $this->invariants($label, $finding);
        });
    }

    // ================================================================ amount_paid (down payment on a Rp 660.000 bill)

    /** @return list<array{0: string, 1: mixed, 2: string, 3: ?float}> label, raw input, expectation, expected recorded amount */
    private static function amountTable(): array
    {
        return [
            // zero / negative
            ['int 0', 0, 'reject', null], ['"0"', '0', 'reject', null], ['"0,00"', '0,00', 'reject', null], ['"0.00"', '0.00', 'reject', null],
            ['"-0"', '-0', 'reject', null], ['int -1', -1, 'reject', null], ['"-1"', '-1', 'reject', null], ['"-100000"', '-100000', 'reject', null],
            ['"-660000"', '-660000', 'reject', null], ['"0,009" (below 1 cent)', '0,009', 'reject', null],
            // empty / non-numeric
            ['empty string', '', 'reject', null], ['spaces only', '   ', 'reject', null], ['null', null, 'reject', null], ['"abc"', 'abc', 'reject', null],
            ['"Rp 150.000"', 'Rp 150.000', 'reject', null], ['"Rp150000"', 'Rp150000', 'reject', null], ['"150 000"', '150 000', 'reject', null],
            ['"150000abc"', '150000abc', 'reject', null], ['"1e"', '1e', 'reject', null], ['"NaN"', 'NaN', 'reject', null], ['"INF"', 'INF', 'reject', null],
            ['"-INF"', '-INF', 'reject', null], ['hex "0x1F"', '0x1F', 'reject', null], ['fullwidth digits', '１５００００', 'reject', null],
            ['arabic-indic digits', '١٥٠٠٠٠', 'reject', null], ['"--5"', '--5', 'reject', null], ['"150000-"', '150000-', 'reject', null],
            ['"1_000"', '1_000', 'reject', null], ['"1,2,3"', '1,2,3', 'reject', null],
            ['bool false', false, 'reject', null],
            // arrays / objects instead of a scalar
            ['array []', [], 'reject', null], ['array [1]', [1], 'reject', null], ['array [150000]', [150000], 'reject', null], ['object {"a":1}', ['a' => 1], 'reject', null],
            ['nested array', [[1]], 'reject', null],
            // valid, different spellings of the same number
            ['int 1 (smallest rupiah)', 1, 'accept', 1.0], ['"0,01" (1 cent, min boundary)', '0,01', 'accept', 0.01], ['"150000"', '150000', 'accept', 150000.0],
            ['int 150000', 150000, 'accept', 150000.0], ['"150.000"', '150.000', 'accept', 150000.0], ['" 150.000 " (spaces)', ' 150.000 ', 'accept', 150000.0],
            ["newline after number", "150000\n", 'accept', 150000.0], ['"150000,50"', '150000,50', 'accept', 150000.5], ['"150.000,50"', '150.000,50', 'accept', 150000.5],
            ['"0,5"', '0,5', 'accept', 0.5], ['"99.999"', '99.999', 'accept', 99999.0], ['trailing NUL byte (trim strips it)', "150000\0", 'accept', 150000.0],
            // ambiguous spellings: the app strips every "." and turns "," into the decimal point (MoneyInput); record what it does
            ['"150,000" (English thousands)', '150,000', 'any', null], ['"150000.50" (English decimals)', '150000.50', 'any', null],
            ['bool true (JSON, becomes "1")', true, 'any', null], ['"1e5" (scientific)', '1e5', 'any', null], ['"1.5e5" (scientific)', '1.5e5', 'any', null], ['"+150000"', '+150000', 'any', null],
            ['float 150000.5 (JSON number)', 150000.5, 'any', null], ['"0.5" (dot as decimal)', '0.5', 'any', null], ['"1.2.3"', '1.2.3', 'any', null],
            // exactly the bill / above the bill / absurd
            ['"660000" = the whole bill as a down payment', '660000', 'any', null], // C3-3 (DP covering the whole bill) is a known finding
            ['"660000,01" (1 cent above the bill)', '660000,01', 'reject', null], ['"660001"', '660001', 'reject', null], ['"1.000.000"', '1.000.000', 'reject', null],
            ['"999999999,99" (validator max)', '999999999,99', 'reject', null], ['"1000000000"', '1000000000', 'reject', null],
            ['int 9999999999999', 9999999999999, 'reject', null], ['int PHP_INT_MAX', PHP_INT_MAX, 'reject', null], ['"9223372036854775807"', '9223372036854775807', 'reject', null],
            ['float 1e15', 1e15, 'reject', null], ['"1e15"', '1e15', 'reject', null], ['"1E+15"', '1E+15', 'reject', null], ['float 1.0E+25', 1.0e25, 'reject', null],
            ['400-digit number', str_repeat('9', 400), 'reject', null],
        ];
    }

    public function test_down_payment_amount_table(): void
    {
        foreach (self::amountTable() as [$name, $raw, $expect, $expectPaid]) {
            $known = $name === '"660000" = the whole bill as a down payment' ? 'KNOWN-C3-3' : null;
            $this->tryCreate("DP amount_paid = {$name}", ['amount_paid' => $raw], $expect, $expectPaid, $known);
        }
        $this->assertNoUnexplained();
    }

    public function test_full_payment_amount_table(): void
    {
        // payment_kind=full must cover the whole bill (660.000) and not exceed it.
        $cases = [
            ['exact "660000"', '660000', 'accept', 660000.0], ['exact "660.000"', '660.000', 'accept', 660000.0], ['exact "660000,00"', '660000,00', 'accept', 660000.0],
            ['1 below', '659999', 'reject', null], ['1 cent below "659999,99"', '659999,99', 'reject', null], ['1 above', '660001', 'reject', null],
            ['"660000,01"', '660000,01', 'reject', null], ['0', '0', 'reject', null], ['-660000', '-660000', 'reject', null], ['array', [660000], 'reject', null],
            ['"Rp 660.000"', 'Rp 660.000', 'reject', null], ['PHP_INT_MAX', PHP_INT_MAX, 'reject', null], ['"1e15"', '1e15', 'reject', null],
        ];
        foreach ($cases as [$name, $raw, $expect, $paid]) {
            $this->tryCreate("FULL amount_paid = {$name}", ['payment_kind' => 'full', 'amount_paid' => $raw], $expect, $paid);
        }
        $this->assertNoUnexplained();
    }

    public function test_payment_kind_and_method_values(): void
    {
        foreach ([['kind full', 'payment_kind', 'full', 'accept'], ['kind final_payment on a new rent', 'payment_kind', 'final_payment', 'any'],
            ['kind "DP"', 'payment_kind', 'DP', 'reject'], ['kind empty', 'payment_kind', '', 'reject'], ['kind null', 'payment_kind', null, 'reject'],
            ['kind array', 'payment_kind', ['full'], 'reject'], ['kind overdue (internal value)', 'payment_kind', 'overdue', 'reject'],
            ['method 100 chars', 'payment_method', str_repeat('a', 100), 'accept'], ['method 101 chars', 'payment_method', str_repeat('a', 101), 'reject'],
            ['method array', 'payment_method', ['cash'], 'reject'], ['method empty', 'payment_method', '', 'reject'], ['method emoji', 'payment_method', "qris \u{1F4B8}", 'accept'],
        ] as [$name, $field, $value, $expect]) {
            // 'full' needs the whole bill, so send it with that kind.
            $over = [$field => $value];
            if ($field === 'payment_kind' && in_array($value, ['full', 'final_payment'], true)) {
                $over['amount_paid'] = '660000';
            }
            $this->tryCreate("payment field {$name}", $over, $expect);
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ extra_charge (needed for 4 days = 3 + 1 extra)

    public function test_extra_charge_table(): void
    {
        $cases = [
            ['missing (required for 4 days)', null, 'reject', null], ['0', '0', 'reject', null], ['"0,00"', '0,00', 'reject', null], ['-1', '-1', 'reject', null],
            ['-20000', '-20000', 'reject', null], ['"abc"', 'abc', 'reject', null], ['"Rp 20.000"', 'Rp 20.000', 'reject', null], ['array', [20000], 'reject', null],
            ['"20000"', '20000', 'accept', 20000.0], ['"20.000"', '20.000', 'accept', 20000.0], ['"1"', '1', 'accept', 1.0],
            ['"0,01"', '0,01', 'accept', 0.01], ['"999999999,99" (validator max)', '999999999,99', 'accept', 999999999.99], ['"1000000000"', '1000000000', 'reject', null],
            ['PHP_INT_MAX', PHP_INT_MAX, 'reject', null], ['"1e15"', '1e15', 'reject', null], ['bool true (JSON, becomes "1")', true, 'any', null],
            ['"20000.50" (dot as decimal)', '20000.50', 'any', null], ['"1.5e4" (scientific)', '1.5e4', 'any', null], ['"20,000" (English thousands)', '20,000', 'any', null],
        ];
        foreach ($cases as [$name, $raw, $expect, $extra]) {
            $over = ['amount_paid' => '100000'];
            if ($raw !== null) {
                $over['extra_charge'] = $raw;
            }
            $this->tryCreate("4-day rent, extra_charge = {$name}", $over, $expect, 100000.0, null, [], 4, function (int $id) use ($extra) {
                $this->log[] = '   -> stored extra_charge '.(float) DB::table('rentals')->where('id', $id)->value('extra_charge').', bill '.$this->bill($id);
                if ($extra !== null) {
                    $stored = (float) DB::table('rentals')->where('id', $id)->value('extra_charge');
                    if (abs($stored - $extra) > 0.005) {
                        $this->problem(null, "stored extra_charge {$stored}, expected {$extra}", 'wrong-money');
                    }
                    $bill = $this->bill($id);
                    if (abs($bill - (660000 + $extra)) > 0.01) {
                        $this->problem(null, "bill {$bill}, expected ".(660000 + $extra), 'wrong-money');
                    }
                }
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ dates and the 3-day blocks

    public function test_day_count_and_block_boundaries(): void
    {
        // Bill = price x blocks; a rent that is not a multiple of 3 days (and longer than 3) needs an extra charge.
        // 1 and 2 days are billed as one block with no extra charge. Outfit 3 = Rp 660.000.
        foreach ([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12, 14, 30, 31, 90, 365] as $days) {
            $blocks = max(1, intdiv($days, 3));
            $needsExtra = $days > 3 && $days % 3 !== 0;
            $bill = 660000 * $blocks;
            $this->tryCreate("{$days} days, no extra charge", ['amount_paid' => '100000'], $needsExtra ? 'reject' : 'accept', 100000.0, null, [], $days, function (int $id) use ($bill, $days) {
                if (abs($this->bill($id) - $bill) > 0.01) {
                    $this->problem(null, "{$days} days: bill {$this->bill($id)}, expected {$bill}", 'wrong-money');
                }
            });
            if ($needsExtra) {
                $this->tryCreate("{$days} days, extra charge 50.000", ['amount_paid' => '100000', 'extra_charge' => '50000'], 'accept', 100000.0, null, [], $days, function (int $id) use ($bill, $days) {
                    if (abs($this->bill($id) - ($bill + 50000)) > 0.01) {
                        $this->problem(null, "{$days} days + extra: bill {$this->bill($id)}, expected ".($bill + 50000), 'wrong-money');
                    }
                });
            }
            // paid in full at creation = exactly the bill
            $over = ['payment_kind' => 'full', 'amount_paid' => (string) ($bill + ($needsExtra ? 50000 : 0))] + ($needsExtra ? ['extra_charge' => '50000'] : []);
            $this->tryCreate("{$days} days, paid in FULL", $over, 'accept', (float) ($bill + ($needsExtra ? 50000 : 0)), null, [], $days);
        }
        $this->assertNoUnexplained();
    }

    public function test_date_values(): void
    {
        $today = Carbon::now()->startOfDay();
        $d = fn (int $o) => $today->copy()->addDays($o)->toDateString();
        $cases = [
            ['inverted (end before start)', ['start_date' => $d(5), 'end_date' => $d(1)], 'reject'],
            ['same day', ['start_date' => $d(1), 'end_date' => $d(1)], 'accept'],
            ['starts in the past (-30d)', ['start_date' => $d(-30), 'end_date' => $d(-28)], 'accept'],
            ['starts a year ago', ['start_date' => $d(-365), 'end_date' => $d(-363)], 'accept'],
            ['starts in 2 years', ['start_date' => $d(730), 'end_date' => $d(732)], 'accept'],
            ['2026-02-30 (no such day)', ['start_date' => '2026-02-30', 'end_date' => '2026-03-04'], 'reject'],
            ['2026-13-01 (no such month)', ['start_date' => '2026-13-01', 'end_date' => '2026-12-04'], 'reject'],
            ['2026-02-29 (not a leap year)', ['start_date' => '2026-02-29', 'end_date' => '2026-03-03'], 'reject'],
            ['2028-02-29 (leap day)', ['start_date' => '2028-02-29', 'end_date' => '2028-03-02'], 'accept'],
            ['text "tomorrow"', ['start_date' => 'tomorrow', 'end_date' => 'tomorrow'], 'any'],
            ['text "abc"', ['start_date' => 'abc', 'end_date' => 'abc'], 'reject'],
            ['empty', ['start_date' => '', 'end_date' => ''], 'reject'],
            ['null', ['start_date' => null, 'end_date' => null], 'reject'],
            ['array', ['start_date' => [$d(1)], 'end_date' => [$d(3)]], 'reject'],
            ['dd/mm/yyyy', ['start_date' => '15/10/2026', 'end_date' => '17/10/2026'], 'any'],
            ['with a time part', ['start_date' => $d(1).' 23:59:59', 'end_date' => $d(3).' 00:00:01'], 'any'],
            ['year 0000', ['start_date' => '0000-01-01', 'end_date' => '0000-01-03'], 'any'],
            ['year 9999', ['start_date' => '9999-12-29', 'end_date' => '9999-12-31'], 'any'],
            ['unix timestamp', ['start_date' => '1790000000', 'end_date' => '1790200000'], 'any'],
            ['int 20261015', ['start_date' => 20261015, 'end_date' => 20261017], 'any'],
        ];
        foreach ($cases as [$name, $over, $expect]) {
            $this->tryCreate("dates: {$name}", $over, $expect, $expect === 'accept' ? 100000.0 : null, $name === 'int 20261015' ? 'B11' : null, [], 3, function (int $id) use ($name, $over) {
                $r = $this->row($id);
                $this->log[] = "   -> {$name}: sent ".json_encode([$over['start_date'] ?? null, $over['end_date'] ?? null]).' stored '.substr((string) $r->start_date, 0, 19).' .. '.substr((string) $r->end_date, 0, 19).', bill '.$this->bill($id);
            });
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ discounts

    public function test_order_discount_table(): void
    {
        $note = ['discount_note' => 'promo'];
        // [label, discount, type, expectation, expected bill]  (bill without discount: 660.000; DP 100.000)
        $cases = [
            ['fixed 0', '0', 'fixed', 'accept', 660000.0], ['fixed -1', '-1', 'fixed', 'reject', null], ['fixed "abc"', 'abc', 'fixed', 'reject', null],
            ['fixed array', [1000], 'fixed', 'reject', null], ['fixed 60.000', '60.000', 'fixed', 'accept', 600000.0], ['fixed "60000,50"', '60000,50', 'fixed', 'accept', 599999.5],
            ['fixed 659.999', '659.999', 'fixed', 'accept', 1.0], ['fixed = price (660.000)', '660.000', 'fixed', 'any', null], ['fixed > price (1.000.000)', '1.000.000', 'fixed', 'any', null],
            ['fixed 999999999,99', '999999999,99', 'fixed', 'any', null], ['fixed 1e15', '1e15', 'fixed', 'reject', null], ['fixed PHP_INT_MAX', PHP_INT_MAX, 'fixed', 'reject', null],
            ['fixed "60000.50" (dot as decimal)', '60000.50', 'fixed', 'any', null], ['fixed "6e4"', '6e4', 'fixed', 'any', null],
            ['percent 0', '0', 'percent', 'accept', 660000.0], ['percent 10', '10', 'percent', 'accept', 594000.0], ['percent 50', '50', 'percent', 'accept', 330000.0],
            ['percent 99', '99', 'percent', 'accept', 6600.0], ['percent 100', '100', 'percent', 'any', null], ['percent 101', '101', 'percent', 'any', null],
            ['percent -5', '-5', 'percent', 'reject', null], ['percent 1000', '1000', 'percent', 'any', null], ['percent 12.5', '12.5', 'percent', 'accept', 577500.0],
            ['percent "12,5" (comma decimal)', '12,5', 'percent', 'any', null], ['percent 33.33', '33.33', 'percent', 'accept', 440022.0], ['percent "abc"', 'abc', 'percent', 'reject', null],
            ['percent array', [10], 'percent', 'reject', null], ['percent 1e15', '1e15', 'percent', 'any', null],
            ['type "weird"', '10', 'weird', 'reject', null], ['type array', '10', ['percent'], 'reject', null], ['type empty (= fixed)', '10000', '', 'accept', 650000.0],
        ];
        // NEW findings (see the report): B1 = empty discount_type answers 500, B2 = array discount_type answers 500.
        // (A percent with 3 decimals looked like a finding - sqlite stores 33.333 raw while the model casts decimal:2 on read and
        // on set, so the app itself stays consistent at 33.33 - it was a test-database artefact and is not reported.)
        $findings = ['type array' => 'B2', 'type empty (= fixed)' => 'B1'];
        foreach ($cases as [$name, $disc, $type, $expect, $bill]) {
            $f = $findings[$name] ?? null;
            $this->tryCreate("order discount {$name}", ['discount' => $disc, 'discount_type' => $type, 'amount_paid' => '0,01'] + $note, $expect, 0.01, $f, [], 3, function (int $id) use ($bill, $name, $f) {
                $stored = $this->bill($id);
                if ($bill !== null && abs($stored - $bill) > 0.01) {
                    $this->problem($f, "order discount {$name}: bill {$stored}, expected {$bill}", 'wrong-money');
                }
                if ($stored < 0) {
                    $this->problem($f, "order discount {$name}: NEGATIVE bill {$stored}", 'wrong-money');
                }
            });
        }
        // The note is mandatory for any discount > 0
        $this->tryCreate('order discount 10.000 without a note', ['discount' => '10000', 'discount_type' => 'fixed', 'amount_paid' => '0,01'], 'reject');
        $this->tryCreate('order discount 10.000 with a blank note', ['discount' => '10000', 'discount_type' => 'fixed', 'discount_note' => '   ', 'amount_paid' => '0,01'], 'reject');
        $this->tryCreate('order discount note 501 chars', ['discount' => '10000', 'discount_note' => str_repeat('n', 501), 'amount_paid' => '0,01'], 'reject');
        $this->tryCreate('order discount note array', ['discount' => '10000', 'discount_note' => ['x'], 'amount_paid' => '0,01'], 'reject');
        $this->assertNoUnexplained();
    }

    public function test_item_discount_table(): void
    {
        // item_discounts is NOT in the validator of store(): whatever the browser sends goes to the pivot row.
        $sid = $this->outfit(3);
        $cases = [
            ['fixed 0', '0', 'fixed', 'accept', 660000.0], ['fixed 100.000', '100.000', 'fixed', 'accept', 560000.0], ['fixed "100000,50"', '100000,50', 'fixed', 'accept', 559999.5],
            ['fixed -50.000', '-50000', 'fixed', 'reject', null], ['fixed = price', '660000', 'fixed', 'any', null], ['fixed > price (1.000.000)', '1000000', 'fixed', 'any', null],
            ['fixed "abc"', 'abc', 'fixed', 'reject', null], ['fixed array', [5], 'fixed', 'reject', null], ['fixed 1e12', '1e12', 'fixed', 'reject', null],
            ['fixed 99999999999 (11 digits)', '99999999999', 'fixed', 'reject', null], ['fixed PHP_INT_MAX', (string) PHP_INT_MAX, 'fixed', 'reject', null],
            ['fixed "100000.50" (dot as decimal)', '100000.50', 'fixed', 'any', null],
            ['percent 0', '0', 'percent', 'accept', 660000.0], ['percent 10', '10', 'percent', 'accept', 594000.0], ['percent 100', '100', 'percent', 'any', null],
            ['percent 101', '101', 'percent', 'reject', null], ['percent -5', '-5', 'percent', 'reject', null], ['percent 1000000', '1000000', 'percent', 'reject', null],
            ['percent "abc"', 'abc', 'percent', 'reject', null],
            ['fixed 100000 + type "weird"', '100000', 'weird', 'reject', null], ['fixed 100000 + type 11 chars', '100000', 'abcdefghijk', 'reject', null],
            ['fixed 100000 + type array', '100000', ['fixed'], 'reject', null],
        ];
        // NEW finding B3: the item_discounts* fields are not validated (negative / junk stored, arrays answer 500).
        foreach ($cases as [$name, $disc, $type, $expect, $bill]) {
            $over = ['amount_paid' => '0,01', 'item_discounts' => [$sid => $disc], 'item_discount_types' => [$sid => $type], 'item_discount_notes' => [$sid => 'promo']];
            $this->tryCreate("item discount {$name}", $over, $expect, 0.01, 'B3', [], 3, function (int $id) use ($bill, $name) {
                $stored = $this->bill($id);
                if ($bill !== null && abs($stored - $bill) > 0.01) {
                    $this->problem(null, "item discount {$name}: bill {$stored}, expected {$bill}", 'wrong-money');
                }
            });
        }
        $this->tryCreate('item discount without a note', ['amount_paid' => '0,01', 'item_discounts' => [$sid => '50000']], 'reject');
        $this->tryCreate('item discount note is an array', ['amount_paid' => '0,01', 'item_discounts' => [$sid => '50000'], 'item_discount_notes' => [$sid => ['x']]], 'reject', null, 'B3');
        $this->tryCreate('item_discounts for an outfit that is not in the cart', ['amount_paid' => '0,01', 'item_discounts' => [$this->outfit(0) => '50000'], 'item_discount_notes' => [$this->outfit(0) => 'x']], 'any');
        $this->tryCreate('item_discounts is a string', ['amount_paid' => '0,01', 'item_discounts' => 'abc'], 'any');
        $this->tryCreate('item_discount_types is a string', ['amount_paid' => '0,01', 'item_discount_types' => 'percent'], 'any');
        $this->assertNoUnexplained();
    }

    // ================================================================ text fields

    public function test_text_fields(): void
    {
        $long10k = str_repeat('x', 10000);
        $cases = [
            ['name 1 char', ['customer_name' => 'A'], 'accept'], ['name 255 chars', ['customer_name' => str_repeat('n', 255)], 'accept'], ['name 256 chars', ['customer_name' => str_repeat('n', 256)], 'reject'],
            ['name 10k chars', ['customer_name' => $long10k], 'reject'], ['name empty', ['customer_name' => ''], 'reject'], ['name spaces only', ['customer_name' => '     '], 'reject'],
            ['name null', ['customer_name' => null], 'reject'], ['name array', ['customer_name' => ['a']], 'reject'], ['name int', ['customer_name' => 12345], 'any'],
            ['name emoji', ['customer_name' => "Budi \u{1F454}\u{1F935}"], 'accept'], ['name 255 emoji (4-byte)', ['customer_name' => str_repeat("\u{1F600}", 255)], 'accept'],
            ['name 256 emoji', ['customer_name' => str_repeat("\u{1F600}", 256)], 'reject'], ['name RTL (arabic)', ['customer_name' => 'عبد الله'], 'accept'],
            ['name html', ['customer_name' => '<script>alert(1)</script>'], 'accept'], ['name sql', ['customer_name' => "x'); DROP TABLE rentals;--"], 'accept'],
            ['name newline', ['customer_name' => "a\nb"], 'accept'], ['name NUL byte', ['customer_name' => "a\0b"], 'any'], ['name invalid utf-8', ['customer_name' => "a\xC3\x28b"], 'any'],
            ['name zero-width / combining', ['customer_name' => "Jos\u{0065}\u{0301}\u{200B}"], 'accept'],
            ['phone 20 chars', ['phone' => str_repeat('1', 20)], 'accept'], ['phone 21 chars', ['phone' => str_repeat('1', 21)], 'reject'], ['phone empty', ['phone' => ''], 'reject'],
            ['phone letters', ['phone' => 'call me'], 'any'], ['phone array', ['phone' => ['0812']], 'reject'], ['phone emoji x20', ['phone' => str_repeat("\u{1F4DE}", 20)], 'accept'],
            ['instagram 100', ['instagram' => str_repeat('i', 100)], 'accept'], ['instagram 101', ['instagram' => str_repeat('i', 101)], 'reject'], ['instagram array', ['instagram' => ['x']], 'reject'],
            ['notes 500', ['notes' => str_repeat('n', 500)], 'accept'], ['notes 501', ['notes' => str_repeat('n', 501)], 'reject'], ['notes 10k', ['notes' => $long10k], 'reject'],
            ['notes emoji 500', ['notes' => str_repeat("\u{1F600}", 500)], 'accept'], ['notes array', ['notes' => ['x']], 'reject'], ['notes null', ['notes' => null], 'accept'],
        ];
        foreach ($cases as [$name, $over, $expect]) {
            $this->tryCreate("text: {$name}", $over, $expect, $expect === 'accept' ? 100000.0 : null);
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ structure: arrays / wrong types for every field

    public function test_wrong_structure_never_500(): void
    {
        $sid = $this->outfit(3);
        $cases = [
            ['outfit_ids missing', ['outfit_ids' => null], 'reject'], ['outfit_ids empty', ['outfit_ids' => []], 'reject'], ['outfit_ids scalar', ['outfit_ids' => $sid], 'reject'],
            ['outfit_ids string', ['outfit_ids' => (string) $sid], 'reject'], ['outfit_ids unknown id', ['outfit_ids' => [999999]], 'reject'], ['outfit_ids negative', ['outfit_ids' => [-1]], 'reject'],
            ['outfit_ids zero', ['outfit_ids' => [0]], 'reject'], ['outfit_ids text', ['outfit_ids' => ['abc']], 'reject'], ['outfit_ids nested', ['outfit_ids' => [[$sid]]], 'reject'],
            ['outfit_ids float', ['outfit_ids' => [$sid + 0.5]], 'any'], ['outfit_ids "id " with space', ['outfit_ids' => [$sid.' ']], 'any'], ['outfit_ids duplicated', ['outfit_ids' => [$sid, $sid]], 'accept'],
            ['outfit_ids string number "3abc"', ['outfit_ids' => [$sid.'abc']], 'any'], ['outfit_ids huge int', ['outfit_ids' => [PHP_INT_MAX]], 'reject'],
            ['outfit_ids map {a: id}', ['outfit_ids' => ['a' => $sid]], 'any'],
            ['discount_type array', ['discount_type' => ['percent'], 'discount' => '10', 'discount_note' => 'x'], 'reject'],
            ['amount_paid object', ['amount_paid' => ['a' => ['b' => 1]]], 'reject'],
            ['whole body is empty strings', ['customer_name' => '', 'phone' => '', 'payment_method' => '', 'payment_kind' => '', 'amount_paid' => '', 'start_date' => '', 'end_date' => ''], 'reject'],
            ['tutorial_practice array', ['tutorial_practice' => ['1']], 'any'], ['tutorial_practice "abc"', ['tutorial_practice' => 'abc'], 'any'],
            ['unknown extra fields (mass assignment)', ['id' => 1, 'status' => 'returned', 'is_tutorial' => true, 'store_id' => 2, 'created_by_user_id' => 1, 'deleted_at' => '2020-01-01', 'picked_up_at' => '2020-01-01'], 'any'],
        ];
        // NEW findings: B4 = nested outfit_ids accepted (intval([3]) = 1), B2 = array discount_type answers 500.
        $findings = ['outfit_ids nested' => 'B4', 'discount_type array' => 'B2'];
        foreach ($cases as [$name, $over, $expect]) {
            $this->tryCreate("structure: {$name}", $over, $expect, null, $findings[$name] ?? null, [], 3, $name === 'unknown extra fields (mass assignment)' ? function (int $id) {
                $r = DB::table('rentals')->where('id', $id)->first();
                if ($r->status !== 'active' || $r->deleted_at !== null || $r->picked_up_at !== null || (int) $r->store_id !== 1 || (int) ($r->is_tutorial ?? 0) !== 0 || (int) $r->created_by_user_id !== $this->w->users['admin1']->id || $id === 1 && false) {
                    $this->problem(null, 'mass assignment: '.json_encode($r), 'mass-assignment');
                }
            } : null);
        }
        $this->assertNoUnexplained();
    }

    // ================================================================ PPN rounding and big carts

    public function test_ppn_rounding_prices(): void
    {
        // Tax-inclusive prices, PPN 10%: pivot stores incl / excl (= incl / 1.1, 2 decimals) / tax. Bill must equal incl x blocks
        // whatever the rounding of the split, and excl + tax must add back to incl.
        foreach (['33.333', '99.999', '1100000', '15000', '1', '0.01', '0', '0.5', '1.1', '9999999.99', '10'] as $price) {
            foreach ([3, 9] as $days) {
                $this->inCase("PPN price {$price} x {$days}d", function (string $label) use ($price, $days) {
                    $sid = $this->outfitAt($price);
                    $payload = $this->createPayload(['amount_paid' => '0,01'], [$sid], $days);
                    $res = $this->attempt($label, 'post', route('rentals.store'), $payload, 'admin1', 1, 'any');
                    if (! $res['ok'] || ! $res['changed']) {
                        return;
                    }
                    $id = (int) $res['json']['rental_id'];
                    $pv = DB::table('rental_outfit')->where('rental_id', $id)->first();
                    $blocks = intdiv($days, 3);
                    $bill = $this->bill($id);
                    if (abs($bill - round((float) $price * $blocks, 2)) > 0.01) {
                        $this->problem(null, "{$label}: bill {$bill} != price x blocks ".round((float) $price * $blocks, 2), 'wrong-money');
                    }
                    $incl = (float) $pv->price_incl_tax;
                    $sum = round((float) $pv->price_excl_tax + (float) $pv->tax_amount, 2);
                    if (abs($incl - $sum) > 0.005) {
                        $this->problem('B-PPN', "{$label}: pivot excl {$pv->price_excl_tax} + tax {$pv->tax_amount} = {$sum} != incl {$incl}");
                    }
                    if (abs($incl - (float) $price) > 0.005) {
                        $this->problem('B-PPN', "{$label}: pivot incl {$incl} != catalogue price {$price} (rounded at the column)");
                    }
                    // the summary's own split of the bill: pivot DPP + PPN must add back to the gross, and the per-unit rounding
                    // of the pivot split may drift from the aggregate split (price / 1.1) by cents only
                    $sum2 = $this->appSummary($id);
                    $gap = abs($sum2['gross_excl_tax'] + $sum2['gross_tax_amount'] - $sum2['gross']);
                    $drift = abs($sum2['gross_excl_tax'] - $sum2['gross'] / 1.1);
                    $this->log[] = "   -> gross {$sum2['gross']} pivot dpp {$sum2['gross_excl_tax']} pivot ppn {$sum2['gross_tax_amount']} | aggregate dpp {$sum2['pre_tax_net']} ppn {$sum2['tax_amount']} | pivot-vs-aggregate drift ".round($drift, 4);
                    if ($gap > 0.01) {
                        $this->problem('B-PPN', "{$label}: pivot DPP+PPN differ from the gross by {$gap}");
                    }
                    if ($drift > 0.05) {
                        $this->problem('B-PPN', "{$label}: pivot DPP differs from gross/1.1 by {$drift}");
                    }
                    $this->invariants($label);
                });
            }
        }
        $this->assertNoUnexplained();
    }

    public function test_cart_sizes_and_rounding_sums(): void
    {
        // 1 vs 20 items; every item has a different "ugly" price so rounding differences can add up.
        foreach ([1, 2, 8, 20] as $count) {
            $this->inCase("cart of {$count} items", function (string $label) use ($count) {
                $ids = [];
                for ($i = 0; $i < $count; $i++) {
                    $ids[] = $this->outfitAt(33333 + $i * 777 + 0.33 * ($i % 3));
                }
                $due = 0.0;
                foreach ($ids as $id) {
                    $due += (float) DB::table('outfits')->where('id', $id)->value('price');
                }
                $due = round($due, 2);
                $payload = $this->createPayload(['payment_kind' => 'full', 'amount_paid' => number_format($due, 2, ',', '')], $ids);
                $res = $this->attempt($label.' paid in FULL ('.$due.')', 'post', route('rentals.store'), $payload, 'admin1', 1, 'accept');
                if (! $res['ok']) {
                    return;
                }
                $id = (int) $res['json']['rental_id'];
                if (abs($this->bill($id) - $due) > 0.01 || abs($this->w->paidOf($id) - $due) > 0.01) {
                    $this->problem(null, "{$label}: bill {$this->bill($id)} paid {$this->w->paidOf($id)} expected {$due}", 'wrong-money');
                }
                $this->invariants($label);
            });
        }
        $this->assertNoUnexplained();
    }

    public function test_huge_bills_and_ceilings(): void
    {
        // A bill far above the 999.999.999,99 field ceiling: nothing the app computes may overflow or go negative.
        $this->inCase('1 outfit, 3 years of days (1095)', function (string $label) {
            $payload = $this->createPayload(['amount_paid' => '100000', 'extra_charge' => '1'], [$this->outfit(6)], 1096);
            $res = $this->attempt($label, 'post', route('rentals.store'), $payload, 'admin1', 1, 'any');
            if ($res['ok'] && $res['changed']) {
                $this->invariants($label);
            }
        });
        $this->inCase('DP of exactly 100.000.000 on a 3.3 billion bill (down_payment_amount is decimal(10,2))', function (string $label) {
            $payload = $this->createPayload(['amount_paid' => '100.000.000', 'extra_charge' => '1'], [$this->outfit(6)], 3 * 1000 + 1);
            $res = $this->attempt($label, 'post', route('rentals.store'), $payload, 'admin1', 1, 'any', 'B-DECIMAL');
            if ($res['ok'] && $res['changed']) {
                $this->hits['B-DECIMAL'][] = "{$label}: accepted on sqlite; stored down_payment_amount = ".DB::table('rentals')->max('down_payment_amount').' (column is decimal(10,2): max 99.999.999,99)';
                $this->invariants($label);
            }
        });
        $this->inCase('DP at the validator maximum 999.999.999,99', function (string $label) {
            $payload = $this->createPayload(['amount_paid' => '999999999,99', 'extra_charge' => '1'], [$this->outfit(6)], 3 * 2000 + 1);
            $res = $this->attempt($label, 'post', route('rentals.store'), $payload, 'admin1', 1, 'any', 'B-DECIMAL');
            if ($res['ok'] && $res['changed']) {
                $this->hits['B-DECIMAL'][] = "{$label}: accepted on sqlite; stored down_payment_amount = ".DB::table('rentals')->max('down_payment_amount');
                $this->invariants($label);
            }
        });
        $this->assertNoUnexplained();
    }

    public function test_dp_dot_vs_comma_on_a_large_bill(): void
    {
        // On a bill big enough to swallow the 100x misreading ("150000.50" -> 15.000.050) the wrong amount is ACCEPTED.
        $outfits = [];
        foreach ($this->w->outfits[1] as $id) {
            $outfits[] = $id;
        }
        // 8 outfits x 12 days = 4 blocks = Rp 19.200.000
        foreach ([['"150000.50"', '150000.50', 15000050.0], ['"150000,50"', '150000,50', 150000.5], ['"1.5e5"', '1.5e5', 1500000.0], ['"150,000"', '150,000', 150.0]] as [$name, $raw, $expected]) {
            $this->tryCreate("19.2 million bill, DP {$name}", ['amount_paid' => $raw], 'any', null, $name === '"150000.50"' ? 'B-DOT' : null, $outfits, 12, function (int $id) use ($expected, $name) {
                $paid = $this->w->paidOf($id);
                $this->log[] = "   -> DP {$name} recorded as {$paid} (bill {$this->bill($id)})";
                if (abs($paid - $expected) > 0.01) {
                    $this->problem(null, "DP {$name}: recorded {$paid}, expected per MoneyInput rules {$expected}", 'wrong-money');
                }
            });
        }
        $this->assertNoUnexplained();
    }
}
