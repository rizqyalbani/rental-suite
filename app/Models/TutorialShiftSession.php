<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A ShiftSession subclass backed by its own `tutorial_shift_sessions` table,
 * same reasoning as TutorialRental — the shift tutorial needs to let someone
 * genuinely open, close, and view the report of a shift without touching the
 * real `shift_sessions` table (real shift totals, real closed-shift reports).
 */
class TutorialShiftSession extends ShiftSession
{
    protected $table = 'tutorial_shift_sessions';

    protected static function booted(): void
    {
        parent::booted();
        // store_scope (BelongsToStore) — inherited from ShiftSession via its
        // trait. shift_sessions itself has no store_id column (single-store
        // era leftover), so this is already dormant, but neutralize it here
        // too rather than rely on that happening to stay true.
        static::addGlobalScope('store_scope', fn ($query) => $query);
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(TutorialRental::class, 'shift_session_id');
    }
}
