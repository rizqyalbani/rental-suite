<?php

namespace App\Http\Middleware;

use App\Models\SecurityEvent;
use App\Support\StoreContext;
use Closure;
use Illuminate\Http\Request;

class SecurityRequestTelemetry
{
    private const ATTR = '_security_events';

    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next)
    {
        $path = '/'.ltrim($request->path(), '/');
        $method = strtoupper($request->method());
        $ua = (string) ($request->userAgent() ?? '');
        $reasons = [];

        if (! in_array($method, ['GET', 'POST', 'HEAD'], true)) {
            $reasons[] = ['kind' => 'http_method_unexpected', 'severity' => 'high', 'meta' => ['method' => $method]];
        }

        if (preg_match('#\.\./|\.\.\\\\|%2e%2e#i', $path)) {
            $reasons[] = ['kind' => 'path_traversal_probe', 'severity' => 'high', 'meta' => ['path' => $path]];
        }

        if (preg_match('#/(?:\.env|\.git|vendor/phpunit|phpinfo\.php|server-status|wp-login\.php|wp-admin)#i', $path)) {
            $reasons[] = ['kind' => 'known_probe_path', 'severity' => 'high', 'meta' => ['path' => $path]];
        }

        if (strlen($ua) < 4) {
            $reasons[] = ['kind' => 'missing_user_agent', 'severity' => 'medium', 'meta' => ['user_agent' => $ua]];
        }

        if ($reasons !== []) {
            $request->attributes->set(self::ATTR, $reasons);
        }

        return $next($request);
    }

    public function terminate(Request $request, $response): void
    {
        $events = $request->attributes->get(self::ATTR);
        if (! is_array($events) || $events === []) {
            return;
        }

        $status = null;
        if (is_object($response) && method_exists($response, 'getStatusCode')) {
            $status = (int) $response->getStatusCode();
        }

        $user = $request->user();
        $storeId = StoreContext::storeId();
        $ip = (string) ($request->ip() ?? '');
        $method = strtoupper($request->method());
        $path = '/'.ltrim($request->path(), '/');
        $query = (string) ($request->getQueryString() ?? '');
        $ua = (string) ($request->userAgent() ?? '');

        foreach ($events as $e) {
            $kind = is_array($e) ? (string) ($e['kind'] ?? 'request_anomaly') : 'request_anomaly';
            $severity = is_array($e) ? (string) ($e['severity'] ?? 'low') : 'low';
            $meta = is_array($e) ? ($e['meta'] ?? null) : null;

            try {
                SecurityEvent::create([
                    'store_id' => $storeId,
                    'user_id' => $user ? (int) $user->id : null,
                    'kind' => $kind,
                    'severity' => $severity,
                    'ip' => $ip !== '' ? $ip : null,
                    'method' => $method,
                    'path' => $path,
                    'query' => $query !== '' ? $query : null,
                    'user_agent' => $ua !== '' ? $ua : null,
                    'status_code' => $status,
                    'meta' => is_array($meta) ? $meta : null,
                ]);
            } catch (\Throwable) {
            }
        }
    }
}

