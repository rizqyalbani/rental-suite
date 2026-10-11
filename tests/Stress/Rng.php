<?php

namespace Tests\Stress;

/**
 * Tiny deterministic PRNG (xorshift32). The random walk must replay exactly from its seed,
 * and PHP's mt_rand / random_int are also used by Faker and the framework between steps,
 * so the walk keeps its own generator.
 */
final class Rng
{
    private int $state;

    public function __construct(int $seed)
    {
        $this->state = (($seed * 2654435761) + 0x9E3779B9) & 0xFFFFFFFF;
        if ($this->state === 0) {
            $this->state = 1;
        }
        // Warm up so that small consecutive seeds diverge quickly.
        for ($i = 0; $i < 8; $i++) {
            $this->next();
        }
    }

    public function next(): int
    {
        $x = $this->state;
        $x ^= ($x << 13) & 0xFFFFFFFF;
        $x ^= ($x >> 17);
        $x ^= ($x << 5) & 0xFFFFFFFF;
        $this->state = $x & 0xFFFFFFFF;
        if ($this->state === 0) {
            $this->state = 1;
        }

        return $this->state;
    }

    public function int(int $min, int $max): int
    {
        return $min + ($this->next() % ($max - $min + 1));
    }

    /** True with probability $percent / 100. */
    public function chance(int $percent): bool
    {
        return $this->int(1, 100) <= $percent;
    }

    /**
     * @template T
     *
     * @param  list<T>  $items
     * @return T
     */
    public function pick(array $items)
    {
        $items = array_values($items);

        return $items[$this->int(0, count($items) - 1)];
    }

    /**
     * @param  array<string, int>  $weights  name => weight
     */
    public function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $roll = $this->int(1, max(1, $total));
        foreach ($weights as $name => $weight) {
            $roll -= $weight;
            if ($roll <= 0) {
                return (string) $name;
            }
        }

        return (string) array_key_last($weights);
    }
}
