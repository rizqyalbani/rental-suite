<?php

namespace App\Support;

use Illuminate\Support\Carbon;

/**
 * Turns request input that a person (or a broken bookmark) can put anything
 * into — "abc", "2026-02-30", an array from ?date_from[]=x — into something
 * safe to hand to a report. Reports read their range straight from the query
 * string; before this, any unparseable date was a server error.
 */
final class DateInput
{
    /** A valid Y-m-d date (years 1970–2100), else $default. */
    public static function date(mixed $value, string $default): string
    {
        if (! is_string($value)) {
            return $default;
        }
        $value = trim($value);
        if ($value === '') {
            return $default;
        }

        try {
            $parsed = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return $default;
        }

        // Rejects "2026-02-30" (which PHP would roll over to March) and non-dates.
        if (! $parsed || $parsed->format('Y-m-d') !== $value || $parsed->year < 1970 || $parsed->year > 2100) {
            return $default;
        }

        return $value;
    }

    /** A trimmed string of at most $max characters; anything that is not a string becomes ''. */
    public static function text(mixed $value, int $max = 200): string
    {
        return is_string($value) ? mb_substr(trim($value), 0, $max) : '';
    }
}
