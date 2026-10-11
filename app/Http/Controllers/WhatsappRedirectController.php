<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WhatsappRedirectController extends Controller
{
    public function redirect(Request $request): RedirectResponse
    {
        $text = trim((string) $request->query('text', ''));
        $phoneRaw = trim((string) $request->query('phone', ''));

        if ($text === '') {
            abort(400, 'Missing message text.');
        }

        $digits = preg_replace('/\D+/', '', $phoneRaw) ?? '';
        if ($digits !== '' && str_starts_with($digits, '0')) {
            $digits = '62'.substr($digits, 1);
        }

        $enc = rawurlencode($text);
        $url = $digits !== ''
            ? ('https://wa.me/'.rawurlencode($digits).'?text='.$enc)
            : ('https://wa.me/?text='.$enc);

        return redirect()->away($url);
    }
}

