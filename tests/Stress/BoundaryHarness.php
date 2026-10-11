<?php

namespace Tests\Stress;

use App\Models\Outfit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Shared plumbing for the boundary / hostile-input tests (chunk C10, docs/overnight-2026-10-09/04b-stress-boundary.md).
 *
 * One "case" = one savepoint (rolled back afterwards, so every case starts from the same empty shop) with one or more
 * HTTP requests through the REAL routes. Each request is judged by `attempt()`:
 *
 *   - never a 5xx (an exception answers 500)                        -> problem "500"
 *   - expectation 'reject': 4xx AND no money / rent / approval row changed (snapshot md5 before = after)
 *   - expectation 'accept': 2xx AND something changed
 *   - expectation 'any'   : just recorded (ambiguous input, the report lists what the app did)
 *
 * After an accepted request `invariants()` runs the shared `Invariants` (payments sum, journal debit = credit, ledger sync
 * idempotent, bill math, shift totals, dashboard formulas, ...).
 *
 * Problems that match an already KNOWN finding (earlier overnight reports) are tallied as `known`, never failed.
 * Problems that are NEW findings are tallied under their B-id (see the report) and listed, never failed either: the
 * minimal named repros live in BoundaryFindingsReproTest. Anything else fails the test.
 */
trait BoundaryHarness
{
    private StressWorld $w;

    /** @var list<string> every request, for the report */
    private array $log = [];

    /** @var array<string, list<string>> finding id => example lines */
    private array $hits = [];

    /** @var list<string> unexplained problems (fail the test) */
    private array $unexplained = [];

    private int $requests = 0;

    private int $invariantRuns = 0;

    private function initWorld(int $seed = 11): void
    {
        $this->w = new StressWorld($this, new Rng($seed));
        $this->w->build();
    }

    // ------------------------------------------------------------------ cases and requests

    /** Runs $body inside a savepoint that is always rolled back. */
    private function inCase(string $label, callable $body): void
    {
        $level = DB::transactionLevel();
        DB::beginTransaction();
        try {
            $body($label);
        } catch (\Throwable $e) {
            $this->unexplained[] = "[EXC] {$label}: ".get_class($e).': '.mb_substr($e->getMessage(), 0, 220);
        } finally {
            while (DB::transactionLevel() > $level) {
                DB::rollBack();
            }
            \App\Support\StoreContext::set(null, false);
            Carbon::setTestNow($this->w->startedAt->copy());
        }
    }

    /**
     * One HTTP request with a verdict.
     *
     * @param  'reject'|'accept'|'any'|'nomove'  $expect  'nomove' = refused with no change, OR answered 2xx without moving any money (metadata may change)
     * @param  string|null  $finding  when set and the verdict is a problem, count it under this finding id instead of failing
     * @return array{status: int, json: ?array, body: string, changed: bool, ok: bool, verdict: string}
     */
    private function attempt(string $label, string $method, string $uri, array $payload, string $actor, int $store, string $expect, ?string $finding = null): array
    {
        $before = $this->w->snapshot();
        $moneyBefore = $expect === 'nomove' ? $this->w->moneySnapshot() : null;
        $res = $this->w->http($method, $uri, $payload, $actor, $store);
        $after = $this->w->snapshot();
        $this->requests++;
        $changed = $before !== $after;
        $status = $res['status'];
        $verdict = 'ok';
        $detail = null;

        if ($status >= 500) {
            $verdict = '500';
            $detail = "answered {$status}: ".mb_substr(preg_replace('/\s+/', ' ', $res['body']), 0, 200);
        } elseif ($expect === 'reject') {
            if ($status < 300) {
                $verdict = 'accepted-but-should-reject';
                $detail = "answered {$status} (".($changed ? 'state CHANGED: '.implode(',', $this->changedTables($before, $after)) : 'no state change').')';
            } elseif ($changed) {
                $verdict = 'refused-but-changed-state';
                $detail = "answered {$status} yet changed ".implode(',', $this->changedTables($before, $after));
            }
        } elseif ($expect === 'accept') {
            if ($status >= 300) {
                $verdict = 'refused-but-should-accept';
                $detail = "answered {$status}: ".mb_substr(preg_replace('/\s+/', ' ', $res['body']), 0, 200);
            } elseif (! $changed) {
                $verdict = 'accepted-but-nothing-changed';
                $detail = "answered {$status} but no table changed";
            }
        } elseif ($expect === 'nomove') {
            if ($status >= 400 && $changed) {
                $verdict = 'refused-but-changed-state';
                $detail = "answered {$status} yet changed ".implode(',', $this->changedTables($before, $after));
            } elseif ($status < 300 && $this->w->moneySnapshot() !== $moneyBefore) {
                $verdict = 'money-moved';
                $detail = "answered {$status} and money changed: ".implode(',', $this->changedTables($moneyBefore, $this->w->moneySnapshot()));
            }
        } elseif ($status >= 400 && $changed) {
            $verdict = 'refused-but-changed-state';
            $detail = "answered {$status} yet changed ".implode(',', $this->changedTables($before, $after));
        }

        if ($status < 500 && str_contains($res['body'], 'SQLSTATE')) {
            // B8: a 4xx answer that carries raw SQL / internal text (the update() catch-all returns $e->getMessage() with a 409).
            $this->problem('B8', "{$label}: answered {$status} with raw SQL text: ".mb_substr(preg_replace('/\s+/', ' ', $res['body']), 0, 160));
        }
        $this->log[] = sprintf('%-9s %-4s %s -> %d%s%s%s', $expect, strtoupper($method), $label, $status, $changed ? ' [changed]' : '', $verdict === 'ok' ? '' : "  !! {$verdict}",
            $status >= 400 && ($expect === 'any' || $verdict !== 'ok') ? '  | '.mb_substr(preg_replace('/\s+/', ' ', $res['body']), 0, 200) : '');
        if ($detail !== null) {
            $this->problem($finding, "{$label}: {$detail}", $verdict);
        }

        return $res + ['changed' => $changed, 'ok' => $status < 300, 'verdict' => $verdict];
    }

    /** @return list<string> */
    private function changedTables(array $before, array $after): array
    {
        return array_keys(array_filter($after, fn ($v, $k) => ($before[$k] ?? null) !== $v, ARRAY_FILTER_USE_BOTH));
    }

    private function problem(?string $finding, string $msg, string $kind = ''): void
    {
        if ($finding !== null) {
            $this->hits[$finding][] = $msg;

            return;
        }
        $this->unexplained[] = "[{$kind}] {$msg}";
    }

    /** Shared invariants on the current (savepoint) state; violations are folded into the problem list. */
    /** @param  list<string>  $ignore  invariant ids that do not apply to the scenario (e.g. I13 ignores the overdue fee, which the walk never used) */
    private function invariants(string $label, ?string $finding = null, array $ignore = []): void
    {
        $this->invariantRuns++;
        $this->w->violations = [];
        $inv = new Invariants($this->w);
        $inv->check();
        foreach ($this->w->violations as $v) {
            if (in_array($v['id'], $ignore, true)) {
                continue;
            }
            $this->problem($finding, "{$label}: invariant {$v['id']} - {$v['msg']}", 'invariant');
        }
        $this->w->violations = [];
    }

    private function assertNoUnexplained(): void
    {
        if (getenv('BOUNDARY_STATS')) {
            fwrite(STDERR, sprintf("[boundary-stats] %s: %d requests, %d invariant runs, finding hits %s\n", $this->name(), $this->requests, $this->invariantRuns, json_encode(array_map('count', $this->hits))));
        }
        if (getenv('BOUNDARY_VERBOSE')) {
            fwrite(STDERR, "\n".implode("\n", $this->log)."\n");
        }
        if ($this->hits !== [] && getenv('BOUNDARY_HITS')) {
            fwrite(STDERR, "\n---- findings / known hits ----\n");
            foreach ($this->hits as $id => $lines) {
                fwrite(STDERR, sprintf("%s (%d)\n", $id, count($lines)));
                foreach (array_slice($lines, 0, 6) as $l) {
                    fwrite(STDERR, '    '.mb_substr($l, 0, 330)."\n");
                }
            }
        }
        $this->assertSame([], $this->unexplained, "Unexplained boundary problems (".$this->requests." requests, ".$this->invariantRuns." invariant runs):\n - ".implode("\n - ", $this->unexplained));
    }

    // ------------------------------------------------------------------ fixtures

    /** Catalogue outfit at an exact price in store $store (the seeded world has 8 store-1 outfits from 275k to 1.1M). */
    private function outfitAt(float|string $price, int $store = 1): int
    {
        static $n = 0;
        $n++;
        $outfit = Outfit::withoutGlobalScopes()->create(['store_id' => $store, 'code' => "B{$store}-{$n}", 'price' => $price, 'stock' => 1]);

        return (int) $outfit->id;
    }

    /** Store-1 catalogue outfit by index (330k, 440k, 550k, 660k, 770k, 880k, 1.1M, 275k). */
    private function outfit(int $idx, int $store = 1): int
    {
        return $this->w->outfits[$store][$idx];
    }

    /** A valid create payload: outfit idx 3 (660.000), 3 days starting tomorrow, down payment 100.000. */
    private function createPayload(array $over = [], array $outfits = [], int $days = 3, int $startOffset = 1): array
    {
        $outfits = $outfits ?: [$this->outfit(3)];
        $start = Carbon::now()->startOfDay()->addDays($startOffset);

        return array_merge([
            'outfit_ids' => $outfits, 'customer_name' => 'Boundary', 'phone' => '0812',
            'start_date' => $start->toDateString(), 'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
            'payment_method' => 'cash', 'payment_kind' => 'down_payment', 'amount_paid' => '100000',
        ], $over);
    }

    private function create(array $payload, string $label = 'setup create', string $actor = 'admin1', int $store = 1): int
    {
        $res = $this->attempt($label, 'post', route('rentals.store'), $payload, $actor, $store, 'accept');
        if (! $res['ok']) {
            throw new \RuntimeException("setup create failed: {$res['status']} {$res['body']}");
        }

        return (int) $res['json']['rental_id'];
    }

    private function row(int $id): object
    {
        return DB::table('rentals')->where('id', $id)->first();
    }

    private function bill(int $id): float
    {
        return $this->w->billOf($this->row($id));
    }

    /** Cash handed back to the customer for this rent (refund / cancel-refund / FREE entries, reversals netted), from the ledger. */
    private function cashBack(int $id): float
    {
        return $this->w->cashBack($id);
    }

    /** The app's own bill summary (RentalFinancialSummary) for a rent; StoreContext must be set or the outfits relation comes back empty. */
    private function appSummary(int $id): array
    {
        $r = $this->row($id);
        $original = [\App\Support\StoreContext::storeId(), \App\Support\StoreContext::allStores()];
        \App\Support\StoreContext::set((int) $r->store_id);
        try {
            $model = \App\Models\Rental::withoutGlobalScopes()->with('outfits')->find($id);

            return \App\Support\RentalFinancialSummary::summarize($model, 10);
        } finally {
            \App\Support\StoreContext::set($original[0], $original[1]);
        }
    }

    /** Pay-button payload (edit modal, only the payment fields move). */
    private function payPayload(int $id, mixed $amount, ?string $kind = null, string $method = 'cash'): array
    {
        $p = $this->w->basePayload($this->row($id));
        $p['payment_amount_add'] = $amount;
        $p['pay_action'] = '1';
        $p['payment_method'] = $method;
        if ($kind !== null) {
            $p['payment_kind'] = $kind;
        }

        return $p;
    }
}
