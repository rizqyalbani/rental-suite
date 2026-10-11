<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\User;
use App\Support\Geofence;
use App\Support\StoreCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class AuthenticatedSessionController extends Controller
{
    /**
     * Display the login view.
     */
    public function create(Request $request): View
    {
        $loginMethod = (string) StoreCache::get('login_method', 'both');

        $this->rememberRedirectIntent($request, $request->query('redirect'));

        return view('auth.login', compact('loginMethod'));
    }

    /**
     * Handle an incoming authentication request.
     */
    public function store(LoginRequest $request): RedirectResponse
    {
        // A login form submitted directly — e.g. the inline username/password
        // switcher on an item's public QR page — never visits create() above,
        // so the redirect intent has to be captured here too, from a hidden
        // `redirect` field instead of the query string. Same session key
        // either way, so it's still redirect()->intended() below that acts on it.
        $this->rememberRedirectIntent($request, $request->input('redirect'));

        $request->authenticate();

        /** @var User $user */
        $user = Auth::user();
        if (! $this->passesStaffGeofence($user, $request)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return back()->withErrors([
                'login' => 'You must sign in from your assigned store location. Enable location permission in your browser and try again.',
            ])->onlyInput('login');
        }

        $request->session()->regenerate();

        $this->forgetJsonIntendedUrl($request);

        return redirect()->intended(route('dashboard', absolute: false));
    }

    /**
     * Per-store login geofence (App\Support\Geofence) — opt-in per store,
     * so only actually enforced once Management sets a location for a
     * store, and only for Admin/Supervisor (the roles expected to
     * physically work at one). Management/Super Admin/Accounting are never
     * checked, so there's always a way in to fix a misconfigured store.
     * Passes as soon as ANY of the user's assigned stores is satisfied (a
     * store with geofencing off always counts as satisfied). A user with no
     * store assignment is checked against the first active store, the one
     * SetCurrentStore drops them into.
     */
    private function passesStaffGeofence(User $user, Request $request): bool
    {
        if (! $user->isAdmin() && ! $user->isSupervisor()) {
            return true;
        }

        $storeIds = $user->stores()->pluck('stores.id');
        if ($storeIds->isEmpty()) {
            // An unassigned account still lands in the first active store (see
            // SetCurrentStore) and sees its data — so that store's fence applies.
            $fallback = \App\Models\Store::query()->where('is_active', true)->orderBy('id')->value('id');
            if (! $fallback) {
                return true;
            }
            $storeIds = collect([$fallback]);
        }

        $lat = $request->filled('geo_lat') ? (float) $request->input('geo_lat') : null;
        $lng = $request->filled('geo_lng') ? (float) $request->input('geo_lng') : null;
        $accuracy = $request->filled('geo_accuracy') ? (float) $request->input('geo_accuracy') : null;

        foreach ($storeIds as $storeId) {
            if (Geofence::passes((int) $storeId, $lat, $lng, $accuracy)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Lets a public page's own "Staff? Login" link or inline form (e.g. an
     * item's QR landing page) bounce back to that exact page after
     * authenticating, instead of the default dashboard. Written into the
     * same session key Laravel's own auth middleware uses for "intended"
     * redirects, so redirect()->intended() in store() honors it with no
     * further wiring needed.
     *
     * Accepts either a bare relative path ("/i/xyz") or a full URL — both
     * show up in practice: a query param built with a literal "/..." string
     * is a path, but a Blade `url()->full()` (what the hidden `redirect`
     * field on the inline login form actually sends) is a full URL. A full
     * URL is only honored when its host matches this request's own host —
     * anything else is rejected outright, since accepting an arbitrary host
     * here would make this an open redirect to an attacker-controlled site.
     */
    private function rememberRedirectIntent(Request $request, ?string $redirect): void
    {
        $redirect = trim((string) $redirect);
        if ($redirect === '') {
            return;
        }

        if (preg_match('#^/(?!/)#', $redirect)) {
            $request->session()->put('url.intended', url($redirect));

            return;
        }

        $parts = parse_url($redirect);
        if (
            ! $parts
            || empty($parts['scheme']) || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || empty($parts['host']) || strcasecmp($parts['host'], $request->getHost()) !== 0
        ) {
            return;
        }

        $request->session()->put('url.intended', $redirect);
    }

    /**
     * Background fetches (list refresh, fragments) can be captured as the
     * post-login destination when the session expires mid-page; landing on
     * them would show raw JSON, so fall back to the dashboard instead.
     */
    private function forgetJsonIntendedUrl(Request $request): void
    {
        $intended = (string) $request->session()->get('url.intended', '');
        if ($intended === '') {
            return;
        }

        $path = trim((string) parse_url($intended, PHP_URL_PATH), '/');
        $jsonPaths = [
            'rentals/search',
            'rentals/detail-fragment',
            'rentals/shift-control-fragment',
            'approvals/rentals/pending-snapshot',
            'approvals/rentals/queue-fragment',
            'outfits/available',
            'outfits/unique-check',
        ];
        foreach ($jsonPaths as $jsonPath) {
            if ($path === $jsonPath) {
                $request->session()->forget('url.intended');

                return;
            }
        }
    }

    /**
     * Destroy an authenticated session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }
}
