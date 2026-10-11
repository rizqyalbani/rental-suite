<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Support\WhatsappLinkSettings;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class ShiftSession extends Model
{
    use BelongsToStore;

    public const PERIOD_MORNING = 'morning';

    public const PERIOD_AFTERNOON = 'afternoon';

    protected $fillable = [
        'store_id',
        'opened_by_user_id',
        'closed_by_user_id',
        'opened_at',
        'period',
        'closed_at',
        'opening_note',
        'closing_note',
        'total_transactions',
        'total_revenue',
    ];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'total_revenue' => 'decimal:2',
        ];
    }

    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    public function rentals(): HasMany
    {
        return $this->hasMany(Rental::class, 'shift_session_id');
    }

    public function periodLabel(): string
    {
        return match ($this->period) {
            self::PERIOD_AFTERNOON => 'Afternoon',
            default => 'Morning',
        };
    }

    /**
     * Signed URL to the shift closing report PDF (WhatsApp media, Web Share API, etc.).
     */
    public function signedPublicPdfUrl(): string
    {
        $signedDays = WhatsappLinkSettings::signedUrlDays();
        $publicBaseUrl = WhatsappLinkSettings::publicBaseUrl();
        $params = ['shift' => $this->id];
        // Must match Route::middleware('signed:relative').
        $signed = $signedDays > 0
            ? URL::temporarySignedRoute('shifts.report.pdf', now()->addDays($signedDays), $params, absolute: false)
            : URL::signedRoute('shifts.report.pdf', $params, absolute: false);

        if ($publicBaseUrl === '') {
            return $signed;
        }

        $parts = parse_url($signed);
        if (! is_array($parts) || empty($parts['path'])) {
            return $signed;
        }

        $pathWithQuery = $parts['path'].(isset($parts['query']) ? ('?'.$parts['query']) : '');

        return Str::finish($publicBaseUrl, '/').ltrim($pathWithQuery, '/');
    }
}
