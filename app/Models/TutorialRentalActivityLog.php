<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * RentalActivityLog subclass backed by `tutorial_rental_activity_logs` —
 * see TutorialRental for why. Its `rental_id` foreign key points at
 * `tutorial_rentals`, not `rentals`, so rental() is overridden accordingly.
 */
class TutorialRentalActivityLog extends RentalActivityLog
{
    protected $table = 'tutorial_rental_activity_logs';

    protected static function booted(): void
    {
        parent::booted();
        static::addGlobalScope('store_scope', fn ($query) => $query);
        static::addGlobalScope('tutorial_scope', fn ($query) => $query);
    }

    public function rental(): BelongsTo
    {
        return $this->belongsTo(TutorialRental::class, 'rental_id');
    }

    public static function record(Rental $rental, string $action, array $changes = [], ?string $note = null, ?int $userId = null): ?self
    {
        if ($action !== 'created' && $action !== 'deleted' && $changes === [] && ($note === null || $note === '')) {
            return null;
        }

        return static::create([
            'store_id' => $rental->store_id,
            'rental_id' => $rental->id,
            'user_id' => $userId ?? auth()->id(),
            'action' => $action,
            'changes' => $changes !== [] ? $changes : null,
            'note' => $note,
        ]);
    }
}
