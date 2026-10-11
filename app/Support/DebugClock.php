<?php

namespace App\Support;

/**
 * Time-travel debug clock: lets testers fast-forward the app clock from the UI.
 * Gated by a super-admin toggle in Settings; defaults to on in local, off elsewhere.
 */
final class DebugClock
{
    public static function enabled(): bool
    {
        return (bool) cache()->get('debug_time_travel_enabled', app()->environment('local'));
    }

    public static function setEnabled(bool $enabled): void
    {
        cache()->forever('debug_time_travel_enabled', $enabled);
        if (! $enabled) {
            cache()->forget('debug_fake_now');
        }
    }

    /** Apply the persisted fake time for this request, if any. */
    public static function apply(): void
    {
        if (! self::enabled()) {
            return;
        }

        $fakeNow = cache()->get('debug_fake_now') ?: env('APP_FAKE_NOW');
        if (! $fakeNow) {
            return;
        }

        try {
            \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse($fakeNow));
        } catch (\Throwable) {
            cache()->forget('debug_fake_now');
        }
    }
}
