<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\ExcludesAccountingTrialData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use BelongsToStore;
    use ExcludesAccountingTrialData;

    protected $fillable = [
        'store_id',
        'entry_date',
        'memo',
        'reference_type',
        'reference_id',
        'fixed_asset_id',
        'created_by_user_id',
        'is_reversal',
        'reverses_journal_entry_id',
        'is_tutorial',
        'is_tutorial_practice',
    ];

    protected function casts(): array
    {
        return [
            'entry_date' => 'date',
            'is_reversal' => 'boolean',
            'is_tutorial' => 'boolean',
            'is_tutorial_practice' => 'boolean',
        ];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function reverses(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reverses_journal_entry_id');
    }

    public function fixedAsset(): BelongsTo
    {
        return $this->belongsTo(FixedAsset::class);
    }
}
