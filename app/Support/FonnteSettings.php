<?php

namespace App\Support;

/**
 * Fonnte send URL and token. Token saved in Settings overrides {@see config('services.fonnte.token')} / FONNTE_TOKEN when set.
 */
final class FonnteSettings
{
    public static function token(): ?string
    {
        $cached = StoreCache::get('fonnte_token');
        if (is_string($cached)) {
            $trimmed = trim($cached);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        $env = trim((string) config('services.fonnte.token', ''));

        return $env !== '' ? $env : null;
    }

    public static function sendEndpoint(): string
    {
        return trim((string) config('services.fonnte.endpoint', 'https://api.fonnte.com/send'));
    }

    public static function hasSavedToken(): bool
    {
        $cached = StoreCache::get('fonnte_token');

        return is_string($cached) && trim($cached) !== '';
    }

    public static function savedTokenHint(): ?string
    {
        $token = StoreCache::get('fonnte_token');
        if (! is_string($token)) {
            return null;
        }

        $trimmed = trim($token);
        if ($trimmed === '') {
            return null;
        }

        if (strlen($trimmed) <= 4) {
            return str_repeat('•', strlen($trimmed));
        }

        return str_repeat('•', max(8, strlen($trimmed) - 4)).substr($trimmed, -4);
    }
}
