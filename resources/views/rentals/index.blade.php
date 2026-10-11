@php
    $pricing = \App\Support\StoreCache::get('pricing_enabled', true);
    $mayOperateRentTransactions = $mayOperateRentTransactions ?? (Auth::user()?->mayOperateRentTransactions() ?? false);
    $mayGenerateInvoices = Auth::user()?->mayGenerateInvoices() ?? false;
    $mayMutateItems = Auth::user()?->mayMutateItems() ?? false;
    $mayDeleteRentals = \App\Support\RentDeleteRestriction::userMayDeleteRentals(Auth::user());
    $rentalMutationsLocked = $rentalMutationsLocked ?? ($currentShift ?? null) === null || !$mayOperateRentTransactions;
@endphp

<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Rents
        </h2>
    </x-slot>

    <div class="py-6 md:py-12">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative text-sm">
                    {!! session('success') !!}
                </div>
            @endif

            @if (session('error'))
                <div class="mb-4 bg-red-50 border border-red-300 text-red-800 px-4 py-3 rounded relative text-sm">
                    {{ session('error') }}
                </div>
            @endif

            @if (session('approval_request_sent'))
                <div class="mb-4 rounded-lg border border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                    role="status" data-rentals-awaiting-approval-flash>
                    <p class="font-semibold text-amber-950">Request sent — booking is not active yet</p>
                    <p class="mt-1.5 text-amber-900/95 leading-relaxed">
                        Nothing was added to the rent list. A manager must <strong>approve</strong> the cool-down
                        overlap first. After approval, the rent appears below like any other booking.
                    </p>
                    <p class="mt-2 text-xs text-amber-900/85">
                        You can start a different rent in the form above. If you need to change this request, ask a
                        manager to reject it or wait until it is processed.
                    </p>
                    <p class="mt-2 text-xs text-amber-900/80 border-t border-amber-200/80 pt-2">
                        <strong>Who approves?</strong> Only <strong>Management</strong> or <strong>Super Admin</strong>
                        accounts see Approve/Reject. They appear <strong>at the top of this Rents page</strong> and in a
                        small <strong>bottom-right</strong> panel on other screens (plus a number badge next to
                        <strong>Rents</strong> in the menu).
                    </p>
                </div>
            @endif

            @if (session('approval_duplicate'))
                <div class="mb-4 rounded-lg border border-amber-400 bg-amber-50 px-4 py-3 text-sm text-amber-950"
                    role="alert" data-rentals-awaiting-approval-flash>
                    <p class="font-semibold">Duplicate request</p>
                    <p class="mt-1 text-amber-900/95">
                        You already have a <strong>pending</strong> approval for these dates and items. Wait for a
                        manager — submitting again does not speed this up.
                    </p>
                </div>
            @endif

            @php
                $managerApprovalQueue = $pendingRentalApprovals ?? collect();
            @endphp
            {{-- Anchor so the live poll can insert a fresh queue panel at its natural top spot. --}}
            <span id="manager-pending-approvals-top-anchor" class="hidden" aria-hidden="true"></span>
            @if ((Auth::user()->isManagement() || Auth::user()->isSuperAdmin()) && $managerApprovalQueue->isNotEmpty())
                @include('rentals.partials.manager-pending-inline', [
                    'managerApprovalQueue' => $managerApprovalQueue,
                    'pendingRentalApprovalsCount' => (int) ($pendingRentalApprovalsCount ?? 0),
                ])
            @endif

            @include('rentals.partials.payment-assign-panel')

            @php
                $myPendingList = $myPendingRentalApprovals ?? collect();
            @endphp
            {{-- Redundant with the inline "Quick edit" cards above (same pending requests,
                 same rent #/item/date/cancel-request info, just less detail) — kept in the
                 DOM instead of removed because its <template id="pending-edit-diff-*"> diff
                 data and its ids are still read by the inline cards' "View details" toggle
                 and by live-poll JS (appendMyPendingApprovalRow, syncMyPendingApprovalsFromSnapshot,
                 etc. in rentals-index.js) that toggles the Tailwind `hidden` class on this
                 element — an inline display:none survives that, a hardcoded `hidden` class
                 wouldn't. --}}
            <div id="my-pending-approvals-section" style="display:none !important"
                class="mb-4 rounded-lg border border-amber-300 bg-white px-4 py-3 text-sm text-gray-900 shadow-sm">
                <p class="font-semibold text-gray-900">
                    Your pending manager approvals (<span
                        id="my-pending-approvals-count">{{ $myPendingList->count() }}</span>)
                </p>
                <p class="text-xs text-gray-600 mt-1">
                    New cool-down <strong>bookings</strong> are not in the list until a manager approves.
                    <strong>Quick edits</strong> keep the saved rent visible; requested changes apply only after
                    approval.
                </p>
                <ul id="my-pending-approvals-list" class="mt-2 space-y-1.5 text-xs text-gray-800">
                    @foreach ($myPendingList as $p)
                        @php
                            $pr = $p->payload['rental'] ?? [];
                            $codes = $p->approval_outfit_codes ?? [];
                            $focusCodes = $p->approval_focus_outfit_codes ?? [];
                            $showCodes = count($focusCodes) ? $focusCodes : $codes;
                            $otherCodes = [];
                            if (count($focusCodes) > 0 && count($codes) > count($focusCodes)) {
                                $otherCodes = array_values(array_diff($codes, $focusCodes));
                            }
                            $custPending = !empty($pr['customer_pending']) || trim((string) ($pr['customer_name'] ?? '')) === '';
                            $pKind = (string) ($p->payload['kind'] ?? 'create');
                            $pRentalId = isset($p->payload['rental_id']) ? (int) $p->payload['rental_id'] : null;
                        @endphp
                        <li class="border-t border-gray-100 first:border-t-0 first:pt-0 pt-2"
                            data-my-pending-approval="{{ $p->id }}" data-pending-kind="{{ $pKind }}"
                            data-pending-created="{{ $p->created_at?->format('d M Y, H:i') }}"
                            data-pending-created-human="{{ $p->created_at?->diffForHumans() }}"
                            @if ($pKind === 'edit' && $pRentalId) data-pending-rental-id="{{ $pRentalId }}" @endif>
                            @if ($pKind === 'edit' && $pRentalId)
                                <span class="font-medium text-amber-900">Quick edit pending</span>
                                <span class="block text-xs text-gray-600">
                                    Rent #{{ $pRentalId }} — updates apply after approval
                                </span>
                            @elseif ($pKind === 'late_done' && $pRentalId)
                                <span class="font-medium text-amber-900">Pickup/return report pending</span>
                                <span class="block text-xs text-gray-600">
                                    Rent #{{ $pRentalId }} — {{ $p->payload['customer_name'] ?? '' }}
                                </span>
                            @elseif ($pKind === 'payment_assign' && $pRentalId)
                                <span class="font-medium text-amber-900">Payment sent to a shift</span>
                                <span class="block text-xs text-gray-600">
                                    Rent #{{ $pRentalId }} — {{ $p->payload['customer_name'] ?? '' }} · shift #{{ $p->payload['shift_session_id'] ?? '' }}
                                </span>
                            @elseif ($pKind === 'free' && $pRentalId)
                                <span class="font-medium text-amber-900">Free rent pending</span>
                                <span class="block text-xs text-gray-600">
                                    Rent #{{ $pRentalId }} — {{ $p->payload['customer_name'] ?? '' }} · {{ $p->payload['reason'] ?? '' }}
                                </span>
                            @elseif($custPending)
                                <span class="font-medium text-amber-900">Items &amp; dates only</span>
                            @else
                                <span class="font-medium">{{ $pr['customer_name'] ?? '—' }}</span>
                                @if (!empty($pr['phone']))
                                    <span class="text-gray-600"> · {{ $pr['phone'] }}</span>
                                @endif
                            @endif

                            @if (count($showCodes))
                                <span
                                    class="block text-gray-800 mt-0.5 font-mono text-xs">{{ implode(', ', $showCodes) }}</span>
                            @endif

                            @if (count($otherCodes))
                                <span class="block text-gray-500 mt-0.5 text-xs">
                                    Also includes: {{ implode(', ', $otherCodes) }}
                                </span>
                            @endif

                            <span class="block text-gray-600 mt-0.5">
                                @if (! in_array($pKind, ['free', 'payment_assign', 'late_done'], true)){{ $pr['start_date'] ?? '' }}–{{ $pr['end_date'] ?? '' }} ·@endif
                                {{ $p->created_at?->diffForHumans() ?? '' }}
                            </span>
                        </li>
                        @if ($pKind === 'edit' && $pRentalId)
                            @php
                                $pSavedRental = \App\Models\Rental::withoutGlobalScope('tutorial_scope')->find($pRentalId);
                                $pFieldDiff = [];
                                $pChangedFieldKeys = [];
                                $pChangedItemDiscountOutfitIds = [];
                                $pFieldDiffByKey = [];
                                $pItemDiscountDiffByOutfitId = [];
                                if ($pSavedRental) {
                                    $pDiff = $p->diffAgainstSavedRental($pSavedRental, $codes);
                                    $pFieldDiff = array_values(array_filter($pDiff['fields'], fn ($d) => $d['changed']));
                                    if ($pDiff['items']) {
                                        $pFieldDiff[] = $pDiff['items'];
                                    }
                                    $pFieldDiff = array_merge($pFieldDiff, $pDiff['item_discounts']);
                                    $pChangedFieldKeys = $pDiff['changed_field_keys'];
                                    $pFieldDiffByKey = $pDiff['fields_by_key'];
                                    $pChangedItemDiscountOutfitIds = $pDiff['item_discount_outfit_ids'];
                                    $pItemDiscountDiffByOutfitId = $pDiff['item_discounts_by_outfit_id'];
                                }
                            @endphp
                            <template id="pending-edit-diff-{{ $p->id }}"
                                data-changed-fields="{{ json_encode($pChangedFieldKeys) }}"
                                data-changed-item-discount-outfit-ids="{{ json_encode($pChangedItemDiscountOutfitIds) }}"
                                data-changed-fields-detail="{{ json_encode($pFieldDiffByKey) }}"
                                data-changed-item-discount-detail="{{ json_encode($pItemDiscountDiffByOutfitId) }}">
                                @if (count($pFieldDiff))
                                    <div class="space-y-1">
                                        @foreach ($pFieldDiff as $pd)
                                            <div><span class="text-gray-600">{{ $pd['label'] }}:</span> <span class="text-gray-400 line-through">{{ $pd['old'] }}</span> <span class="text-emerald-700 font-semibold">→ {{ $pd['new'] }}</span></div>
                                        @endforeach
                                    </div>
                                @else
                                    <div class="text-gray-500">No changes to show yet.</div>
                                @endif
                            </template>
                        @endif
                    @endforeach
                </ul>
            </div>

            @if (Auth::user()->isManagement() || Auth::user()->isSuperAdmin())
                <form method="GET" action="{{ route('rentals.index') }}"
                    class="mb-4 flex items-center gap-2 text-sm text-gray-700">
                    @foreach (request()->except('all_stores') as $k => $v)
                        @if (is_array($v))
                            @foreach ($v as $vv)
                                <input type="hidden" name="{{ $k }}[]" value="{{ $vv }}">
                            @endforeach
                        @else
                            <input type="hidden" name="{{ $k }}" value="{{ $v }}">
                        @endif
                    @endforeach
                    <input type="hidden" name="all_stores" value="0">
                    <input type="checkbox" id="rentalsAllStoresToggle" name="all_stores" value="1"
                        {{ $allStoresMode ?? false ? 'checked' : '' }} onchange="this.form.submit()"
                        class="h-4 w-4 rounded border-gray-400 text-indigo-600 shadow-none focus:ring-indigo-500 cursor-pointer">
                    <label for="rentalsAllStoresToggle" class="cursor-pointer">All stores</label>
                </form>
            @endif

            <div id="shiftControlHost">
                @include('rentals.partials.shift-control', [
                    'currentShift' => $currentShift,
                    'shiftStats' => $shiftStats,
                    'lastClosedShift' => $lastClosedShift,
                    'rentalMutationsLocked' => $rentalMutationsLocked,
                    'mayOperateRentTransactions' => $mayOperateRentTransactions,
                ])
            </div>

            @if (($deletedMode ?? false))
                <div class="mb-4 rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-900">
                    <span class="font-semibold">Deleted transactions.</span>
                    You are viewing soft-deleted rents. They no longer affect availability or reports.
                    Use the buttons below the filters to permanently remove them.
                </div>
            @endif

            @if ($mayOperateRentTransactions && ! ($deletedMode ?? false))
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                    <div class="p-4 sm:p-6">
                        <div class="flex items-center justify-between mb-4">
                            <h3 class="text-lg font-medium text-gray-900">New Rent</h3>
                            <a href="{{ route('rentals.index') }}" target="_blank"
                                class="px-3 py-1.5 bg-indigo-600 text-white rounded-md text-xs font-semibold hover:bg-indigo-700 transition cursor-pointer">
                                + New Tab
                            </a>
                        </div>

                        <form method="POST" action="{{ route('rentals.store') }}" id="rentalForm" novalidate>
                            @csrf
                            <fieldset
                                id="rentalFormFieldset"
                                class="border-0 p-0 m-0 min-w-0 @if ($rentalMutationsLocked) opacity-60 @endif"
                                @if ($rentalMutationsLocked) disabled @endif>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div id="newRentStepDates" class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-2 gap-4">
                                        <div class="sm:col-span-2 rounded-lg border border-indigo-100 bg-indigo-50/60 px-4 py-3">
                                            <p class="text-sm font-semibold text-indigo-900">1. Pick rent dates first</p>
                                            <p class="mt-1 text-sm text-indigo-800 leading-snug">
                                                Enter <strong>start</strong> and <strong>end</strong> date below. Item
                                                search and
                                                the list stay off until both are set—availability is based on that period.
                                            </p>
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                                            <input type="date" name="start_date" id="rsd"
                                                value="{{ old('start_date') }}" required min="{{ now()->toDateString() }}"
                                                class="form-input w-full">
                                            @error('start_date')
                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>

                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                                            <input type="date" name="end_date" id="red"
                                                value="{{ old('end_date') }}" required min="{{ now()->toDateString() }}"
                                                class="form-input w-full">
                                            @error('end_date')
                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">2. Items</label>
                                        <div id="outfitHint"
                                            class="mb-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 shadow-sm"
                                            role="status">
                                            <span class="font-semibold text-amber-900">Dates required.</span>
                                            <span class="text-amber-900/90">
                                                Choose start and end date above—then search and add items here.
                                            </span>
                                        </div>

                                        <div class="relative mb-2">
                                            <span
                                                class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400"
                                                aria-hidden="true">
                                                <svg class="h-4 w-4" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2"
                                                        d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                                </svg>
                                            </span>
                                            <input type="text" id="outfitSearch" placeholder="Search items..."
                                                disabled autocomplete="off"
                                                class="form-input-search h-10 py-0 w-full pl-9 pr-9">
                                            <button type="button" id="outfitSearchClear"
                                                class="hidden absolute right-1.5 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-gray-500 hover:text-gray-900 hover:bg-gray-100 cursor-pointer focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                                aria-label="Clear search" title="Clear search">
                                                <svg class="h-4 w-4 block" fill="none" stroke="currentColor"
                                                    viewBox="0 0 24 24" aria-hidden="true">
                                                    <path stroke-linecap="round" stroke-linejoin="round"
                                                        stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                </svg>
                                            </button>
                                        </div>

                                        <div id="outfitFilterTabs" class="flex gap-1 mb-2 hidden">
                                            <button type="button" onclick="setOutfitFilter('all', this)"
                                                class="px-2.5 py-1 text-xs rounded-md cursor-pointer font-medium border border-indigo-600 bg-white text-gray-900 font-semibold">
                                                All
                                            </button>
                                            <button type="button" onclick="setOutfitFilter('available', this)"
                                                class="px-2.5 py-1 text-xs rounded-md cursor-pointer font-medium border border-gray-400 bg-gray-50 text-gray-600 hover:text-gray-900">
                                                Available
                                            </button>
                                        </div>

                                        @if ($mayMutateItems)
                                            <div class="mb-2">
                                                <button type="button" id="toggleQuickAddCreateBtn"
                                                    onclick="toggleSection('quickAddCreatePanel')"
                                                    class="inline-flex items-center gap-1 text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                                                    <svg class="h-4 w-4 shrink-0" fill="none"
                                                        stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                                        <path stroke-linecap="round" stroke-linejoin="round"
                                                            stroke-width="2" d="M12 4.5v15m7.5-7.5h-15" />
                                                    </svg>
                                                    Quick add new item code
                                                </button>

                                                <div id="quickAddCreatePanel"
                                                    class="hidden mt-2 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2.5 shadow-sm">
                                                    <div class="flex gap-2 items-start mb-2">
                                                        <span class="shrink-0 text-gray-500 mt-0.5"
                                                            aria-hidden="true">
                                                            <svg class="h-4 w-4" fill="none" stroke="currentColor"
                                                                viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    stroke-width="2"
                                                                    d="M12 16v-4m0-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                            </svg>
                                                        </span>
                                                        <p class="text-xs text-gray-600 leading-snug">
                                                            <kbd
                                                                class="px-1 py-0.5 rounded border border-gray-200 bg-white font-mono text-xs">Enter</kbd>
                                                            in Code, New code, or Description to submit.
                                                        </p>
                                                    </div>

                                                    <div class="flex flex-col sm:flex-row gap-2 sm:items-end">
                                                        <div class="flex-1 min-w-0">
                                                            <label for="quickAddCreateInput"
                                                                class="block text-xs font-medium text-gray-700 mb-0.5">Code</label>
                                                            <input type="text" id="quickAddCreateInput"
                                                                autocomplete="off" placeholder="e.g. OUTFIT-NEW-01"
                                                                class="form-input-compact w-full">
                                                        </div>
                                                        <div class="flex-1 min-w-0">
                                                            <label for="quickAddCreateNewCode"
                                                                class="block text-xs font-medium text-gray-700 mb-0.5">
                                                                New code <span
                                                                    class="font-normal text-gray-500">(optional)</span>
                                                            </label>
                                                            <input type="text" id="quickAddCreateNewCode"
                                                                autocomplete="off" placeholder="Alternate label"
                                                                class="form-input-compact w-full">
                                                        </div>
                                                        <button type="button" onclick="quickAddOutfit()"
                                                            class="inline-flex shrink-0 items-center gap-1 px-2.5 py-1.5 sm:mb-[2px] bg-indigo-600 text-white rounded text-xs font-medium hover:bg-indigo-700">
                                                            <svg class="h-3.5 w-3.5" fill="none"
                                                                stroke="currentColor" viewBox="0 0 24 24"
                                                                aria-hidden="true">
                                                                <path stroke-linecap="round" stroke-linejoin="round"
                                                                    stroke-width="2" d="M12 4.5v15m7.5-7.5h-15" />
                                                            </svg>
                                                            Add Item
                                                        </button>
                                                    </div>

                                                    <div class="mt-2">
                                                        <label for="quickAddCreateDescription"
                                                            class="block text-xs font-medium text-gray-700 mb-0.5">
                                                            Description <span
                                                                class="font-normal text-gray-500">(optional)</span>
                                                        </label>
                                                        <input type="text" id="quickAddCreateDescription"
                                                            autocomplete="off" maxlength="255"
                                                            placeholder="e.g. Black tuxedo, size M"
                                                            class="form-input-compact w-full">
                                                    </div>
                                                    <p class="text-xs text-gray-500 mt-1.5">Only for codes not already
                                                        in inventory.</p>
                                                </div>
                                            </div>
                                        @endif

                                        <div id="outfitList"
                                            class="max-h-48 overflow-y-auto border-2 border-gray-300 rounded-md p-1 bg-white">
                                            <div
                                                class="empty-outfit-list-hint rounded-md border border-amber-100 bg-amber-50/90 m-1 px-4 py-6 text-center text-sm text-amber-950">
                                                <p class="font-semibold">Select start &amp; end dates first</p>
                                                <p class="mt-1.5 text-xs text-amber-900/85 leading-relaxed">
                                                    The item list loads after both rent dates are filled in above.
                                                </p>
                                            </div>
                                        </div>

                                        @error('outfit_ids')
                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                        @enderror

                                        <div id="conflictInfo" class="hidden mt-2 text-sm"></div>

                                        <div id="cartSection" class="mt-4 hidden">
                                            <div class="border-t pt-4">
                                                <div class="flex items-center justify-between mb-2">
                                                    <h4 class="text-sm font-semibold text-gray-700">
                                                        Cart (<span id="cartCount">0</span>)
                                                    </h4>
                                                </div>

                                                <div id="cartItems" class="space-y-2"></div>

                                                @if ($pricing)
                                                    <div
                                                        class="mt-3 rounded-lg border border-gray-200 bg-gray-50/90 px-3 py-3 sm:px-4 space-y-3">
                                                        <p
                                                            class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
                                                            Order discount <span
                                                                class="font-normal normal-case text-gray-500">(optional)</span>
                                                        </p>
                                                        <div>
                                                            <label
                                                                class="block text-sm font-medium text-gray-700 mb-1">
                                                                Order discount
                                                            </label>
                                                            <div class="flex gap-2">
                                                                <input type="text" name="discount"
                                                                    id="discount" value="{{ old('discount') }}"
                                                                    inputmode="decimal" autocomplete="off"
                                                                    class="form-input w-full" placeholder="0">
                                                                <select name="discount_type" id="discountType"
                                                                    class="form-select shrink-0 min-w-[7rem]">
                                                                    <option value="fixed"
                                                                        {{ old('discount_type') == 'fixed' ? 'selected' : '' }}>
                                                                        Rp</option>
                                                                    <option value="percent"
                                                                        {{ old('discount_type') == 'percent' ? 'selected' : '' }}>
                                                                        %</option>
                                                                </select>
                                                            </div>
                                                            @error('discount')
                                                                <p class="text-red-500 text-xs mt-1">
                                                                    {{ $message }}</p>
                                                            @enderror
                                                        </div>
                                                        <div>
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                                Discount note
                                                                <span class="font-normal text-gray-500">(required if discount applied)</span>
                                                            </label>
                                                            <textarea name="discount_note" id="discountNote" rows="2"
                                                                maxlength="500"
                                                                class="form-input w-full{{ $errors->has('discount_note') ? ' !border-red-500 !ring-1 !ring-red-500' : '' }}"
                                                                placeholder="Reason for discount (e.g. regular customer, event promo)">{{ old('discount_note') }}</textarea>
                                                            @error('discount_note')
                                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                            @enderror
                                                        </div>
                                                        <p class="text-xs text-gray-500">
                                                            Order discount applies before tax. A note is required when applying any discount.
                                                        </p>
                                                        <div id="createExtraChargeRow" class="hidden">
                                                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                                                Extra day charge
                                                                <span id="createExtraDaysLabel" class="ml-1 font-normal text-gray-500 text-xs"></span>
                                                            </label>
                                                            <input type="text" name="extra_charge" id="createExtraCharge"
                                                                value="{{ old('extra_charge') }}" inputmode="decimal" autocomplete="off"
                                                                class="form-input w-full" placeholder="0">
                                                            <p class="mt-1 text-xs text-gray-500">Additional charge for the extra days beyond the 3-day blocks. Added to the total after block pricing.</p>
                                                            @error('extra_charge')
                                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                            @enderror
                                                        </div>
                                                    </div>
                                                @endif

                                                <div id="cartTotalsHost"></div>
                                                <p id="cartEmpty" class="text-sm text-gray-400 text-center py-4">No
                                                    items selected</p>
                                            </div>
                                        </div>

                                        @if ($pricing)
                                            <div id="newRentStepPayment"
                                                class="mt-2 rounded-lg border border-indigo-100 bg-white p-3 ring-1 ring-indigo-50">
                                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 mb-3">
                                                    <p
                                                        class="text-xs font-semibold uppercase tracking-wide text-indigo-700">
                                                        Payment &amp; settlement</p>
                                                    <span class="text-xs text-gray-500">Follows cart totals.</span>
                                                </div>
                                                <div class="space-y-3">
                                                    <div
                                                        class="grid grid-cols-1 md:grid-cols-[auto_1fr_1fr] gap-3 md:items-stretch">
                                                        <div class="flex flex-col gap-1.5 min-h-0">
                                                            <p
                                                                class="text-[calc(0.75rem-1px)] font-medium text-gray-600 shrink-0">
                                                                Settlement</p>
                                                            <label
                                                                class="flex items-center gap-2 cursor-pointer text-xs text-gray-800">
                                                                <input type="radio" name="payment_kind"
                                                                    value="full" id="createPaymentKindFull"
                                                                    class="border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                                    {{ old('payment_kind', 'full') === 'full' ? 'checked' : '' }}>
                                                                Full payment
                                                            </label>
                                                            <label
                                                                class="flex items-center gap-2 cursor-pointer text-xs text-gray-800">
                                                                <input type="radio" name="payment_kind"
                                                                    value="down_payment"
                                                                    id="createPaymentKindDown"
                                                                    class="border-gray-300 text-indigo-600 focus:ring-indigo-500"
                                                                    {{ old('payment_kind') === 'down_payment' ? 'checked' : '' }}>
                                                                Down payment
                                                            </label>
                                                            @error('payment_kind')
                                                                <p class="text-red-500 text-xs">{{ $message }}</p>
                                                            @enderror
                                                        </div>
                                                        <div class="flex flex-col gap-2 min-h-0">
                                                            <label
                                                                class="block text-[calc(0.75rem-1px)] font-medium text-gray-600 shrink-0">
                                                                Payment method
                                                            </label>
                                                            <select name="payment_method" id="createPaymentMethod"
                                                                class="form-select w-full h-10 py-0 shrink-0">
                                                                <option value="">- Select -</option>
                                                                <option value="cash"
                                                                    {{ old('payment_method') === 'cash' ? 'selected' : '' }}>
                                                                    Cash</option>
                                                                <option value="transfer"
                                                                    {{ old('payment_method') === 'transfer' ? 'selected' : '' }}>
                                                                    Transfer</option>
                                                                <option value="qris"
                                                                    {{ old('payment_method') === 'qris' ? 'selected' : '' }}>
                                                                    QRIS</option>
                                                                <option value="edc"
                                                                    {{ old('payment_method') === 'edc' ? 'selected' : '' }}>
                                                                    EDC</option>
                                                                <option value="other"
                                                                    {{ old('payment_method') === 'other' ? 'selected' : '' }}>
                                                                    Other</option>
                                                            </select>
                                                            @error('payment_method')
                                                                <p class="text-red-500 text-xs shrink-0">
                                                                    {{ $message }}</p>
                                                            @enderror
                                                        </div>
                                                        <div class="flex flex-col gap-2 min-h-0">
                                                            <label
                                                                class="block text-[calc(0.75rem-1px)] font-medium text-gray-600 shrink-0"
                                                                id="createAmountPaidLabel" for="amountPaid">
                                                                Amount paid
                                                            </label>
                                                            <input type="text" name="amount_paid" id="amountPaid"
                                                                value="{{ old('amount_paid') }}" inputmode="decimal"
                                                                autocomplete="off"
                                                                class="form-input w-full h-10 py-0 shrink-0"
                                                                placeholder="e.g. 200.000">
                                                            @error('amount_paid')
                                                                <p class="text-red-500 text-xs shrink-0">
                                                                    {{ $message }}</p>
                                                            @enderror
                                                            <div class="flex-1 min-h-[1.5rem] md:min-h-0"></div>
                                                        </div>
                                                    </div>

                                                    <div
                                                        class="flex flex-col gap-2 w-full min-w-0 pt-3 border-t border-indigo-100/90">
                                                        <div id="createDueSummaryPanel"
                                                            class="rounded-md border border-indigo-200/90 bg-gradient-to-b from-indigo-50/90 to-white px-3 py-2.5 shadow-inner w-full flex flex-col gap-1 shrink-0">
                                                            <div class="flex w-full items-baseline justify-between gap-4 min-w-0"
                                                                title="Total due for the current cart">
                                                                <span
                                                                    class="text-sm font-semibold text-gray-900 shrink-0">
                                                                    Total due <span id="createDueInclTaxLabel"
                                                                        class="font-normal text-gray-500 font-medium">(incl.
                                                                        tax)</span>
                                                                </span>
                                                                <span id="createDueInlineDisplay"
                                                                    class="text-[calc(1.125rem-3px)] sm:text-[calc(1.25rem-3px)] font-bold text-indigo-950 tabular-nums shrink-0 text-right"
                                                                    aria-live="polite">
                                                                    —
                                                                </span>
                                                            </div>
                                                            <p id="createPaymentBalanceDisplay"
                                                                class="text-sm font-medium tabular-nums min-h-[1.25rem] text-gray-700 leading-snug border-t border-indigo-100/80 pt-2 mt-0.5"
                                                                aria-live="polite"></p>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        @else
                                            <div
                                                class="mt-2 max-w-md rounded-lg border border-gray-200 bg-gray-50/90 px-3 py-2">
                                                <label
                                                    class="block text-[calc(0.75rem-1px)] font-medium text-gray-600 mb-0.5">Payment
                                                    method</label>
                                                <select name="payment_method" id="createPaymentMethod"
                                                    class="form-select w-full">
                                                    <option value="">- Select payment method -</option>
                                                    <option value="cash"
                                                        {{ old('payment_method') === 'cash' ? 'selected' : '' }}>Cash
                                                    </option>
                                                    <option value="transfer"
                                                        {{ old('payment_method') === 'transfer' ? 'selected' : '' }}>
                                                        Transfer</option>
                                                    <option value="qris"
                                                        {{ old('payment_method') === 'qris' ? 'selected' : '' }}>QRIS
                                                    </option>
                                                    <option value="edc"
                                                        {{ old('payment_method') === 'edc' ? 'selected' : '' }}>EDC
                                                    </option>
                                                    <option value="other"
                                                        {{ old('payment_method') === 'other' ? 'selected' : '' }}>Other
                                                    </option>
                                                </select>
                                                @error('payment_method')
                                                    <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                                @enderror
                                            </div>
                                        @endif
                                    </div>

                                    <div id="newRentStepCustomer" class="sm:col-span-2 grid grid-cols-1 sm:grid-cols-3 gap-4">
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Customer
                                                Name</label>
                                            <input type="text" name="customer_name" id="rentCustomerName"
                                                value="{{ old('customer_name') }}" required
                                                placeholder="e.g. John Doe" class="form-input w-full">
                                            @error('customer_name')
                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div>
                                            <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                            <input type="text" name="phone" id="rentPhone" value="{{ old('phone') }}"
                                                required placeholder="e.g. 0812-3456-7890" class="form-input w-full">
                                            @error('phone')
                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>
                                        <div>
                                            <label
                                                class="block text-sm font-medium text-gray-700 mb-1">Instagram</label>
                                            <input type="text" name="instagram" value="{{ old('instagram') }}"
                                                placeholder="@username (optional)" class="form-input w-full">
                                            @error('instagram')
                                                <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                            @enderror
                                        </div>
                                    </div>

                                    <div class="sm:col-span-2">
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Notes <span class="font-normal text-gray-400">(optional)</span></label>
                                        <textarea name="notes" placeholder="Address / notes (optional)"
                                            class="form-input w-full min-h-[44px]">{{ old('notes', old('address')) }}</textarea>
                                        @error('notes')
                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                        @enderror
                                    </div>

                                    <div id="cooldownApprovalSummary"
                                        class="hidden sm:col-span-2 rounded-lg border border-amber-300 bg-amber-50/90 px-4 py-3">
                                        <p class="text-xs font-medium text-amber-900 uppercase tracking-wide">Approval
                                            request</p>
                                        <dl class="mt-2 space-y-2 text-sm">
                                            <div>
                                                <dt class="text-xs text-amber-800/90">Dates</dt>
                                                <dd id="cooldownApprovalDates"
                                                    class="font-medium text-amber-950 tabular-nums">—</dd>
                                            </div>
                                            <div>
                                                <dt class="text-xs text-amber-800/90">Items</dt>
                                                <dd id="cooldownApprovalItems"
                                                    class="font-mono text-sm text-amber-950 break-words">—</dd>
                                            </div>
                                        </dl>
                                        <p class="text-xs text-amber-950/90 leading-snug mt-3">
                                            Customer details are not required for this step. After management approves,
                                            open the rent and complete customer info.
                                        </p>
                                    </div>

                                    <div class="sm:col-span-2 flex flex-col gap-2">
                                        <button type="submit" id="btnCreateRental"
                                            class="w-full inline-flex items-center justify-center px-6 py-2.5 bg-indigo-600 border border-transparent rounded-md font-semibold text-sm text-white uppercase tracking-widest hover:bg-indigo-700 focus:outline-none transition cursor-pointer">
                                            Create Rent
                                        </button>
                                        <button type="button" id="btnCooldownApprovalOnly"
                                            data-action-url="{{ route('rentals.cooldown-approval-request') }}"
                                            class="hidden w-full inline-flex items-center justify-center px-6 py-2.5 rounded-md border-2 border-amber-600 bg-amber-600 text-sm font-semibold text-white uppercase tracking-widest hover:bg-amber-700 hover:border-amber-700 focus:outline-none transition cursor-pointer @if ($rentalMutationsLocked) opacity-50 cursor-not-allowed @endif"
                                            @if ($rentalMutationsLocked) disabled @endif>
                                            Request Management Approval
                                        </button>
                                        <div id="createErrorSummary"
                                            class="hidden mt-2 rounded-lg border border-red-200 bg-red-50/90 px-3 py-2.5 text-sm text-red-900 shadow-sm"
                                            role="alert"></div>
                                    </div>
                                </div>
                            </fieldset>
                        </form>

                        <div class="mt-4 pt-4 border-t">
                            <button type="button" onclick="toggleSection('bulkImportForm')"
                                class="text-sm text-indigo-600 hover:text-indigo-800 font-medium cursor-pointer @if ($rentalMutationsLocked) opacity-50 cursor-not-allowed @endif"
                                @if ($rentalMutationsLocked) disabled @endif>
                                + Bulk Import Rents
                            </button>
                            <div id="bulkImportForm" class="hidden mt-2">
                                <form method="POST" action="{{ route('rentals.bulk-import') }}">
                                    @csrf
                                    <fieldset
                                        class="border-0 p-0 m-0 min-w-0 @if ($rentalMutationsLocked) opacity-60 @endif"
                                        @if ($rentalMutationsLocked) disabled @endif>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">
                                            Paste rent data (tab separated)
                                        </label>
                                        <p class="text-xs text-gray-500 mb-2">
                                            Columns: Customer Name, Phone, Pickup Date, Return Date, Outfit Code
                                        </p>
                                        <textarea name="data" rows="8" class="form-textarea w-full font-mono"
                                            placeholder="Jane Doe&#9;62812345678&#9;2026-05-07&#9;2026-05-09&#9;OUTFIT-001">{{ old('data') }}</textarea>
                                        @error('data')
                                            <p class="text-red-500 text-xs mt-1">{{ $message }}</p>
                                        @enderror
                                        <button type="submit"
                                            class="mt-2 px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer">
                                            Import Rents
                                        </button>
                                    </fieldset>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-4 sm:p-6">
                    <div class="mb-4 rounded-2xl border border-gray-200 bg-gray-50/80 p-4 sm:p-5 shadow-sm">
                        <div class="flex flex-col gap-4">
                            <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                                <div class="flex flex-wrap items-center gap-4 min-w-0">
                                    <h3 class="text-lg font-semibold text-gray-900 tracking-tight">All Rents</h3>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <label for="rentStatusFilter"
                                            class="text-sm font-medium text-gray-700 whitespace-nowrap">Show</label>
                                        <select id="rentStatusFilter"
                                            class="form-select h-10 min-w-[12rem] max-w-full"
                                            aria-label="Rent status filter" onchange="applyRentFilter()">
                                            <option value="">All</option>
                                            <option value="active">Rented</option>
                                            <option value="returned">Return</option>
                                            <option value="overdue">Overdue</option>
                                            <option value="pickup_waiting">Waiting pickup</option>
                                            <option value="pickup_done">Picked up</option>
                                            <option value="payment_down_payment">Down payment</option>
                                            <option value="payment_paid_off">Full Payment</option>
                                        </select>
                                    </div>
                                </div>

                                <div
                                    class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
                                    <div
                                        class="flex flex-col gap-2 sm:flex-row sm:items-center rounded-xl border border-gray-300 bg-white p-3 shadow-sm transition-colors hover:border-gray-400 focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-500/10">
                                        <span class="text-sm font-medium text-gray-700 whitespace-nowrap">Dates</span>
                                        <label class="sr-only" for="rentDateFrom">From</label>
                                        <input type="date" id="rentDateFrom"
                                            class="form-input h-10 py-0 w-full sm:w-[9.5rem]" aria-label="From date">
                                        <span class="hidden sm:inline text-sm text-gray-400">—</span>
                                        <label class="sr-only" for="rentDateTo">To</label>
                                        <input type="date" id="rentDateTo"
                                            class="form-input h-10 py-0 w-full sm:w-[9.5rem]" aria-label="To date">
                                        <button type="button" id="rentDateClearBtn"
                                            class="h-10 px-3 rounded-lg border border-gray-300 bg-white text-sm font-medium text-gray-700 shadow-sm transition-colors hover:bg-gray-50 hover:border-gray-400 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/25">
                                            Clear
                                        </button>
                                    </div>
                                    @if (\App\Support\DebugTools::enabled())
                                        <div
                                            class="flex flex-col gap-2 sm:flex-row sm:items-center rounded-xl border border-dashed border-amber-400 bg-amber-50 p-3 shadow-sm">
                                            <span class="text-sm font-medium text-amber-800 whitespace-nowrap">Created (debug)</span>
                                            <label class="sr-only" for="rentCreatedOn">Created on date</label>
                                            <input type="date" id="rentCreatedOn"
                                                class="form-input h-10 py-0 w-full sm:w-[9.5rem]" aria-label="Created on date">
                                        </div>
                                    @endif
                                </div>
                            </div>

                            <div class="w-full">
                                <label class="sr-only" for="searchInput">Search rents</label>
                                <div class="relative w-full min-w-[16rem]">
                                    <span
                                        class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400"
                                        aria-hidden="true">
                                        <svg class="h-4 w-4" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                        </svg>
                                    </span>
                                    <input type="text" id="searchInput" value="{{ request('search') }}"
                                        placeholder="Search name, phone, item…"
                                        class="form-input-search h-10 py-0 w-full pl-9 pr-9" autocomplete="off">
                                    <button type="button" id="searchInputClear"
                                        class="hidden absolute right-1.5 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-gray-500 hover:text-gray-900 hover:bg-gray-100 cursor-pointer focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-indigo-500/25"
                                        aria-label="Clear search" title="Clear search">
                                        <svg class="h-4 w-4 block" fill="none" stroke="currentColor"
                                            viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                                d="M6 18L18 6M6 6l12 12" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-3 items-center">
                                <div
                                    class="flex items-center gap-3 shrink-0 rounded-xl transition-colors hover:border-gray-400 focus-within:border-indigo-500 focus-within:ring-2 focus-within:ring-indigo-500/10">
                                    <label class="sr-only" for="perPage">Results per page</label>
                                    <select id="perPage" class="form-select w-full sm:w-auto sm:min-w-[6.5rem]">
                                        <option value="10" {{ request('per_page') == 10 ? 'selected' : '' }}>
                                            10/page</option>
                                        <option value="25" {{ request('per_page') == 25 ? 'selected' : '' }}>
                                            25/page</option>
                                        <option value="50" {{ request('per_page') == 50 ? 'selected' : '' }}>
                                            50/page</option>
                                    </select>
                                    <span class="text-sm text-gray-600 tabular-nums whitespace-nowrap min-w-[7rem]"
                                        id="resultCount" aria-live="polite"></span>
                                </div>
                                <span id="offlineSyncIndicator"
                                    class="hidden w-fit justify-self-start md:justify-self-end text-[11px] leading-4 px-2 py-1 rounded-full border whitespace-nowrap"
                                    aria-live="polite"></span>
                            </div>
                        </div>
                    </div>

                    <div id="rentalListToast" class="hidden mb-3 rounded-lg border px-4 py-3 text-sm shadow-sm"
                        role="status" aria-live="polite"></div>

                    @if (($deletedMode ?? false))
                        <div class="mb-3 flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between rounded-lg border border-red-200 bg-white px-4 py-3 shadow-sm">
                            <label class="flex items-center gap-2 text-sm font-medium text-gray-700 cursor-pointer">
                                <input type="checkbox" id="deletedSelectAll" class="rounded border-gray-300 text-red-600 focus:ring-red-500">
                                Select all on this page
                                <span id="deletedSelectedCount" class="text-xs text-gray-500"></span>
                            </label>
                            <div class="flex flex-wrap gap-2">
                                <button type="button" id="deletedBulkDeleteBtn"
                                    class="px-3 py-1.5 rounded-md border border-red-300 bg-white text-sm font-semibold text-red-700 hover:bg-red-50 transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed"
                                    disabled>
                                    Delete selected permanently
                                </button>
                                <button type="button" id="deletedClearAllBtn"
                                    class="px-3 py-1.5 rounded-md bg-red-600 text-sm font-semibold text-white hover:bg-red-700 transition cursor-pointer">
                                    Clear all deleted
                                </button>
                            </div>
                        </div>
                    @endif

                    <div class="space-y-3" id="rentalCards"></div>
                    <span id="rentals-approval-poll-anchor" class="hidden" aria-hidden="true"></span>

                    <div class="mt-4 grid grid-cols-1 sm:grid-cols-2 gap-3 items-center">
                        <div id="paginationInfo" class="text-sm text-gray-600"></div>
                        <div id="paginationLinks" class="sm:justify-self-end">
                            {{ $rentals->links() }}
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @include('rentals.partials.edit-modal')
    @include('rentals.partials.item-edit-modal')

    @include('rentals.partials.index-script')

    @vite('resources/js/pages/rentals/index.js')
</x-app-layout>
