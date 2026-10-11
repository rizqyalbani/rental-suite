<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CspReportOnlyHeader
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);

        $enabled = filter_var((string) env('CSP_REPORT_ONLY', '1'), FILTER_VALIDATE_BOOL);
        if (! $enabled) {
            return $response;
        }

        $reportUri = (string) env('CSP_REPORT_URI', '/csp-report');
        if ($reportUri === '') {
            $reportUri = '/csp-report';
        }

        $policy = implode('; ', array_filter([
            "default-src 'self'",
            "base-uri 'self'",
            "object-src 'none'",
            "frame-ancestors 'self'",
            "img-src 'self' data:",
            "style-src 'self' 'unsafe-inline'",
            "script-src 'self' 'unsafe-inline'",
            "connect-src 'self'",
            'report-uri '.$reportUri,
        ]));

        if (is_object($response) && method_exists($response, 'headers')) {
            $response->headers->set('Content-Security-Policy-Report-Only', $policy);
        }

        return $response;
    }
}

