<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Public absolute URLs embedded in WhatsApp / Fonnte payloads must be reachable from the internet.
 * Values saved in Settings override .env (WHATSAPP_MEDIA_BASE_URL, INVOICE_SIGNED_URL_DAYS).
 */
final class WhatsappLinkSettings
{
    /**
     * Fonnte and similar fetchers need an absolute http(s) URL. Relative signed paths work in the browser on the current origin (e.g. ngrok) but must be expanded here.
     */
    public static function absolutizeUrl(Request $request, string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return $url;
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }

        return rtrim($request->root(), '/').'/'.ltrim($url, '/');
    }

    public static function publicBaseUrl(): string
    {
        $cached = StoreCache::get('whatsapp_public_base_url');
        if (is_string($cached) && trim($cached) !== '') {
            return rtrim(trim($cached), '/');
        }

        return rtrim((string) env('WHATSAPP_MEDIA_BASE_URL', ''), '/');
    }

    public static function signedUrlDays(): int
    {
        $cached = StoreCache::get('invoice_signed_url_days');
        if ($cached !== null) {
            return max(0, (int) $cached);
        }

        return max(0, (int) env('INVOICE_SIGNED_URL_DAYS', 0));
    }
}
