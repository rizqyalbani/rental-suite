<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

class StockOpname extends Model
{
    use BelongsToStore;

    protected $fillable = [
        'store_id', 'started_by_user_id', 'completed_by_user_id',
        'started_at', 'completed_at', 'notes', 'is_tutorial_practice', 'snapshot',
    ];

    /**
     * stock_opname_items rows are cascade-deleted at the DB level
     * (foreignId()->constrained()->cascadeOnDelete()), which never loads or
     * fires model events on the individual StockOpnameItem rows — so a photo
     * file on the 'local' disk would otherwise never get cleaned up. Deletes
     * every session's row goes through here (tutorial cleanup, or any future
     * real-session deletion), whether cascaded or explicit.
     */
    protected static function booted(): void
    {
        static::deleting(function (StockOpname $stockOpname) {
            $stockOpname->items()->whereNotNull('photo_path')->get()->each(function (StockOpnameItem $item) {
                if ($item->photo_path && Storage::disk('local')->exists($item->photo_path)) {
                    Storage::disk('local')->delete($item->photo_path);
                }
            });
        });
    }

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'is_tutorial_practice' => 'boolean',
            'snapshot' => 'array',
        ];
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockOpnameItem::class);
    }

    public function isCompleted(): bool
    {
        return $this->completed_at !== null;
    }
}
