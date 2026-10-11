<?php

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final class OverdueDuration
{
    public static function overdueSecondsForEndDate(CarbonInterface $endDate, ?CarbonInterface $now = null): int
    {
        $now = $now ? $now->copy() : Carbon::now($endDate->getTimezone());
        $dueAt = $endDate->copy()->startOfDay()->addDay();

        $sec = $dueAt->diffInSeconds($now, false);

        return max(0, (int) $sec);
    }

    public static function formatSeconds(int $seconds): string
    {
        $seconds = max(0, (int) $seconds);
        if ($seconds <= 0) {
            return '';
        }

        $units = [
            'year' => 365 * 86400,
            'month' => 30 * 86400,
            'week' => 7 * 86400,
            'day' => 86400,
            'hour' => 3600,
            'minute' => 60,
        ];

        $parts = [];
        foreach ($units as $name => $unitSeconds) {
            if ($seconds < $unitSeconds) {
                continue;
            }

            $qty = intdiv($seconds, $unitSeconds);
            $seconds -= $qty * $unitSeconds;
            $parts[] = $qty.' '.$name.($qty === 1 ? '' : 's');

            if (count($parts) >= 2) {
                break;
            }
        }

        if ($parts === []) {
            return 'less than a minute overdue';
        }

        return count($parts) === 1
            ? ($parts[0].' overdue')
            : ($parts[0].' and '.$parts[1].' overdue');
    }
}
