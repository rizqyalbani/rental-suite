<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockOpnameItem extends Model
{
    protected $fillable = ['stock_opname_id', 'outfit_id', 'status', 'note', 'photo_path', 'marked_by_user_id'];

    /**
     * updateOrCreate() that survives two people marking the same outfit at the
     * same moment: both see "no row yet", the second insert then trips the
     * (session, outfit) unique index. That is not an error — the second mark
     * simply becomes an update of the row the first one made.
     */
    public static function upsertSafely(array $keys, array $values): self
    {
        try {
            return static::updateOrCreate($keys, $values);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            return static::updateOrCreate($keys, $values);
        }
    }

    public function outfit(): BelongsTo
    {
        return $this->belongsTo(Outfit::class);
    }

    public function stockOpname(): BelongsTo
    {
        return $this->belongsTo(StockOpname::class);
    }

    public function markedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'marked_by_user_id');
    }
}
