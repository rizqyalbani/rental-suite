@php
    $rentalStoreStaff = \App\Models\User::withoutGlobalScopes()
        ->whereIn('role', ['admin', 'supervisor', 'management'])
        ->when(\App\Support\StoreContext::storeId(), fn ($q, $sid) => $q->whereHas('stores', fn ($w) => $w->where('stores.id', $sid)))
        ->orderBy('name')
        ->get(['id', 'name']);
@endphp
<script>
        var _pricingEnabled = {{ \App\Support\StoreCache::get('pricing_enabled', true) ? 'true' : 'false' }};
        var _pricingTaxPercent = {{ (float) \App\Support\StoreCache::get('pricing_tax_percent', 0) }};
        var _pricingPricesIncludeTax = {{ \App\Support\StoreCache::get('pricing_prices_include_tax', false) ? 'true' : 'false' }};
        var _pricingLegacyCutOffDate = @json((string) (\App\Support\StoreCache::get('pricing_legacy_cut_off_date') ?? ''));
        var _adminTransactionEditMode = {{ $adminTransactionEditMode ? 'true' : 'false' }};
        // Admin's discount edit always goes through the edit-approval queue
        // for a human to decide, so admin is exempt from the down-payment
        // discount lock below; management/supervisor/super_admin edit
        // directly with no review, so they stay locked. Mirrors the
        // server-side check in RentalController::update() ($actorIsAdmin).
        var _viewerDiscountLockExempt = @json(Auth::user()->isAdmin());
        var _overdueFinePerDay = {{ (float) \App\Support\StoreCache::get('overdue_fine_per_day', 100000) }};
        var _overdueFineType = @json((string) \App\Support\StoreCache::get('overdue_fine_type', 'fixed'));
        var _overdueFinePercentPerDay = {{ (float) \App\Support\StoreCache::get('overdue_fine_percent_per_day', 10) }};
        var __RENTAL_DEBUG_TODAY_YMD = @json((config('app.debug') ? request()->query('debug_today') : null) ?: (\App\Support\DebugClock::enabled() && \Carbon\Carbon::hasTestNow() ? now()->toDateString() : null));
        var rentalMutationsLocked = @json($rentalMutationsLocked);
        // Only management may cancel WITH a refund (everyone else's cancel keeps the money).
        var rentalMayRefundCancel = @json((bool) (auth()->user()?->isManagement() || auth()->user()?->isSuperAdmin()));
        var mayOperateRentTransactions = @json($mayOperateRentTransactions);
        // Staff who can hand over / take back outfits, for "Report pickup/return".
        var rentalStoreStaff = @json($rentalStoreStaff);
        var currentUserId = @json(auth()->id());
        var mayGenerateInvoices = @json($mayGenerateInvoices ?? $mayOperateRentTransactions);
        var mayMutateItems = @json($mayMutateItems);
        var mayDeleteRentals = @json($mayDeleteRentals ?? true);
        var allStoresMode = @json($allStoresMode ?? false);
        var rentalsDeletedMode = @json((bool) ($deletedMode ?? false));
        var rentalsListEmptyHtml = @json(view('rentals.partials.list-empty')->render());
        var rentalsListLoadErrorHtml = @json(view('rentals.partials.list-load-error')->render());
        var rentalsOutfitListEmptyInventoryHtml = @json(view('rentals.partials.outfit-list-empty-inventory')->render());
        var rentalsOutfitListLoadingHtml = @json(view('rentals.partials.outfit-list-loading')->render());
        var rentalsOutfitListLoadErrorHtml = @json(view('rentals.partials.outfit-list-load-error')->render());
        var rentalsOutfitListLoadErrorCompactHtml = @json(view('rentals.partials.outfit-list-load-error-compact')->render());
        var rentalsOutfitListEmptyAvailableHtml = @json(view('rentals.partials.outfit-list-empty-available')->render());
        var rentalsOutfitListEmptyFilterHtml = @json(view('rentals.partials.outfit-list-empty-filter')->render());
        var rentalsOutfitHintDatesRequiredHtml = @json(view('rentals.partials.outfit-hint-dates-required')->render());
        var rentalsOutfitHintDatesRequiredChangeHtml = @json(view('rentals.partials.outfit-hint-dates-required-change')->render());
        var rentalsOutfitHintReadyTemplateHtml = @json(view('rentals.partials.outfit-hint-ready-template')->render());
        var rentalsOutfitHintDatesSetTemplateHtml = @json(view('rentals.partials.outfit-hint-dates-set-template')->render());
        var rentalsMgmtSnapshotWatcher = @json(Auth::user()->isManagement() || Auth::user()->isSuperAdmin());
        var rentalsStaffApprovalWatcher = @json(Auth::user()->isAdmin());
        var rentalCooldownApprovalBypass = @json(Auth::user()->isSuperAdmin());
        // The vars above stay inline (Blade-dependent config/permission constants);
        // everything else lives in the static resources/js/rentals-index.js asset,
        // loaded below. These few extra vars replace what used to be inline Blade
        // conditionals and helper calls scattered through that file — kept as
        // globals here so the static file's references to them still resolve.
        var __rentalsValidationErrors = @json(array_values(array_filter(array_map(static fn ($m) => trim((string) $m), $errors->all()))));
        var __rentalsOldOutfitIds = @json(old('outfit_ids', []));
        var __rentalsOldItemDiscounts = @json($pricing ? old('item_discounts', []) : []);
        var __rentalsOldItemDiscountTypes = @json($pricing ? old('item_discount_types', []) : []);
        var __rentalsHasOldDates = {{ (old('start_date') && old('end_date')) ? 'true' : 'false' }};
        var __rentalsDebugToolsEnabled = {{ \App\Support\DebugTools::enabled() ? 'true' : 'false' }};
        var __rentalsRouteDeletedBulkDestroy = @json(route('rentals.deleted.bulk-destroy'));
        var __rentalsRouteDeletedClear = @json(route('rentals.deleted.clear'));
        var __rentalsRouteQueueFragment = @json(route('approvals.rentals.queue-fragment'));
        var __rentalsRoutePendingSnapshot = @json(route('approvals.rentals.pending-snapshot'));
        var __rentalsInvoiceTimeoutMs = {{ (int) env('INVOICE_GENERATE_CLIENT_TIMEOUT_MS', 300000) }};
</script>
<script src="{{ asset('js/rentals-index.js') }}?v={{ filemtime(public_path('js/rentals-index.js')) }}"></script>
