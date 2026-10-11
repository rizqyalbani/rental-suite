<?php

namespace App\Providers;

use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\SecurityEvent;
use App\Models\ShiftSession;
use App\Models\Outfit;
use App\Models\TutorialRental;
use App\Models\TutorialRentalApprovalRequest;
use App\Models\TutorialShiftSession;
use App\Support\AdminDeleteRestriction;
use App\Support\RentDeleteRestriction;
use App\Support\StoreContext;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Time-travel debug clock (super-admin toggle in Settings; defaults on in local).
        // Set from the debug bar in the UI, or via APP_FAKE_NOW in .env.
        \App\Support\DebugClock::apply();

        Route::bind('rental', function (string $value) {
            $user = Auth::user();
            $id = (int) $value;
            if ($id <= 0) {
                abort(404);
            }

            // Some routes (invoice generation etc.) declare ->withTrashed()
            // to stay reachable for a cancelled/deleted rental — an explicit
            // Route::bind() closure like this one replaces Laravel's own
            // implicit-binding resolution entirely, so that flag has to be
            // read back and honored manually or those routes would silently
            // stop finding trashed rentals.
            $currentRoute = Route::current();
            $allowsTrashed = $currentRoute && $currentRoute->allowsTrashedBindings();

            // Practice rents have their own id sequence; a practice screen marks its
            // requests with ?tutorial=1 (or the tour's hidden tutorial_practice field) so the
            // same number never resolves to a real rent.
            if ($user && (request()->boolean('tutorial') || request()->boolean('tutorial_practice'))) {
                $practice = TutorialRental::query()->whereKey($id)->where('created_by_user_id', $user->id)->first();
                if ($practice) {
                    return $practice;
                }
            }
            // This also serves guest-facing signed invoice links
            // (invoices.view/regenerate/template-preview/download —
            // middleware('signed:relative'), deliberately no auth), so this
            // can't require a logged-in user the way it used to. Confirmed
            // live: with the old unconditional 404-if-no-user check, the
            // server's own Playwright receipt-image screenshot step (which
            // requests template-preview with no session, exactly like a
            // logged-out customer would) 404'd on every single generation —
            // silently falling back to a PDF-rasterized or hand-drawn image
            // that looks different from the live page, which is the receipt
            // image/page mismatch reported live. Every other {rental} route
            // (rentals.update/destroy, invoices.generate, etc.) stays behind
            // its own 'auth' middleware regardless, so this doesn't loosen
            // anything for them — 'auth' already runs before route binding.
            //
            // When there IS a user, match against every store they may
            // access, not just the currently-selected one (same reasoning as
            // {outfit}/{approval} below) — otherwise management/super-admin
            // viewing "All stores" could see a rental in the list but 404
            // the moment they opened it, if it happened to belong to a store
            // other than whichever one their session had selected.
            $rentalQuery = $user
                ? Rental::withoutGlobalScope('store_scope')->whereIn('store_id', $user->accessibleActiveStoreIds())
                : Rental::withoutGlobalScope('store_scope');
            if ($allowsTrashed) {
                $rentalQuery->withTrashed();
            }
            $rental = $rentalQuery->find($id);
            // Practice rentals live in their own table with their own id
            // sequence (see TutorialRental) — same reasoning as the
            // {approval} binding above. Guest signed links never resolve
            // against it: a practice rental has no real receipt to share.
            if (! $rental && $user) {
                $tutorialQuery = TutorialRental::query();
                if ($allowsTrashed) {
                    $tutorialQuery->withTrashed();
                }
                $rental = $tutorialQuery->whereKey($id)->where('created_by_user_id', $user->id)->first();
            }

            if (! $rental) {
                abort(404);
            }

            return $rental;
        });

        Route::bind('shift', function (string $value) {
            $user = Auth::user();
            $id = (int) $value;
            if ($id <= 0) {
                abort(404);
            }

            // Unlike {rental}/{approval}, this does NOT fall back to the
            // Tutorial* table on a miss: tutorial_shift_sessions and
            // shift_sessions both auto-increment from 1 independently, so
            // their ids collide constantly once either table has more than a
            // handful of rows (real shift #4 and practice shift #4 both
            // genuinely exist) — a fallback here would silently resolve to
            // the WRONG one whenever a real shift happens to occupy the same
            // id a practice-shift URL is asking for. Practice shift routes
            // use the separate {tutorialShift} binding below instead, which
            // has no such ambiguity since it only ever looks in one table.
            //
            // {shift} also serves a guest-facing signed PDF link
            // (shifts.report.pdf, no auth middleware), so this can't require
            // a logged-in user the way {rental}/{approval} do. When there IS
            // one, match against every store they may access rather than
            // just the currently-selected one (same reasoning as {rental}
            // above) — otherwise management viewing another store's shift
            // report (which authorizeShiftReport() explicitly allows) would
            // 404 before that authorization check ever ran.
            $shiftQuery = ShiftSession::query();
            if ($user) {
                $shiftQuery = ShiftSession::withoutGlobalScope('store_scope')
                    ->whereIn('store_id', $user->accessibleActiveStoreIds());
            }
            $shift = $shiftQuery->find($id);
            if (! $shift) {
                abort(404);
            }

            return $shift;
        });

        Route::bind('tutorialShift', function (string $value) {
            $user = Auth::user();
            $id = (int) $value;
            if ($id <= 0 || ! $user) {
                abort(404);
            }

            $shift = TutorialShiftSession::whereKey($id)->where('opened_by_user_id', $user->id)->first();
            if (! $shift) {
                abort(404);
            }

            return $shift;
        });

        Route::bind('approval', function (string $value) {
            $user = Auth::user();
            $id = (int) $value;
            if ($id <= 0 || ! $user) {
                abort(404);
            }

            // Practice and real approvals have separate id sequences, so the same
            // number can exist in both. A practice page marks its buttons with
            // ?tutorial=1 — resolve those against the practice table first, or a
            // real (already decided) approval with that id gets picked instead.
            if (request()->boolean('tutorial')) {
                $practice = TutorialRentalApprovalRequest::whereKey($id)
                    ->where('requested_by_user_id', $user->id)
                    ->first();
                if ($practice) {
                    return $practice;
                }
            }

            if ($user->isManagement() || $user->isSuperAdmin()) {
                $storeIds = $user->accessibleActiveStoreIds();
                $approval = RentalApprovalRequest::withoutGlobalScope('store_scope')
                    ->whereKey($id)
                    ->whereIn('store_id', $storeIds)
                    ->first();
            } else {
                $approval = RentalApprovalRequest::query()->whereKey($id)->first();
            }

            // Practice approvals live in their own table with their own id
            // sequence (see TutorialRental) — a real lookup miss doesn't
            // necessarily mean "not found", it can just mean this id belongs
            // to that table instead. Scoped to the caller's own tutorial
            // data only, same access boundary the tag-based version had.
            if (! $approval) {
                $approval = TutorialRentalApprovalRequest::whereKey($id)
                    ->where('requested_by_user_id', $user->id)
                    ->first();
            }

            if (! $approval) {
                abort(404);
            }

            return $approval;
        });

        /**
         * Items page / bulk edit / quick delete: implicit {outfit} must resolve for any store the user may access,
         * not only the current session store (avoids 404 when staff has multiple stores or management switches context).
         * Signed public routes (e.g. outfits.status) run without auth — resolve by id without store filter.
         */
        Route::bind('outfit', function (string $value) {
            $id = (int) $value;
            if ($id <= 0) {
                abort(404);
            }

            $user = Auth::user();
            if (! $user) {
                return Outfit::withoutGlobalScope('store_scope')->withoutGlobalScope('tutorial_scope')->whereKey($id)->firstOrFail();
            }

            $storeIds = $user->accessibleActiveStoreIds();
            if ($storeIds === []) {
                abort(404);
            }

            $outfit = Outfit::withoutGlobalScope('store_scope')
                ->withoutGlobalScope('tutorial_scope')
                ->whereKey($id)
                ->whereIn('store_id', $storeIds)
                ->first();

            if (! $outfit) {
                abort(404);
            }

            return $outfit;
        });

        View::composer('*', function ($view): void {
            $user = Auth::user();
            if (! $user) {
                return;
            }

            $myPendingRentalApprovals = RentalApprovalRequest::query()
                ->where('requested_by_user_id', $user->id)
                ->where('status', 'pending')
                ->latest()
                ->limit(5)
                ->get();

            self::attachApprovalOutfitCodes($myPendingRentalApprovals);
            $view->with('myPendingRentalApprovals', $myPendingRentalApprovals);
            $view->with('mayDeleteRecords', AdminDeleteRestriction::userMayDeleteRecords($user));
            $view->with('mayDeleteRentals', RentDeleteRestriction::userMayDeleteRentals($user));

            if ($user->mayApproveItemRemoval()) {
                $itemRemovalStoreIds = $user->accessibleActiveStoreIds();
                $view->with(
                    'pendingItemRemovalCount',
                    RentalApprovalRequest::withoutGlobalScope('store_scope')
                        ->where('status', 'pending')
                        ->whereIn('store_id', $itemRemovalStoreIds)
                        ->where('payload->kind', 'item_removal')
                        ->count()
                );
                $view->with(
                    'pendingEditRequestsCount',
                    RentalApprovalRequest::withoutGlobalScope('store_scope')
                        ->where('status', 'pending')
                        ->whereIn('store_id', $itemRemovalStoreIds)
                        ->where(fn ($q) => $q->whereNull('payload->kind')->orWhereNotIn('payload->kind', ['item_removal', \App\Support\PaymentShiftAssignment::KIND]))
                        ->count()
                );
            }

            if (! ($user->isManagement() || $user->isSuperAdmin())) {
                return;
            }

            $storeIds = $user->accessibleActiveStoreIds();
            $pendingRentalApprovals = RentalApprovalRequest::withoutGlobalScope('store_scope')
                ->where('status', 'pending')
                ->whereIn('store_id', $storeIds)
                ->where(fn ($q) => $q->whereNull('payload->kind')->orWhereNotIn('payload->kind', ['item_removal', \App\Support\PaymentShiftAssignment::KIND]))
                ->with('requestedBy')
                ->latest()
                ->limit(5)
                ->get();

            self::attachApprovalOutfitCodes($pendingRentalApprovals);

            $view->with('pendingRentalApprovals', $pendingRentalApprovals);
            $view->with(
                'pendingRentalApprovalsCount',
                RentalApprovalRequest::withoutGlobalScope('store_scope')
                    ->where('status', 'pending')
                    ->whereIn('store_id', $storeIds)
                    ->where(fn ($q) => $q->whereNull('payload->kind')->orWhereNotIn('payload->kind', ['item_removal', \App\Support\PaymentShiftAssignment::KIND]))
                    ->count()
            );
        });

        Event::listen(Login::class, function (Login $event): void {
            try {
                SecurityEvent::create([
                    'store_id' => StoreContext::storeId(),
                    'user_id' => (int) $event->user->id,
                    'kind' => 'auth_login',
                    'severity' => 'low',
                    'ip' => request()->ip(),
                    'method' => request()->method(),
                    'path' => '/'.ltrim(request()->path(), '/'),
                    'query' => request()->getQueryString(),
                    'user_agent' => request()->userAgent(),
                    'status_code' => 200,
                    'meta' => [
                        'guard' => $event->guard,
                    ],
                ]);
            } catch (\Throwable) {
            }
        });

        Event::listen(Failed::class, function (Failed $event): void {
            try {
                SecurityEvent::create([
                    'store_id' => StoreContext::storeId(),
                    'user_id' => $event->user ? (int) $event->user->id : null,
                    'kind' => 'auth_failed',
                    'severity' => 'medium',
                    'ip' => request()->ip(),
                    'method' => request()->method(),
                    'path' => '/'.ltrim(request()->path(), '/'),
                    'query' => request()->getQueryString(),
                    'user_agent' => request()->userAgent(),
                    'status_code' => 401,
                    'meta' => [
                        'guard' => $event->guard,
                        'credentials_keys' => array_keys((array) $event->credentials),
                    ],
                ]);
            } catch (\Throwable) {
            }
        });

        Event::listen(Lockout::class, function (Lockout $event): void {
            try {
                SecurityEvent::create([
                    'store_id' => StoreContext::storeId(),
                    'user_id' => null,
                    'kind' => 'auth_lockout',
                    'severity' => 'high',
                    'ip' => $event->request->ip(),
                    'method' => $event->request->method(),
                    'path' => '/'.ltrim($event->request->path(), '/'),
                    'query' => $event->request->getQueryString(),
                    'user_agent' => $event->request->userAgent(),
                    'status_code' => 429,
                    'meta' => null,
                ]);
            } catch (\Throwable) {
            }
        });

        Event::listen(Logout::class, function (Logout $event): void {
            try {
                $userId = $event->user ? (int) $event->user->id : null;
                SecurityEvent::create([
                    'store_id' => StoreContext::storeId(),
                    'user_id' => $userId,
                    'kind' => 'auth_logout',
                    'severity' => 'low',
                    'ip' => request()->ip(),
                    'method' => request()->method(),
                    'path' => '/'.ltrim(request()->path(), '/'),
                    'query' => request()->getQueryString(),
                    'user_agent' => request()->userAgent(),
                    'status_code' => 200,
                    'meta' => [
                        'guard' => $event->guard,
                    ],
                ]);
            } catch (\Throwable) {
            }
        });
    }

    /**
     * @param  Collection<int, RentalApprovalRequest>  $approvals
     */
    public static function attachApprovalOutfitCodes($approvals): void
    {
        if ($approvals->isEmpty()) {
            return;
        }

        $allOutfitIds = $approvals
            ->flatMap(fn (RentalApprovalRequest $a) => $a->payload['outfit_ids'] ?? [])
            ->unique()
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        // withoutGlobalScope('tutorial_scope'): a tutorial-tagged approval's
        // outfit_ids point at tutorial-only items, which the default scope excludes
        // — without bypassing it here, the onboarding tour's own practice card
        // would show a bare "#123" instead of the real "TUT 01" code.
        $codeById = $allOutfitIds === []
            ? collect()
            : Outfit::withoutGlobalScope('tutorial_scope')->whereIn('id', $allOutfitIds)->pluck('code', 'id');

        foreach ($approvals as $approval) {
            $codes = collect($approval->payload['outfit_ids'] ?? [])
                ->map(fn ($id) => $codeById->get((int) $id) ?? '#'.(int) $id)
                ->values()
                ->all();
            $approval->setAttribute('approval_outfit_codes', $codes);
            $focusIds = $approval->resolvedApprovalFocusOutfitIds();
            $outfitIdList = array_map('intval', $approval->payload['outfit_ids'] ?? []);
            $focusIds = array_values(array_filter(
                $focusIds,
                fn (int $id) => $id > 0 && in_array($id, $outfitIdList, true)
            ));
            $focusCodes = collect($focusIds)
                ->map(fn (int $id) => $codeById->get($id) ?? '#'.$id)
                ->values()
                ->all();
            $approval->setAttribute('approval_focus_outfit_codes', $focusCodes);
        }
    }
}
