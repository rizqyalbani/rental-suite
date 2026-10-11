<style>
    /* Plain CSS, not a Tailwind utility class: this project has two conflicting
       Tailwind versions installed and some utilities silently fail to compile
       (see the activity-log panel fix). A field the user has changed from its
       saved value gets a green highlight so it's obvious before Save/Submit. */
    #editForm .field-changed {
        border-color: #22c55e !important;
        background-color: #f0fdf4 !important;
        box-shadow: 0 0 0 1px #86efac;
    }
</style>
<div id="editModal" class="hidden fixed inset-0 bg-gray-900/50 flex items-center justify-center z-50 p-3 sm:p-4">
    @php
        $pricingPricesIncludeTax = (bool) \App\Support\StoreCache::get('pricing_prices_include_tax', false);
    @endphp
    <div id="editModalPanel" class="relative bg-white rounded-2xl shadow-xl max-w-6xl w-full max-h-[94vh] flex flex-col overflow-hidden">
        <div id="editModalLoadingOverlay" class="hidden absolute inset-0 z-30 flex flex-col items-center justify-center gap-5 rounded-2xl bg-white/92 backdrop-blur-[2px]" role="status" aria-live="polite" aria-busy="false">
            <div class="w-full max-w-[min(18rem,calc(100%-2rem))] px-6" aria-hidden="true">
                <div class="rental-loading-track mx-auto h-1 max-w-none rounded-full">
                    <div class="rental-loading-stripe"></div>
                </div>
            </div>
            <p class="text-sm font-medium text-gray-600">Loading rent details…</p>
        </div>
        <div id="editModalScroll" class="min-h-0 flex-1 overflow-y-auto p-4 sm:p-6">
        @if($adminTransactionEditMode ?? false)
        <div class="mb-3 rounded-lg border border-amber-400 bg-amber-50 px-3 py-2.5 text-xs text-amber-900 font-medium">
            Transaction edit mode is ON — payment and discount fields are unlocked for all transactions. <a href="{{ route('settings.index') }}" class="underline font-semibold">Turn it off in Settings</a> after fixing.
        </div>
        @endif
        @if(auth()->user()?->isAdmin())
        <div class="mb-3 rounded-lg border border-indigo-300 bg-indigo-50 px-3 py-2.5 text-xs text-indigo-900 font-medium">
            Admin edits need approval — saving here submits your changes to a supervisor or management for review. Nothing is applied until they approve it.
        </div>
        @endif
        <h3 id="editModalTitle" class="text-lg font-medium text-gray-900">Quick edit rent</h3>
        <div id="editFreeBadge" class="hidden mt-1 mb-2 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs text-emerald-900">
            <span class="px-2 py-0.5 mr-2 text-xs font-bold tracking-wide rounded-md bg-emerald-600 text-white">FREE</span>
            <span id="editFreeBadgeText"></span>
        </div>
        <p id="editModalSubtitle" class="text-xs text-gray-500 mb-4">Update dates, then manage items by tab: <b>Available</b> to add, <b>Rented</b> to switch. Selected items show code, description, stock, price, and per-item discount (same as new rent).</p>
        <form id="editForm" method="POST">
            @csrf @method('PUT')
            <input type="hidden" name="overdue_base_end_date" id="editOverdueBaseEndDate" value="">
            <input type="hidden" name="overdue_charge_through_date" id="editOverdueChargeThroughDate" value="">
            <input type="hidden" name="overdue_fee_rate" id="editOverdueFeeRate" value="">
            <input type="hidden" name="overdue_fee_days" id="editOverdueFeeDays" value="">
            <input type="hidden" name="overdue_fee_amount" id="editOverdueFeeAmount" value="">
            <div id="editErrorSummary" class="hidden mb-3 rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-700"></div>
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <div class="lg:col-span-2 space-y-4">
                    <section class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 mb-2">Step 1 - Rent Period</p>
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Start Date</label>
                                <input type="date" name="start_date" id="editSd" required
                                    min="{{ now()->toDateString() }}"
                                    class="form-input w-full">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">End Date</label>
                                <input type="date" name="end_date" id="editEd" required
                                    min="{{ now()->toDateString() }}"
                                    class="form-input w-full">
                            </div>
                        </div>
                        <div id="editDateHint" class="mt-3 rounded-md border border-amber-200 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 shadow-sm" role="status">
                            <span class="font-semibold text-amber-900">Dates required.</span>
                            <span class="text-amber-900/90"> Set start and end date above before adding or changing items—availability updates from those dates.</span>
                        </div>
                    </section>

                    <section class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 mb-2">Step 2 - Items</p>
                        <p class="text-xs text-indigo-900/80 mb-2 rounded-md bg-indigo-50/80 border border-indigo-100 px-2.5 py-1.5">Items stay disabled until <strong>both</strong> dates in Step 1 are set.</p>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Items in rent</label>
                        <div class="relative mb-2">
                            <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                                <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                            </span>
                            <input type="text" id="editOutfitSearch" placeholder="Search items..." disabled autocomplete="off"
                                class="form-input-search h-10 py-0 w-full pl-9 pr-9">
                            <button type="button" id="editOutfitSearchClear"
                                class="hidden absolute right-1.5 top-1/2 -translate-y-1/2 rounded-md p-1.5 text-gray-500 hover:text-gray-900 hover:bg-gray-100 cursor-pointer focus:outline-none focus:ring-1 focus:ring-indigo-500"
                                aria-label="Clear search" title="Clear search">
                                <svg class="h-4 w-4 block" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                        <div id="editOutfitFilterTabs" class="flex gap-1 mb-2 hidden">
                             <button type="button" onclick="setEditOutfitFilter('all', this)" class="px-2.5 py-1 text-xs rounded-md cursor-pointer font-medium border border-gray-400 bg-gray-50 text-gray-600 hover:bg-gray-100">All Items</button>
                             <button type="button" onclick="setEditOutfitFilter('available', this)" class="px-2.5 py-1 text-xs rounded-md cursor-pointer font-medium border border-gray-400 bg-gray-50 text-gray-600 hover:bg-gray-100">Available to Add</button>
                             <button type="button" onclick="setEditOutfitFilter('rented', this)" class="px-2.5 py-1 text-xs rounded-md cursor-pointer font-medium border border-gray-400 bg-gray-50 text-gray-600 hover:bg-gray-100">Rented (Switch)</button>
                        </div>
                        <p class="text-xs text-gray-500 mb-2">Tip: use <b>Switch This Item</b> in Rented tab, or remove/add from Available tab.</p>
                        <div id="editOutfitCheckboxList" class="max-h-56 overflow-y-auto border border-gray-300 rounded-md p-2 space-y-1 bg-white">
                            <div class="empty-outfit-list-hint rounded-md border border-amber-100 bg-amber-50/90 px-4 py-6 text-center text-sm text-amber-950">
                                <p class="font-semibold">Set Step 1 dates first</p>
                                <p class="mt-1.5 text-xs text-amber-900/85 leading-relaxed">Choose start and end date above to load items for this rent.</p>
                            </div>
                        </div>
                        <div id="editConflictInfo" class="hidden mt-2 text-sm"></div>
                        <div id="editCooldownPendingBanner" class="hidden mt-3 rounded-lg border border-amber-400 bg-amber-50 px-3 py-2.5 text-sm text-amber-950 shadow-sm" role="status">
                            <p class="font-semibold text-amber-950">Waiting for a manager to approve</p>
                            <p class="text-xs mt-1 text-amber-900/90 leading-relaxed">This rent won't change until then. <a href="#" id="editCooldownPendingDetailsToggle" class="underline font-medium">Why?</a></p>
                            <div id="editCooldownPendingDetails" class="hidden mt-1.5 text-xs text-amber-900/80 leading-relaxed border-t border-amber-200 pt-1.5">You can keep editing here, but nothing is saved to the rent yet — it stays as it was until a manager approves or rejects this request. This message will disappear once they decide.</div>
                            <div id="editCancelPendingWrap" class="hidden mt-2">
                                <button type="button" id="editCancelPendingBtn" class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 transition cursor-pointer">
                                    <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                    Cancel request
                                </button>
                            </div>
                        </div>
                    </section>

                    <section class="rounded-xl border border-gray-200 bg-gray-50 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600 mb-2">Step 3 - Customer Details</p>
                            <div class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Customer Name</label>
                                <input type="text" name="customer_name" id="editCust" required
                                    placeholder="e.g. John Doe"
                                    class="form-input w-full">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phone</label>
                                <input type="text" name="phone" id="editPhone" required
                                    placeholder="e.g. 0812-3456-7890"
                                    class="form-input w-full">
                            </div>
                            <div id="editHandledByRow" class="hidden">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Handled by</label>
                                <p id="editHandledByDisplay" class="text-sm text-gray-900 bg-gray-50 border border-gray-200 rounded-md px-3 py-2"></p>
                            </div>
                            <div id="editCreatedByRow" class="hidden">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Created by</label>
                                <p id="editCreatedByDisplay" class="text-sm text-gray-900 bg-gray-50 border border-gray-200 rounded-md px-3 py-2"></p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Instagram</label>
                                <input type="text" name="instagram" id="editIg"
                                    placeholder="@username (optional)"
                                    class="form-input w-full">
                            </div>
                            <div id="editStepNotes">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Notes <span class="font-normal text-gray-400">(optional)</span></label>
                                <textarea name="notes" id="editNotes" placeholder="Address / notes (optional)"
                                    class="form-input w-full min-h-[44px]"></textarea>
                            </div>
                        </div>
                    </section>
                </div>

                <div class="space-y-4">
                    <section id="editCartSection" class="rounded-xl border border-gray-200 bg-white p-4 hidden">
                        <div id="editCartSectionHeader" class="flex items-center justify-between mb-2">
                            <h4 class="text-sm font-semibold text-gray-700">Selected items — full detail (<span id="editCartCount">0</span>)</h4>
                            <div class="flex items-center gap-2">
                                <button type="button" id="clearEditCart" class="text-xs text-gray-500 hover:text-gray-700">Clear All</button>
                            </div>
                        </div>
                        <div id="editOutfitCart" class="space-y-2"></div>
                        @if($pricing)
                        <div class="mt-3 rounded-lg border border-gray-200 bg-gray-50/90 px-3 py-3 sm:px-4 space-y-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">Order discount <span class="font-normal normal-case text-gray-500">(optional)</span></p>
                            <div class="space-y-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Order discount</label>
                                    <div class="flex gap-2">
                                        <input type="text" name="discount" id="editDiscount" inputmode="decimal" autocomplete="off"
                                            class="form-input w-full" placeholder="0">
                                        <select name="discount_type" id="editDiscountType"
                                            class="form-select shrink-0 min-w-[7rem]">
                                            <option value="fixed">Rp</option>
                                            <option value="percent">%</option>
                                        </select>
                                    </div>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">
                                        Discount note
                                        <span class="font-normal text-gray-500">(required if discount applied)</span>
                                    </label>
                                    <textarea name="discount_note" id="editDiscountNote" rows="2" maxlength="500"
                                        class="form-input w-full" placeholder="Reason for discount (e.g. regular customer, event promo)"></textarea>
                                    <p id="editDiscountNoteError" class="text-red-500 text-xs mt-1 hidden"></p>
                                </div>
                            </div>
                            <p class="text-xs text-gray-500">Order discount applies before tax. A note is required when applying any discount.</p>
                            <div id="editExtraChargeRow" class="hidden">
                                <label class="block text-sm font-medium text-gray-700 mb-1">
                                    Extra day charge
                                    <span id="editExtraDaysLabel" class="ml-1 font-normal text-gray-500 text-xs"></span>
                                </label>
                                <input type="text" name="extra_charge" id="editExtraCharge" inputmode="decimal" autocomplete="off"
                                    class="form-input w-full" placeholder="0">
                                <p class="mt-1 text-xs text-gray-500">Additional charge for the extra days beyond the 3-day blocks. Entered by admin — added to the total after block pricing.</p>
                            </div>
                        </div>
                        @endif
                        <div id="editCartTotalsHost"></div>
                        <p id="editCartEmpty" class="text-sm text-gray-400 text-center py-3">No items selected yet</p>
                    </section>

                    @if($pricing)
                    <section class="rounded-lg border border-indigo-100 bg-white p-3 ring-1 ring-indigo-50">
                        <div class="flex flex-wrap items-center gap-x-2 gap-y-1 mb-3">
                            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-700">Payment &amp; settlement</p>
                            <span id="editPaymentEditableHint" class="hidden inline-flex items-center text-indigo-600" title="You can edit payment for this rent" role="img" aria-label="Payment editable">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="w-4 h-4 shrink-0" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" />
                                </svg>
                            </span>
                            <span class="text-xs text-gray-500 sm:ml-1">Follows cart totals.</span>
                        </div>
                        <div class="space-y-3">
                            <div id="editPaymentLockedBanner" class="hidden rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-950 leading-snug" role="status"></div>
                            <div class="flex flex-col gap-4">
                                <div class="flex flex-col gap-2 min-h-0">
                                    <label class="block text-[calc(0.75rem-1px)] font-medium text-gray-600 shrink-0">Rent payment method</label>
                                    <select name="payment_method" id="editPaymentMethod" class="form-select w-full h-10 py-0 shrink-0">
                                        <option value="">- Select -</option>
                                        <option value="cash">Cash</option>
                                        <option value="transfer">Transfer</option>
                                        <option value="qris">QRIS</option>
                                        <option value="edc">EDC</option>
                                        <option value="other">Other</option>
                                    </select>
                                    <div id="editPaymentKindSettlement" class="pt-2 mt-1 border-t border-gray-100 space-y-1.5 shrink-0">
                                        <p class="text-xs font-medium text-gray-500 uppercase tracking-wide">Settlement</p>
                                        <label class="flex items-center gap-2 cursor-pointer text-xs text-gray-800">
                                            <input type="radio" name="payment_kind" value="full" id="editPaymentKindFull"
                                                class="border-gray-300 text-indigo-600 focus:ring-indigo-500" checked>
                                            Full payment
                                        </label>
                                        <label class="flex items-center gap-2 cursor-pointer text-xs text-gray-800">
                                            <input type="radio" name="payment_kind" value="down_payment" id="editPaymentKindDown"
                                                class="border-gray-300 text-indigo-600 focus:ring-indigo-500">
                                            Down payment
                                        </label>
                                        <label class="flex items-center gap-2 cursor-pointer text-xs text-gray-800" id="editPaymentKindFinalPaymentRow">
                                            <input type="radio" name="payment_kind" value="final_payment" id="editPaymentKindFinalPayment"
                                                class="border-gray-300 text-green-600 focus:ring-green-500">
                                            Final payment <span class="text-gray-400">(completes down payment)</span>
                                        </label>
                                    </div>
                                </div>
                                <div class="flex flex-col gap-2 min-h-0">
                                    <input type="hidden" name="payment_entry" id="editPaymentEntry" value="replace">
                                    <label class="block text-[calc(0.875rem-1px)] font-medium text-gray-600 shrink-0" id="editAmountPaidLabel" for="editAmountPaid">Amount paid</label>
                                    <input type="text" name="amount_paid" id="editAmountPaid" inputmode="decimal" autocomplete="off"
                                        class="form-input w-full h-10 py-0 shrink-0" placeholder="e.g. 200.000">
                                    <div id="editPaymentBaseHint" class="hidden rounded-xl border-2 border-indigo-300/90 bg-gradient-to-br from-indigo-50 via-white to-amber-50/80 px-3.5 py-3 shadow-md ring-2 ring-indigo-100/90" role="status" aria-live="polite"></div>
                                </div>
                            </div>
                            <div class="flex flex-col gap-2 w-full min-w-0 pt-3 border-t border-indigo-200/90">
                                <div id="editOverdueReturnPanel" class="hidden mb-3 rounded-xl border-2 border-amber-300 bg-amber-50 px-3.5 py-3 text-sm text-amber-950 shadow-sm" role="status">
                                    <div class="flex flex-col gap-2">
                                        <div class="flex flex-col gap-2">
                                            <div class="min-w-0 flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                                                <p id="editOverdueTitle" class="text-[calc(0.75rem-1px)] font-extrabold uppercase tracking-wider text-amber-900">Add charge</p>
                                                <p id="editOverdueActionTitle" class="hidden text-xs font-semibold text-amber-900"></p>
                                            </div>
                                            <div id="editOverdueManualRateWrap" class="hidden flex items-center gap-2">
                                                <label for="editOverdueManualRate" class="text-xs font-semibold text-amber-900 whitespace-nowrap shrink-0">Fine per day (Rp)</label>
                                                <input type="text" id="editOverdueManualRate" inputmode="decimal" autocomplete="off"
                                                    class="form-input flex-1 min-w-0" placeholder="e.g. 100.000">
                                            </div>
                                            <div class="flex gap-2">
                                                <button type="button" id="editApplyOverdueChargeBtn" class="flex-1 px-3 py-2 rounded-md border border-amber-400 bg-white text-amber-950 text-sm font-semibold hover:bg-amber-100 cursor-pointer">Apply</button>
                                                <button type="button" id="editDismissOverdueBtn" class="flex-1 px-3 py-2 rounded-md border border-amber-200 bg-white text-amber-900 text-sm font-medium hover:bg-amber-50 cursor-pointer">Not now</button>
                                            </div>
                                        </div>
                                        <p id="editOverdueInfo" class="text-xs text-amber-900/90 leading-snug w-full" role="status" aria-live="polite"></p>
                                        <div id="editOverduePayPanel" class="hidden mt-3 rounded-lg border border-amber-200 bg-white/70 px-3 py-3">
                                            <input type="hidden" name="overdue_payment_entry" id="editOverduePaymentEntry" value="replace">
                                            <div class="flex flex-col gap-3">
                                                <div>
                                                    <label class="block text-xs font-semibold text-amber-900 mb-1">Overdue payment method</label>
                                                    <select name="overdue_payment_method" id="editOverduePaymentMethod" class="form-select w-full h-10 py-0">
                                                        <option value="">- Select -</option>
                                                        <option value="cash">Cash</option>
                                                        <option value="transfer">Transfer</option>
                                                        <option value="qris">QRIS</option>
                                                        <option value="edc">EDC</option>
                                                        <option value="other">Other</option>
                                                    </select>
                                                </div>
                                                <div>
                                                    <label class="block text-xs font-semibold text-amber-900 mb-1" id="editOverdueAmountPaidLabel">Amount received</label>
                                                    <input type="text" id="editOverdueAmountPaid" inputmode="decimal" autocomplete="off"
                                                        class="form-input w-full h-10 py-0" placeholder="Amount to add now">
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <button type="button" id="editPayOverdueBtn" onclick="payOverdueChargeFromEditModal()"
                                                    class="hidden w-full px-3 py-2 bg-amber-600 rounded-md text-sm font-semibold text-white hover:bg-amber-700 cursor-pointer">Pay overdue</button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span class="text-xs font-bold uppercase tracking-wide text-indigo-900">Total due</span>
                                    <span id="editDueInclTaxChip" class="inline-flex items-center rounded-full bg-indigo-600 px-2 py-0.5 text-xs font-semibold uppercase tracking-wide text-white shadow-sm{{ $pricingPricesIncludeTax ? ' hidden' : '' }}">Incl. tax</span>
                                </div>
                                {{-- Ledger block 1: CHARGES — what the customer owes, fine included, one total. --}}
                                <div id="editDueSummaryPanel" class="rounded-xl border-2 border-indigo-400/90 bg-gradient-to-br from-indigo-100/90 via-white to-indigo-50/95 px-4 py-4 shadow-md ring-2 ring-indigo-200/50 w-full">
                                    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
                                        <p class="text-xs text-indigo-950/80 leading-relaxed max-w-md">Amount for the items and dates on this rent, after discounts, incl. overdue fine.</p>
                                        <div class="shrink-0 text-left sm:text-right">
                                            <span class="sr-only">Total due</span>
                                            <span id="editDueInlineDisplay" class="block text-[calc(1.875rem-3px)] sm:text-[calc(2.25rem-3px)] font-extrabold text-indigo-950 tabular-nums tracking-tight leading-none">—</span>
                                        </div>
                                    </div>
                                    <div id="editChargesBreakdown" class="hidden mt-3 grid grid-cols-[1fr_auto] gap-x-4 gap-y-1 text-xs text-indigo-950/90 border-t border-indigo-200/80 pt-2" aria-label="Charges breakdown"></div>
                                    <dl id="editDueQuickBreakdown" class="mt-2 grid grid-cols-[1fr_auto] gap-x-4 gap-y-2 text-xs hidden" aria-label="Total due breakdown">
                                        <dt id="editDueQuickTaxLabel" class="text-gray-600{{ $pricingPricesIncludeTax ? ' hidden' : '' }}">Tax <span class="text-gray-400 font-normal">(<span id="editDueQuickTaxPct">0</span>%)</span></dt>
                                        <dd id="editDueQuickTax" class="text-right font-semibold text-gray-900 tabular-nums{{ $pricingPricesIncludeTax ? ' hidden' : '' }}">—</dd>
                                    </dl>
                                </div>
                                {{-- Ledger block 2: PAYMENTS — every payment as its own row, one total. --}}
                                <div id="editCustomerPaidPanel" class="hidden w-full rounded-xl border border-indigo-200 bg-white/90 px-4 py-3 shadow-sm ring-1 ring-indigo-100/70">
                                    <div class="flex flex-wrap items-end justify-between gap-x-3 gap-y-1">
                                        <div class="min-w-0">
                                            <span class="text-xs font-bold uppercase tracking-wide text-indigo-900">Total paid</span>
                                            <span class="block text-[11px] font-medium text-indigo-900/80 normal-case tracking-normal" id="editCustomerPaidSubtitle">All payments recorded on this rent</span>
                                        </div>
                                        <span id="editCustomerPaidAmount" class="text-[calc(1.125rem-3px)] sm:text-[calc(1.25rem-3px)] font-extrabold tabular-nums text-indigo-950 leading-none shrink-0">—</span>
                                    </div>
                                    <div id="editCustomerPaidBreakdown" class="hidden mt-2 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 text-xs text-indigo-950/90 border-t border-indigo-200/70 pt-2"></div>
                                </div>
                                {{-- Ledger block 3: RESULT — the one line the admin acts on. --}}
                                <div id="editChangeMoneyInDuePanel" class="hidden w-full rounded-lg border border-emerald-300/80 bg-emerald-50/90 px-3 py-2.5 ring-1 ring-emerald-200/60">
                                    <div class="flex flex-wrap items-end justify-between gap-x-3 gap-y-1">
                                        <div class="min-w-0">
                                            <span id="editChangeMoneyLabel" class="text-xs font-bold uppercase tracking-wide text-emerald-900">Change</span>
                                            <span id="editChangeMoneySubtitle" class="block text-[11px] font-medium text-emerald-800/95 normal-case tracking-normal">Cash to return to customer</span>
                                        </div>
                                        <span id="editChangeMoneyAmount" class="text-[calc(1.125rem-3px)] sm:text-[calc(1.25rem-3px)] font-extrabold tabular-nums text-emerald-950 leading-none shrink-0">—</span>
                                    </div>
                                    <div id="editChangeMoneyBreakdown" class="hidden mt-2 grid grid-cols-[1fr_auto] gap-x-3 gap-y-1 text-xs border-t border-current/20 pt-2"></div>
                                </div>
                                <div class="w-full">
                                    <p id="editPaymentBalanceDisplay" class="text-sm font-semibold tabular-nums leading-snug rounded-lg border-2 border-indigo-100 bg-white/95 px-3 py-2.5 text-indigo-950 min-h-[2.75rem] flex items-center shadow-sm" aria-live="polite"></p>
                                    <div class="mt-3">
                                        <button type="button" id="editPayBtn" onclick="payDownPaymentFromEditModal()"
                                            class="hidden w-full px-3 py-2 bg-indigo-600 rounded-md text-sm font-semibold text-white hover:bg-indigo-700 cursor-pointer">Pay<span class="block text-[11px] font-normal">Save does not take payment. Use this button to record it.</span></button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>
                    @else
                    <section class="rounded-lg border border-gray-200 bg-gray-50 p-3">
                        <label class="block text-[calc(0.875rem-1px)] font-medium text-gray-700 mb-1">Payment method</label>
                        <select name="payment_method" id="editPaymentMethod" class="form-select w-full max-w-md">
                            <option value="">- Select payment method -</option>
                            <option value="cash">Cash</option>
                            <option value="transfer">Transfer</option>
                            <option value="qris">QRIS</option>
                            <option value="edc">EDC</option>
                            <option value="other">Other</option>
                        </select>
                    </section>
                    @endif

                </div>


            </div>
            <div id="editFinalStepHint" class="mt-4 p-2 bg-indigo-50 border border-indigo-100 rounded text-xs text-indigo-700">
                Final step: click <b>{{ auth()->user()?->isAdmin() ? 'Submit for Approval' : 'Save Changes' }}</b> after confirming selected items and dates.
            </div>
            <div id="editFinalStepPendingDetail" class="hidden mt-1 text-xs text-indigo-700">
                <a href="#" id="editFinalStepPendingDetailToggle" class="underline font-medium">What was requested?</a>
                <div id="editFinalStepPendingDetailBody" class="hidden mt-1 rounded border border-indigo-100 bg-indigo-50/60 px-2 py-1.5 leading-relaxed"></div>
            </div>
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mt-6">
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" id="openInvoicePageBtn" onclick="openInvoicePage()"
                        class="px-3 py-2 bg-slate-700 rounded-md text-sm font-medium text-white hover:bg-slate-800 cursor-pointer">Open Secure Receipt Page</button>
                    <button type="button" id="openInvoiceImageBtn" onclick="openInvoiceImage()"
                        class="px-3 py-2 bg-slate-600 rounded-md text-sm font-medium text-white hover:bg-slate-700 cursor-pointer">Open Receipt Image</button>
                    <button type="button" id="sendInvoiceWhatsappBtn" onclick="sendInvoiceWhatsapp()"
                        class="px-3 py-2 bg-green-600 rounded-md text-sm font-medium text-white hover:bg-green-700 cursor-pointer">Send via WhatsApp</button>
                    <button type="button" id="editLateDoneBtn" onclick="requestLateDone(currentRentalId)"
                        class="hidden px-3 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-indigo-600 hover:bg-gray-50 cursor-pointer">Report pickup/return…</button>
                    <button type="button" id="editRequestFreeBtn" onclick="requestFreeRental(currentRentalId)"
                        class="hidden px-3 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-green-600 hover:bg-gray-50 cursor-pointer">Make free…</button>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeModal()"
                        class="px-4 py-2 bg-gray-200 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-300 cursor-pointer">Cancel</button>
                    <button type="submit" id="editSaveChangesBtn"
                        class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer">{{ auth()->user()?->isAdmin() ? 'Submit for Approval' : 'Save Changes' }}</button>
                </div>
            </div>
        </form>
        </div>
    </div>
</div>
