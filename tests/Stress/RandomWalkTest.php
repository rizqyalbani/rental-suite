<?php

namespace Tests\Stress;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * Seeded random walk over the real rental / payment / approval / shift routes.
 *
 *   php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/RandomWalkTest.php
 *   STRESS_SEEDS=40 STRESS_STEPS=60 php -d memory_limit=1G vendor/bin/phpunit --no-coverage tests/Stress/RandomWalkTest.php
 *   STRESS_ONLY_SEED=7 php ...        (replay one seed with its full trace)
 *   STRESS_FROM=101 STRESS_SEEDS=50   (seeds 101..150)
 *   STRESS_PRINT_TRACE=1              (print every seed's trace, not only failing ones)
 *
 * Each seed runs 25-40 random steps (random actor, random action, forbidden combinations included).
 * After every step the invariants in Invariants.php are checked against the raw tables. The test
 * fails when any invariant broke; the message carries the seed and the full step trace so the
 * failure replays with STRESS_ONLY_SEED. A time budget (STRESS_BUDGET_SECONDS, default 420)
 * stops the run cleanly instead of running forever.
 */
class RandomWalkTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_random_walk(): void
    {
        $seeds = (int) (getenv('STRESS_SEEDS') ?: 15);
        $only = getenv('STRESS_ONLY_SEED');
        $budget = (int) (getenv('STRESS_BUDGET_SECONDS') ?: 420);
        $fixedSteps = (int) (getenv('STRESS_STEPS') ?: 0);
        $started = microtime(true);

        $failures = [];
        $held = [];
        $ranSeeds = 0;
        $totals = ['dash' => [0, 0], 'steps' => 0, 'ran' => [], 'excluded' => [], 'known' => [], 'drift' => [], 'statuses' => [], 'coverage' => []];

        $from = (int) (getenv('STRESS_FROM') ?: 1);
        $list = $only !== false && $only !== '' ? [(int) $only] : range($from, $from + $seeds - 1);
        foreach ($list as $seed) {
            if (microtime(true) - $started > $budget) {
                fwrite(STDERR, "\n[stress] time budget reached after {$ranSeeds} seeds\n");
                break;
            }
            $ranSeeds++;
            // Every seed runs inside its own savepoint, so the next one starts from the same empty shop.
            $level = DB::transactionLevel();
            DB::beginTransaction();
            try {
                $result = $this->walk($seed, $fixedSteps);
            } finally {
                while (DB::transactionLevel() > $level) {
                    DB::rollBack();
                }
                \Illuminate\Support\Facades\Cache::flush();
                \App\Support\StoreContext::set(null, false);
                Carbon::setTestNow();
            }
            $totals['steps'] += $result['steps'];
            foreach (['ran', 'excluded', 'known', 'statuses', 'coverage'] as $k) {
                foreach ($result[$k] as $name => $n) {
                    $totals[$k][$name] = ($totals[$k][$name] ?? 0) + $n;
                }
            }
            $totals['dash'][0] += $result['dash'][0];
            $totals['dash'][1] += $result['dash'][1];
            foreach ($result['drift'] as $line) {
                $totals['drift'][] = "seed {$seed} {$line}";
            }
            if (getenv('STRESS_PRINT_TRACE')) {
                fwrite(STDERR, "\n==== trace seed {$seed} ====\n".implode("\n", $result['trace'])."\n");
            }
            if ($result['violations'] !== []) {
                $failures[$seed] = $result;
            } else {
                $held[] = $seed;
            }
        }

        $report = $this->summary($ranSeeds, $totals, $failures, microtime(true) - $started);
        fwrite(STDERR, $report);

        $this->assertSame([], array_keys($failures), "Invariants broke for seeds: ".implode(', ', array_keys($failures))."\n".$report);
    }

    /** @return array<string, mixed> */
    private function walk(int $seed, int $fixedSteps): array
    {
        $rng = new Rng($seed);
        $world = new StressWorld($this, $rng);
        $world->build();
        $inv = new Invariants($world);
        $steps = $fixedSteps > 0 ? $fixedSteps : $rng->int(25, 40);

        for ($i = 0; $i < $steps; $i++) {
            $world->randomStep();
            $inv->check();
            if ($world->violations !== []) {
                // Keep exploring a little after the first violation (other, different problems may show up),
                // but stop once the situation is clearly derailed.
                if (count($world->violations) >= 6) {
                    break;
                }
            }
        }
        $inv->finalSweep();

        return [
            'seed' => $seed, 'steps' => $world->step, 'violations' => $world->violations, 'trace' => $world->trace,
            'ran' => $world->ran, 'excluded' => $world->excluded, 'known' => $inv->knownHits, 'drift' => $inv->drift,
            'statuses' => $world->statuses, 'state' => $world->describeState(), 'coverage' => $world->coverage(),
            'dash' => [$inv->dashboardChecks, $inv->dashboardNonZero],
        ];
    }

    /** @param array<int, array<string, mixed>> $failures */
    private function summary(int $seeds, array $totals, array $failures, float $seconds): string
    {
        $out = sprintf("\n==== STRESS WALK: %d seeds, %d steps, %.1fs, %d seeds with violations ====\n", $seeds, $totals['steps'], $seconds, count($failures));
        arsort($totals['ran']);
        $out .= 'actions run: '.json_encode($totals['ran'])."\n";
        ksort($totals['statuses']);
        $out .= 'http statuses per action: '.json_encode($totals['statuses'])."\n";
        ksort($totals['coverage']);
        $out .= 'data created by the walk: '.json_encode($totals['coverage'])."\n";
        $out .= 'known-finding exclusions that removed a candidate: '.json_encode($totals['excluded'])."\n";
        $out .= sprintf("dashboard formula checks: %d (non-zero amounts in %d)\n", $totals['dash'][0], $totals['dash'][1]);
        $out .= 'known-finding mismatches tolerated by the invariants: '.json_encode($totals['known'])."\n";
        $out .= 'steps after which the ledger sync still had to repair something: '.count($totals['drift'])."\n";
        foreach (array_slice($totals['drift'], 0, 12) as $line) {
            $out .= '   drift: '.$line."\n";
        }

        // Distinct violations (by invariant id) with the first seed that shows each.
        $byId = [];
        foreach ($failures as $seed => $f) {
            foreach ($f['violations'] as $v) {
                $byId[$v['id']][] = $seed;
            }
        }
        $out .= "\ndistinct invariant ids violated: ".json_encode(array_map(fn ($s) => array_values(array_unique($s)), $byId))."\n";

        foreach ($failures as $seed => $f) {
            $out .= "\n---- seed {$seed} ({$f['steps']} steps) ----\n";
            foreach ($f['violations'] as $v) {
                $out .= "VIOLATION [{$v['id']}] at step {$v['step']}: {$v['msg']}\n";
            }
            $first = $f['violations'][0]['trace_len'];
            $out .= "trace up to the first violation (replay: STRESS_ONLY_SEED={$seed}):\n";
            foreach (array_slice($f['trace'], 0, max(1, $first)) as $line) {
                $out .= '  '.$line."\n";
            }
            $out .= "state at end:\n".$f['state']."\n";
        }

        return $out;
    }
}
