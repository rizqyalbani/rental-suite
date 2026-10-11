<?php

namespace Tests\Unit;

use App\Support\OverdueDuration;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OverdueDurationTest extends TestCase
{
    public function test_not_overdue_when_now_is_before_due_boundary(): void
    {
        $tz = 'Asia/Makassar';
        $end = Carbon::create(2026, 5, 10, 0, 0, 0, $tz);
        $now = Carbon::create(2026, 5, 10, 23, 59, 0, $tz);

        $sec = OverdueDuration::overdueSecondsForEndDate($end, $now);
        $this->assertSame(0, $sec);
        $this->assertSame('', OverdueDuration::formatSeconds($sec));
    }

    public function test_minutes_overdue(): void
    {
        $tz = 'Asia/Makassar';
        $end = Carbon::create(2026, 5, 10, 0, 0, 0, $tz);
        $now = Carbon::create(2026, 5, 11, 0, 10, 0, $tz);

        $sec = OverdueDuration::overdueSecondsForEndDate($end, $now);
        $this->assertSame(600, $sec);
        $this->assertSame('10 minutes overdue', OverdueDuration::formatSeconds($sec));
    }

    public function test_week_and_days_overdue(): void
    {
        $tz = 'Asia/Makassar';
        $end = Carbon::create(2026, 5, 1, 0, 0, 0, $tz);
        $now = Carbon::create(2026, 5, 11, 0, 0, 0, $tz);

        $sec = OverdueDuration::overdueSecondsForEndDate($end, $now);
        $this->assertSame('1 week and 2 days overdue', OverdueDuration::formatSeconds($sec));
    }

    public function test_multiple_years_overdue(): void
    {
        $tz = 'Asia/Makassar';
        $end = Carbon::create(2024, 1, 1, 0, 0, 0, $tz);
        $now = Carbon::create(2026, 3, 12, 0, 0, 0, $tz);

        $sec = OverdueDuration::overdueSecondsForEndDate($end, $now);
        $this->assertSame('2 years and 2 months overdue', OverdueDuration::formatSeconds($sec));
    }
}

