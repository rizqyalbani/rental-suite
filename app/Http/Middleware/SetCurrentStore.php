<?php

namespace App\Http\Middleware;

use App\Models\Store;
use App\Support\StoreContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetCurrentStore
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            StoreContext::set(null, false);

            return $next($request);
        }

        // Accounting is a centralized bookkeeping function, not a per-store
        // operational role — it needs the same cross-store visibility (every
        // active store + the "All stores" toggle) as Management/Super Admin,
        // unlike Admin/Supervisor who are scoped to their assigned store(s).
        $isPrivileged = $user->isManagement() || $user->isSuperAdmin() || $user->isAccounting();
        $accessible = $isPrivileged
            ? Store::query()->where('is_active', true)->orderBy('id')->get(['id', 'name'])
            : $user->stores()->where('is_active', true)->orderBy('stores.id')->get(['stores.id', 'stores.name']);

        if ($accessible->isEmpty()) {
            $fallback = Store::query()->where('is_active', true)->orderBy('id')->first();
            if ($fallback) {
                $accessible = collect([$fallback]);
            }
        }

        $requestedStoreId = (int) $request->input('store_id', 0);
        if ($requestedStoreId > 0 && $accessible->contains(fn ($store) => (int) $store->id === $requestedStoreId)) {
            $request->session()->put('current_store_id', $requestedStoreId);
        }

        $selectedId = (int) $request->session()->get('current_store_id', 0);
        if ($selectedId <= 0 || ! $accessible->contains(fn ($store) => (int) $store->id === $selectedId)) {
            $selectedId = (int) ($accessible->first()->id ?? 0);
            if ($selectedId > 0) {
                $request->session()->put('current_store_id', $selectedId);
            }
        }

        $allStores = $isPrivileged && $request->boolean('all_stores');
        StoreContext::set($selectedId > 0 ? $selectedId : null, $allStores);

        view()->share('availableStores', $accessible);
        view()->share('currentStoreId', $selectedId);
        view()->share('allStoresMode', $allStores);

        return $next($request);
    }
}
