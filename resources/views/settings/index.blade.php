<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Settings
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative text-sm">
                    {{ session('success') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-2">Pricing Visibility</h3>
                    <p class="text-sm text-gray-600 mb-4">
                        Control pricing visibility and the cooldown before an item can be rented again.
                    </p>

                    <form method="POST" action="{{ route('settings.pricing') }}" class="space-y-4" enctype="multipart/form-data">
                        @csrf
                        <div class="flex items-center gap-3">
                            <input
                                id="pricing_enabled"
                                name="pricing_enabled"
                                type="checkbox"
                                value="1"
                                {{ $pricingEnabled ? 'checked' : '' }}
                                class="h-4 w-4 rounded border-gray-400 text-indigo-600 shadow-none focus:ring-indigo-500 cursor-pointer"
                            >
                            <label for="pricing_enabled" class="text-sm text-gray-800 cursor-pointer">
                                Enable price and discount fields
                            </label>
                        </div>

                        <div class="text-xs">
                            <span class="px-2 py-1 rounded-full {{ $pricingEnabled ? 'bg-green-100 text-green-700' : 'bg-amber-100 text-amber-700' }}">
                                Current: {{ $pricingEnabled ? 'ON' : 'OFF' }}
                            </span>
                        </div>

                        <div>
                            <label for="rental_return_buffer_days" class="block text-sm font-medium text-gray-700 mb-1">
                                Rent cooldown after end date (days)
                            </label>
                            <input
                                id="rental_return_buffer_days"
                                name="rental_return_buffer_days"
                                type="number"
                                min="0"
                                max="30"
                                value="{{ old('rental_return_buffer_days', $rentalReturnBufferDays) }}"
                                class="form-input w-32"
                            >
                            <p class="mt-1 text-xs text-gray-500">
                                Example: set to 1 to allow the item to be rented again 1 day after the rent end date.
                            </p>
                            @error('rental_return_buffer_days')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="pricing_tax_percent" class="block text-sm font-medium text-gray-700 mb-1">
                                Tax (%)
                            </label>
                            <input
                                id="pricing_tax_percent"
                                name="pricing_tax_percent"
                                type="number"
                                min="0"
                                max="100"
                                step="0.01"
                                value="{{ old('pricing_tax_percent', $pricingTaxPercent ?? 0) }}"
                                class="form-input w-32"
                            >
                            <p class="mt-1 text-xs text-gray-500">
                                Applied to pricing totals (cart, edit cart, and rent list) after discounts.
                            </p>
                            @error('pricing_tax_percent')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="flex items-start gap-3">
                            <input
                                id="pricing_prices_include_tax"
                                name="pricing_prices_include_tax"
                                type="checkbox"
                                value="1"
                                {{ ($pricingPricesIncludeTax ?? false) ? 'checked' : '' }}
                                class="mt-1 h-4 w-4 rounded border-gray-400 text-indigo-600 shadow-none focus:ring-indigo-500 cursor-pointer"
                            >
                            <div>
                                <label for="pricing_prices_include_tax" class="text-sm text-gray-800 cursor-pointer">
                                    Outfit prices already include tax
                                </label>
                                <p class="mt-0.5 text-xs text-gray-500">
                                    When enabled, outfit price is treated as tax-inclusive. Totals still show tax breakdown by extracting it from the item price.
                                </p>
                            </div>
                        </div>

                        <div>
                            <label for="overdue_fine_type" class="block text-sm font-medium text-gray-700 mb-1">
                                Overdue fine per day
                            </label>
                            @php $ofType = old('overdue_fine_type', $overdueFineType ?? 'fixed'); @endphp
                            <div class="flex flex-wrap items-center gap-2">
                                <select id="overdue_fine_type" name="overdue_fine_type" class="form-select w-56"
                                    onchange="document.getElementById('overdueFineFixedWrap').classList.toggle('hidden', this.value !== 'fixed'); document.getElementById('overdueFinePercentWrap').classList.toggle('hidden', this.value !== 'percent');">
                                    <option value="fixed" {{ $ofType === 'fixed' ? 'selected' : '' }}>Fixed amount (Rp per day)</option>
                                    <option value="percent" {{ $ofType === 'percent' ? 'selected' : '' }}>% of item price (per day)</option>
                                    <option value="manual" {{ $ofType === 'manual' ? 'selected' : '' }}>Manual — staff enters the amount per day</option>
                                </select>
                                <span id="overdueFineFixedWrap" class="{{ $ofType === 'fixed' ? '' : 'hidden' }} inline-flex items-center gap-1">
                                    <span class="text-sm text-gray-600">Rp</span>
                                    <input
                                        id="overdue_fine_per_day"
                                        name="overdue_fine_per_day"
                                        type="number"
                                        min="0"
                                        step="1"
                                        value="{{ old('overdue_fine_per_day', $overdueFinePerDay ?? 100000) }}"
                                        class="form-input w-40"
                                    >
                                </span>
                                <span id="overdueFinePercentWrap" class="{{ $ofType === 'percent' ? '' : 'hidden' }} inline-flex items-center gap-1">
                                    <input
                                        id="overdue_fine_percent_per_day"
                                        name="overdue_fine_percent_per_day"
                                        type="number"
                                        min="0"
                                        max="100"
                                        step="0.5"
                                        value="{{ old('overdue_fine_percent_per_day', $overdueFinePercentPerDay ?? 10) }}"
                                        class="form-input w-28"
                                    >
                                    <span class="text-sm text-gray-600">% of the rent's item list price</span>
                                </span>
                            </div>
                            <p class="mt-1 text-xs text-gray-500">
                                Applied only when staff clicks “Apply charge” for an overdue rental. Does not change rental dates or day counts.
                                “% of item price” uses the sum of the rent's item list prices as the base for each overdue day.
                            </p>
                            @error('overdue_fine_type')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            @error('overdue_fine_per_day')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                            @error('overdue_fine_percent_per_day')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div>
                            <label for="pricing_legacy_cut_off_date" class="block text-sm font-medium text-gray-700 mb-1">
                                Legacy pricing cut-off date (optional)
                            </label>
                            <input
                                id="pricing_legacy_cut_off_date"
                                name="pricing_legacy_cut_off_date"
                                type="date"
                                value="{{ old('pricing_legacy_cut_off_date', $pricingLegacyCutOffDate) }}"
                                class="form-input w-48"
                            >
                            <p class="mt-1 text-xs text-gray-500">
                                Rentals created <strong>before</strong> this date use legacy day-by-day pricing (no 3-day minimum). Leave empty to apply 3-day block pricing to all rentals.
                            </p>
                            @if($pricingLegacyCutOffDate !== '')
                                <p class="mt-1 text-xs text-indigo-700">
                                    Currently set: rentals before <strong>{{ $pricingLegacyCutOffDate }}</strong> use legacy pricing.
                                </p>
                            @endif
                            @error('pricing_legacy_cut_off_date')
                                <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                            @enderror
                        </div>

                        <div class="pt-4 border-t border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900 mb-2">Admin — Transaction Edit Mode</h3>
                            <p class="text-sm text-gray-600 mb-3">
                                When enabled, all transaction payment and discount fields become editable in Quick Edit — even for transactions that are already fully paid. Use this temporarily to correct historical data (e.g. pricing migration fixes), then turn it off again.
                            </p>
                            <div class="flex items-start gap-3 rounded-lg border {{ $adminTransactionEditMode ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-gray-50' }} p-3">
                                <input
                                    id="admin_transaction_edit_mode"
                                    name="admin_transaction_edit_mode"
                                    type="checkbox"
                                    value="1"
                                    {{ $adminTransactionEditMode ? 'checked' : '' }}
                                    class="mt-0.5 h-4 w-4 rounded border-gray-400 text-amber-600 shadow-none focus:ring-amber-500 cursor-pointer"
                                >
                                <div>
                                    <label for="admin_transaction_edit_mode" class="text-sm font-semibold text-gray-800 cursor-pointer">
                                        Enable transaction edit mode
                                    </label>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        Unlocks payment amount, payment kind, and discounts for all rentals in Quick Edit, bypassing the normal payment-lock rules.
                                    </p>
                                    @if($adminTransactionEditMode)
                                        <p class="mt-1.5 text-xs font-semibold text-amber-700">Currently ON — all transactions are editable. Turn off after fixing.</p>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900 mb-2">WhatsApp and external links</h3>
                            <p class="text-sm text-gray-600 mb-4">
                                Signed invoice and shift PDF URLs must be reachable by Fonnte/WhatsApp. Use your public site URL in production, or a tunnel URL (for example ngrok) in development. Values saved here override <code class="text-xs bg-gray-100 px-1 rounded">WHATSAPP_MEDIA_BASE_URL</code>, <code class="text-xs bg-gray-100 px-1 rounded">INVOICE_SIGNED_URL_DAYS</code>, and <code class="text-xs bg-gray-100 px-1 rounded">FONNTE_TOKEN</code> in <code class="text-xs bg-gray-100 px-1 rounded">.env</code> when set. The Fonnte send URL stays <code class="text-xs bg-gray-100 px-1 rounded">FONNTE_ENDPOINT</code> (default <code class="text-xs bg-gray-100 px-1 rounded">https://api.fonnte.com/send</code>).
                                For tunnels (ngrok, Cloudflare Tunnel), set <code class="text-xs bg-gray-100 px-1 rounded">TRUSTED_PROXIES=*</code> in <code class="text-xs bg-gray-100 px-1 rounded">.env</code> so Laravel sees HTTPS and the correct host for outbound links.
                            </p>

                            <div class="mb-4">
                                <label for="whatsapp_public_base_url" class="block text-sm font-medium text-gray-700 mb-1">
                                    Public base URL (optional)
                                </label>
                                <input
                                    id="whatsapp_public_base_url"
                                    name="whatsapp_public_base_url"
                                    type="url"
                                    inputmode="url"
                                    placeholder="https://your-domain.com"
                                    value="{{ old('whatsapp_public_base_url', $whatsappPublicBaseUrl) }}"
                                    class="form-input w-full max-w-xl"
                                >
                                <p class="mt-1 text-xs text-gray-500">
                                    Leave empty to use <code class="text-xs bg-gray-100 px-1 rounded">WHATSAPP_MEDIA_BASE_URL</code> from the environment (no trailing slash).
                                </p>
                                @error('whatsapp_public_base_url')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="mb-4">
                                <label for="fonnte_token" class="block text-sm font-medium text-gray-700 mb-1">
                                    Fonnte token (optional)
                                </label>
                                @if(! empty($fonnteTokenSaved))
                                    <p class="mb-1 text-xs text-gray-600">
                                        Saved token: <span class="font-mono">{{ $fonnteTokenSavedHint }}</span>. Enter a new value to replace it, or clear the field and save to use <code class="text-xs bg-gray-100 px-1 rounded">FONNTE_TOKEN</code> from the environment only.
                                    </p>
                                @endif
                                <div class="flex items-center gap-2 w-full max-w-xl">
                                    <input
                                        id="fonnte_token"
                                        name="fonnte_token"
                                        type="password"
                                        autocomplete="off"
                                        autocorrect="off"
                                        autocapitalize="off"
                                        spellcheck="false"
                                        placeholder="Device / API token from Fonnte"
                                        value="{{ old('fonnte_token') }}"
                                        class="form-input w-full font-mono text-sm"
                                    >
                                    <button
                                        type="button"
                                        id="toggle_fonnte_token_visibility"
                                        class="inline-flex items-center justify-center rounded-md border border-gray-300 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50"
                                        aria-label="Show token"
                                    >
                                        Show
                                    </button>
                                </div>
                                <p class="mt-1 text-xs text-gray-500">
                                    Required to send invoice images via Fonnte. Leave empty to use only <code class="text-xs bg-gray-100 px-1 rounded">FONNTE_TOKEN</code> from <code class="text-xs bg-gray-100 px-1 rounded">.env</code>. API URL is always <code class="text-xs bg-gray-100 px-1 rounded">FONNTE_ENDPOINT</code> (default <code class="text-xs bg-gray-100 px-1 rounded">https://api.fonnte.com/send</code>).
                                </p>
                                <p class="mt-1 text-xs text-gray-500">
                                    This token only applies to the store currently selected above — each store keeps its own, saving one never changes another store's token.
                                </p>
                                @error('fonnte_token')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                            <div class="mb-4">
                                <label for="invoice_signed_url_days" class="block text-sm font-medium text-gray-700 mb-1">
                                    Signed link expiry (days)
                                </label>
                                <input
                                    id="invoice_signed_url_days"
                                    name="invoice_signed_url_days"
                                    type="number"
                                    min="0"
                                    max="365"
                                    value="{{ old('invoice_signed_url_days', $invoiceSignedUrlDays) }}"
                                    class="form-input w-32"
                                >
                                <p class="mt-1 text-xs text-gray-500">
                                    Use 0 for links that do not expire (same as the default in <code class="text-xs bg-gray-100 px-1 rounded">.env</code> when this has never been saved).
                                </p>
                                @error('invoice_signed_url_days')
                                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                                @enderror
                            </div>

                        </div>

                        <script>
                            (function () {
                                var input = document.getElementById('fonnte_token');
                                var btn = document.getElementById('toggle_fonnte_token_visibility');
                                if (!input || !btn) return;
                                var fetchUrl = @json(\Illuminate\Support\Facades\Route::has('settings.fonnte-token') ? route('settings.fonnte-token') : '');
                                var hasSaved = @json(! empty($fonnteTokenSaved));

                                function ensureLoaded() {
                                    if (!hasSaved) return Promise.resolve(false);
                                    if (!fetchUrl) return Promise.resolve(false);
                                    if ((input.value || '').trim() !== '') return Promise.resolve(true);
                                    return fetch(fetchUrl + '?_=' + Date.now(), {
                                        cache: 'no-store',
                                        credentials: 'same-origin',
                                        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                                    })
                                        .then(function (r) { return r.ok ? r.json() : null; })
                                        .then(function (d) {
                                            var t = d && typeof d.token === 'string' ? d.token : '';
                                            if (t.trim() === '') return false;
                                            input.value = t;
                                            return true;
                                        })
                                        .catch(function () { return false; });
                                }

                                ensureLoaded();
                                btn.addEventListener('click', function () {
                                    ensureLoaded().then(function () {
                                        var show = input.type === 'password';
                                        input.type = show ? 'text' : 'password';
                                        btn.textContent = show ? 'Hide' : 'Show';
                                        btn.setAttribute('aria-label', show ? 'Hide token' : 'Show token');
                                    });
                                });
                            })();
                        </script>

                        <div class="pt-4 border-t border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900 mb-2">Staff permissions</h3>
                            <p class="text-sm text-gray-600 mb-4">
                                Control which roles can manage items and rents.
                            </p>
                            <div class="flex items-center gap-3">
                                <input
                                    id="restrict_admin_deletes"
                                    name="restrict_admin_deletes"
                                    type="checkbox"
                                    value="1"
                                    {{ ($restrictAdminDeletes ?? false) ? 'checked' : '' }}
                                    class="h-4 w-4 rounded border-gray-400 text-indigo-600 shadow-none focus:ring-indigo-500 cursor-pointer"
                                >
                                <label for="restrict_admin_deletes" class="text-sm text-gray-800 cursor-pointer">
                                    Prevent admin accounts from deleting items, deleting rents/invoices, and cancelling rents
                                </label>
                            </div>
                            <div class="mt-3 flex items-center gap-3">
                                <span class="text-sm text-gray-800">
                                    Admin accounts cannot create/update/delete items
                                </span>
                            </div>
                            <div class="mt-3 flex items-center gap-3">
                                <input
                                    id="allow_management_delete_rents"
                                    name="allow_management_delete_rents"
                                    type="checkbox"
                                    value="1"
                                    {{ ($allowManagementDeleteRents ?? true) ? 'checked' : '' }}
                                    class="h-4 w-4 rounded border-gray-400 text-indigo-600 shadow-none focus:ring-indigo-500 cursor-pointer"
                                >
                                <label for="allow_management_delete_rents" class="text-sm text-gray-800 cursor-pointer">
                                    Allow management accounts to delete rents (no shift needed)
                                </label>
                            </div>
                            <p class="mt-2 text-xs text-gray-500">
                                Super admin is always allowed. When a restriction is enabled, admin accounts become view-only for that area.
                            </p>
                        </div>

                        <div class="pt-4 border-t border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900 mb-2">Accounting — Trial Mode</h3>
                            <p class="text-sm text-gray-600 mb-3">
                                While on, every new Expense, Cash In/Out, and Chart of Accounts entry is tagged as
                                trial data — it behaves completely normally (shows in history, counts toward the
                                Dashboard and balances) so you can freely test the real pages. The moment this is
                                turned off, all trial-tagged entries disappear from the Dashboard, History, and
                                balances instantly — nothing is deleted, they're just excluded, giving you a clean
                                slate the moment you go live with real transactions. A tutorial's own guided
                                practice step (the pre-filled example inside a "Start" walkthrough) is the one
                                exception — it never counts toward real numbers, on or off, since a walkthrough can
                                be reopened any time, even long after you've gone live.
                            </p>
                            <div class="flex items-start gap-3 rounded-lg border {{ $accountingTrialModeEnabled ? 'border-amber-300 bg-amber-50' : 'border-gray-200 bg-gray-50' }} p-3">
                                <input
                                    id="accounting_trial_mode_enabled"
                                    name="accounting_trial_mode_enabled"
                                    type="checkbox"
                                    value="1"
                                    {{ $accountingTrialModeEnabled ? 'checked' : '' }}
                                    class="mt-0.5 h-4 w-4 rounded border-gray-400 text-amber-600 shadow-none focus:ring-amber-500 cursor-pointer"
                                >
                                <div>
                                    <label for="accounting_trial_mode_enabled" class="text-sm font-semibold text-gray-800 cursor-pointer">
                                        Enable accounting trial mode
                                    </label>
                                    <p class="mt-0.5 text-xs text-gray-500">
                                        Turn this off once you're ready to record real transactions.
                                    </p>
                                    @if($accountingTrialModeEnabled)
                                        <p class="mt-1.5 text-xs font-semibold text-amber-700">Currently ON — everything recorded right now is trial data.</p>
                                    @endif
                                </div>
                            </div>
                        </div>

                        <div class="pt-4 border-t border-gray-200">
                            <h3 class="text-lg font-medium text-gray-900 mb-2">Rental Revenue Posting</h3>
                            <p class="text-sm text-gray-600 mb-3">
                                Every rental payment now posts to the ledger automatically. Choose which account cash
                                payments land in, and which account everything else (transfer/QRIS/EDC/other) lands
                                in — defaults to Store Cash and Bank.
                            </p>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="rental_revenue_cash_account_id" class="block text-sm font-medium text-gray-700 mb-1">Cash payments →</label>
                                    <select name="rental_revenue_cash_account_id" id="rental_revenue_cash_account_id" class="form-select w-full">
                                        @foreach($cashEquivalentAccounts as $account)
                                            <option value="{{ $account->id }}" {{ (int) $rentalRevenueCashAccountId === $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label for="rental_revenue_noncash_account_id" class="block text-sm font-medium text-gray-700 mb-1">Transfer/QRIS/EDC/other →</label>
                                    <select name="rental_revenue_noncash_account_id" id="rental_revenue_noncash_account_id" class="form-select w-full">
                                        @foreach($cashEquivalentAccounts as $account)
                                            <option value="{{ $account->id }}" {{ (int) $rentalRevenueNoncashAccountId === $account->id ? 'selected' : '' }}>{{ $account->code }} — {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        </div>

                        @if(Auth::user()->isSuperAdmin())
                            <div class="pt-4 border-t border-gray-200">
                                <h3 class="text-lg font-medium text-gray-900 mb-2">Super admin</h3>
                                <div class="flex items-center gap-3">
                                    <input
                                        id="debug_time_travel_enabled"
                                        name="debug_time_travel_enabled"
                                        type="checkbox"
                                        value="1"
                                        {{ ($debugTimeTravelEnabled ?? false) ? 'checked' : '' }}
                                        class="h-4 w-4 rounded border-gray-400 text-indigo-600 shadow-none focus:ring-indigo-500 cursor-pointer"
                                    >
                                    <label for="debug_time_travel_enabled" class="text-sm text-gray-800 cursor-pointer">
                                        Enable time-travel debug bar (fake app clock for testing overdue fines etc.)
                                    </label>
                                </div>
                                <p class="mt-2 text-xs text-gray-500">
                                    Shows a bar at the top of every page to fast-forward the app clock. Turning it off also resets the clock to real time. Only super admin can see and use it outside local development.
                                </p>

                                <div class="flex items-center gap-3 mt-4">
                                    <input
                                        id="debug_tools_enabled"
                                        name="debug_tools_enabled"
                                        type="checkbox"
                                        value="1"
                                        {{ ($debugToolsEnabled ?? false) ? 'checked' : '' }}
                                        class="h-4 w-4 rounded border-gray-400 text-indigo-600 shadow-none focus:ring-indigo-500 cursor-pointer"
                                    >
                                    <label for="debug_tools_enabled" class="text-sm text-gray-800 cursor-pointer">
                                        Enable cross-check debug tools (Created-date filter, shift lookup, shift reopen)
                                    </label>
                                </div>
                                <p class="mt-2 text-xs text-gray-500">
                                    Adds a "Created" date filter on Rents (matches when a rent was recorded, not its rental period), a shift lookup panel to cross-check daily reports against shifts, and a "Reopen" button on closed shift reports. Read-only except reopen, which only clears a shift's closed status/totals. This checkbox is the only thing that controls it — independent of the server's APP_DEBUG setting.
                                </p>

                                <div class="mt-4">
                                    <label for="login_method" class="block text-sm text-gray-800 mb-1">Login with</label>
                                    <select
                                        id="login_method"
                                        name="login_method"
                                        class="form-select w-full max-w-xs"
                                    >
                                        <option value="email" {{ ($loginMethod ?? 'both') === 'email' ? 'selected' : '' }}>Email only</option>
                                        <option value="username" {{ ($loginMethod ?? 'both') === 'username' ? 'selected' : '' }}>Username only</option>
                                        <option value="both" {{ ($loginMethod ?? 'both') === 'both' ? 'selected' : '' }}>Both email and username</option>
                                    </select>
                                    <p class="mt-2 text-xs text-gray-500">
                                        Controls what the single login field on the login page accepts.
                                    </p>
                                </div>
                            </div>
                        @endif

                        <button
                            type="submit"
                            class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer"
                        >
                            Save Setting
                        </button>
                    </form>
                </div>
            </div>

            @if(Auth::user()->isSuperAdmin())
                @php
                    $accountingTrialTotal = array_sum($accountingTrialDataCounts ?? []);
                @endphp
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-6">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Clear Trial Accounting Data</h3>
                        <p class="text-sm text-gray-600 mb-4">
                            Permanently deletes every trial-tagged Expense, Cash In/Out, Account, and their journal
                            entries — across every store. Real data is never touched. This can't be undone; run it
                            once, right after going live, to clean up everything from testing.
                        </p>
                        <p class="text-sm text-gray-700 mb-4">
                            Currently: <strong>{{ $accountingTrialDataCounts['expenses'] ?? 0 }}</strong> expenses,
                            <strong>{{ $accountingTrialDataCounts['cash_movements'] ?? 0 }}</strong> cash movements,
                            <strong>{{ $accountingTrialDataCounts['accounts'] ?? 0 }}</strong> accounts tagged as trial.
                        </p>
                        <form method="POST" action="{{ route('settings.accounting-trial-data.clear') }}"
                            onsubmit="return window.rentalConfirmSubmit(event, 'Permanently delete all {{ $accountingTrialTotal }} trial accounting records? This cannot be undone.', { title: 'Clear trial data?', confirmButtonText: 'Delete', cancelButtonText: 'Cancel' })">
                            @csrf
                            <button
                                type="submit"
                                {{ $accountingTrialTotal === 0 ? 'disabled' : '' }}
                                class="px-4 py-2 bg-red-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-red-700 transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-red-600"
                            >
                                Clear Trial Data
                            </button>
                        </form>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-6">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Fill In Missing Invoice Numbers</h3>
                        <p class="text-sm text-gray-600 mb-4">
                            Rentals that do not have an invoice number yet (for example because they were created
                            before this feature existed, or the database was just re-imported) will be numbered in
                            sequence by creation date, per store. Safe to click many times: rentals that already
                            have a number will not be changed.
                        </p>
                        <p class="text-sm text-gray-700 mb-4">
                            Right now: <strong>{{ $rentalsMissingInvoiceNumberCount }}</strong> rentals do not have an
                            invoice number yet (across all stores).
                        </p>
                        <form method="POST" action="{{ route('settings.rentals.backfill-invoice-numbers') }}"
                            onsubmit="return window.rentalConfirmSubmit(event, 'Fill in invoice numbers for the {{ $rentalsMissingInvoiceNumberCount }} rentals that are still empty?', { title: 'Fill in invoice numbers?', confirmButtonText: 'Fill In Now', cancelButtonText: 'Cancel' })">
                            @csrf
                            <button
                                type="submit"
                                {{ $rentalsMissingInvoiceNumberCount === 0 ? 'disabled' : '' }}
                                class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-indigo-600"
                            >
                                Fill In Invoice Numbers
                            </button>
                        </form>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-6">
                    <div class="p-6">
                        <h3 class="text-lg font-medium text-gray-900 mb-2">Sync Payments to the Ledger</h3>
                        <p class="text-sm text-gray-600 mb-4">
                            Rental payments (deposit, settlement, or full payment) that were really recorded but
                            never reached the accounting journal, usually because of a momentary problem at the time
                            (accounts not yet complete, or a deploy in progress). This button tries to post them again
                            using the original data (original amount &amp; date), without changing the rental data at
                            all. If the payment record itself is also missing (the rental exists and the deposit is
                            recorded on the rental, but the payment row was never created), the record is rebuilt from
                            the data on that rental. If a rental was edited after being paid (items added/removed,
                            discount, dates), the difference is detected and journalled as a Receivable adjustment.
                            Safe to click many times: it only touches what is really still out of sync.
                        </p>
                        <p class="text-sm text-gray-700 mb-4">
                            Right now: <strong>{{ $rentalLedgerSyncPendingCount }}</strong> payments are not yet
                            synced to the ledger (across all stores).
                        </p>
                        @if(count($rentalLedgerDriftRows) > 0)
                            <div class="mb-4 border border-gray-200 rounded-md overflow-x-auto">
                                <p class="px-3 py-2 text-xs font-medium text-gray-700 bg-gray-50 border-b border-gray-200">
                                    Rentals whose total differs from what is recorded in the journal (probably edited after being paid)
                                </p>
                                <table class="min-w-full text-xs">
                                    <thead class="bg-gray-50 text-gray-500 uppercase">
                                        <tr>
                                            <th class="px-3 py-2 text-left">Rental</th>
                                            <th class="px-3 py-2 text-left">Status</th>
                                            <th class="px-3 py-2 text-right">Current total</th>
                                            <th class="px-3 py-2 text-right">In journal</th>
                                            <th class="px-3 py-2 text-right">Difference</th>
                                            <th class="px-3 py-2 text-left">Handling</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        @foreach($rentalLedgerDriftRows as $row)
                                            <tr>
                                                <td class="px-3 py-2 whitespace-nowrap">#{{ $row['rental']->id }} {{ $row['rental']->customer_name }}</td>
                                                <td class="px-3 py-2">{{ $row['rental']->status }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['due'], 0, ',', '.') }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['booked'], 0, ',', '.') }}</td>
                                                <td class="px-3 py-2 text-right tabular-nums">{{ number_format($row['drift'], 0, ',', '.') }}</td>
                                                <td class="px-3 py-2 {{ $row['auto'] ? 'text-green-700' : 'text-amber-700' }}">{{ $row['auto'] ? 'Automatic when synced' : 'Check manually' }}</td>
                                            </tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                        @endif
                        <form method="POST" action="{{ route('settings.rentals.sync-missing-journal-entries') }}"
                            onsubmit="return window.rentalConfirmSubmit(event, 'Sync {{ $rentalLedgerSyncPendingCount }} payments that are not yet in the ledger? This writes new journals and does not change any existing rental data.', { title: 'Sync now?', confirmButtonText: 'Sync', cancelButtonText: 'Cancel' })">
                            @csrf
                            <button
                                type="submit"
                                {{ $rentalLedgerSyncPendingCount === 0 ? 'disabled' : '' }}
                                class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-indigo-600"
                            >
                                Sync Now
                            </button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</x-app-layout>
