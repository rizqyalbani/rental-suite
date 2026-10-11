<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowed = collect($roles)
            ->flatMap(fn (string $role) => explode(',', $role))
            ->map(fn (string $role) => trim($role))
            ->filter()
            ->values()
            ->all();
        $user = $request->user();
        $userRole = $user ? strtolower(trim((string) $user->role)) : '';
        $userRole = match ($userRole) {
            'manager' => 'management',
            default => $userRole,
        };
        $allowedNorm = collect($allowed)
            ->map(fn (string $role) => match (strtolower(trim($role))) {
                'manager' => 'management',
                default => strtolower(trim($role)),
            })
            ->all();

        if (! $user || ! in_array($userRole, $allowedNorm, true)) {
            abort(403, 'Unauthorized access.');
        }

        return $next($request);
    }
}
