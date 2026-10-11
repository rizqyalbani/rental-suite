<?php

namespace App\Support;

final class MoneyInput
{
    /**
     * Indonesian-style grouping in forms: thousands ".", decimals ",".
     * Returns normalized numeric string or null when empty or invalid.
     */
    public static function normalizeNullable(mixed $raw): ?string
    {
        // An array (?amount[]=1) or object is not an amount — and (string) on it would throw.
        if ($raw === null || $raw === '' || ! is_scalar($raw)) {
            return null;
        }

        $s = trim((string) $raw);
        if ($s === '') {
            return null;
        }

        $s = str_replace('.', '', $s);
        $s = str_replace(',', '.', $s);

        if ($s === '' || ! is_numeric($s)) {
            return null;
        }

        return $s;
    }
}
