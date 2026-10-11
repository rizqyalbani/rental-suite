<?php

namespace App\Http\Controllers;

use App\Models\CspReport;
use App\Support\StoreContext;
use Illuminate\Http\Request;

class SecurityController extends Controller
{
    public function cspReport(Request $request)
    {
        $payload = $request->all();
        $report = null;
        if (is_array($payload)) {
            $report = $payload['csp-report'] ?? $payload['csp_report'] ?? $payload['report'] ?? null;
        }
        if (! is_array($report)) {
            $report = [];
        }

        $user = $request->user();
        $storeId = StoreContext::storeId();
        $ip = (string) ($request->ip() ?? '');
        $ua = (string) ($request->userAgent() ?? '');

        try {
            CspReport::create([
                'store_id' => $storeId,
                'user_id' => $user ? (int) $user->id : null,
                'ip' => $ip !== '' ? $ip : null,
                'user_agent' => $ua !== '' ? $ua : null,
                'document_uri' => isset($report['document-uri']) ? (string) $report['document-uri'] : null,
                'referrer' => isset($report['referrer']) ? (string) $report['referrer'] : null,
                'blocked_uri' => isset($report['blocked-uri']) ? (string) $report['blocked-uri'] : null,
                'violated_directive' => isset($report['violated-directive']) ? (string) $report['violated-directive'] : null,
                'effective_directive' => isset($report['effective-directive']) ? (string) $report['effective-directive'] : null,
                'original_policy' => isset($report['original-policy']) ? (string) $report['original-policy'] : null,
                'disposition' => isset($report['disposition']) ? (string) $report['disposition'] : null,
                'status_code' => isset($report['status-code']) ? (int) $report['status-code'] : null,
                'raw' => $payload,
            ]);
        } catch (\Throwable) {
        }

        return response('', 204);
    }
}

