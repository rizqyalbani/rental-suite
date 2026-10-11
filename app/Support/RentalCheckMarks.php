<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * "Checked" marks for the dashboard's Rentals to Review list, kept per
 * store in store_settings (no migration). A mark remembers which problems were
 * checked, so a rent comes back to the list only when a new problem appears.
 */
class RentalCheckMarks
{
    private const KEY = 'rental_check_done';

    /** @return array<string, array{f: list<string>, by: string, at: string}> */
    public static function all(): array
    {
        $marks = StoreCache::get(self::KEY, []);

        return is_array($marks) ? $marks : [];
    }

    /** @param  list<string>  $flags */
    public static function mark(int $rentalId, array $flags, string $by): void
    {
        $marks = self::all();
        $old = $marks[(string) $rentalId]['f'] ?? [];
        $marks[(string) $rentalId] = [
            'f' => array_values(array_unique(array_merge($old, array_map([self::class, 'hash'], $flags)))),
            'by' => $by,
            'at' => now()->format('Y-m-d H:i'),
        ];
        StoreCache::forever(self::KEY, $marks, alsoGlobal: false);
    }

    public static function unmark(int $rentalId): void
    {
        $marks = self::all();
        unset($marks[(string) $rentalId]);
        StoreCache::forever(self::KEY, $marks, alsoGlobal: false);
    }

    /**
     * Splits detector rows into still-open and already-checked. A row is done
     * only when every one of its current problems was checked before.
     *
     * @return array{0: Collection, 1: Collection}
     */
    public static function split(Collection $rows): array
    {
        $marks = self::all();
        [$done, $open] = $rows->partition(function (array $row) use ($marks) {
            $mark = $marks[(string) $row['rental']->id] ?? null;

            return $mark && array_diff(array_map([self::class, 'hash'], $row['flags']), $mark['f']) === [];
        });

        return [
            $open->values(),
            $done->map(fn (array $row) => $row + ['mark' => $marks[(string) $row['rental']->id]])->values(),
        ];
    }

    private static function hash(string $flag): string
    {
        return substr(md5($flag), 0, 10);
    }
}
