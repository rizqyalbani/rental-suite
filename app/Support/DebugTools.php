<?php

namespace App\Support;

/**
 * Cross-check debug tools (Created-date filter, shift lookup, shift reopen, etc.):
 * controlled solely by a super-admin toggle in Settings — the dedicated UI for
 * this feature. Deliberately independent of Laravel's app.debug, which has no
 * UI of its own and controls something else entirely (error page verbosity);
 * it must never silently turn this on or off.
 */
final class DebugTools
{
    public static function enabled(): bool
    {
        return (bool) cache()->get('debug_tools_enabled', false);
    }

    public static function setEnabled(bool $enabled): void
    {
        cache()->forever('debug_tools_enabled', $enabled);
    }
}
