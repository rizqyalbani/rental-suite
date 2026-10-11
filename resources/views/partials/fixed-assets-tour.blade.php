@if(Auth::check() && Auth::user()->mayViewAccountingDashboard())
{{-- Standalone walkthrough for the Fixed Assets page — own tour instance (own
     idPrefix/step class/storage keys) via the SAME rentalCreateTour() engine as
     manual-journal-entry-tour.blade.php, kept in its own file for the same
     reason: a page with real interactive forms (Add Asset, Depreciate)
     needs its own practice step, unlike the read-only financial-reports-tour
     it sits next to in the nav.

     Two practice steps, both reusing the proven Expenses/Manual Journal Entry
     pattern (pre-fill the REAL form, spotlight the real submit button — only
     rendered when Trial Mode is on, so the result is tagged is_tutorial=true
     and never touches real balances): "Add Asset" fills and submits the
     add-item form directly. "Depreciate" is one step further removed — it
     needs an EXISTING registered item's id to target, which the add-item
     form doesn't give a script access to declaratively, so instead of
     waiting for a real click on the [data-susutkan-trigger] button (the tour
     engine's click-to-advance assumes a page navigation follows, which
     opening a same-page modal never does), the step's own script directly
     .click()s the first real trigger it finds to open the modal, then
     pre-fills the amount — same end result, just scripted one layer deeper.
     Both practice steps degrade to a plain explanation when their
     precondition isn't met (Trial Mode off, or Depreciate specifically: no
     item registered yet), keeping the step COUNT constant either way so the
     accounting-help-fab's hardcoded endStep never points past the real last
     step. --}}
<div id="rentalFixedAssetsTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalFixedAssetsTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalFixedAssetsTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalFixedAssetsTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            Fixed Assets
            <span id="rentalFixedAssetsTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalFixedAssetsTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalFixedAssetsTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-fixed-assets-tour-step" data-step="0">
            <h3 id="rentalFixedAssetsTourTitle" class="text-lg font-semibold text-gray-900 mb-2">What is this page for?</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                The place that lists physical items counted as assets (brooms, hangers, display wardrobes, etc.) —
                not just a total per account category, but <strong>item by item</strong>: when it was
                bought, how much it cost, and what it is worth now after depreciation.
            </p>
        </div>

        <div class="rental-fixed-assets-tour-step hidden" data-target="#rentalTourNavAccountingToggle" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The menu is in the sidebar</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Accounting"</strong> to open its submenu.
            </p>
        </div>

        <div class="rental-fixed-assets-tour-step hidden" data-target="#rentalTourNavFixedAssets">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Getting here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Fixed Assets"</strong> in the Reports submenu.
            </p>
        </div>

        <div class="rental-fixed-assets-tour-step hidden" data-target="#fixedAssetsContent" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Summary per account</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Purchase Price = total purchases into this account (stays intact, never decreases). Total
                Depreciated = the sum of depreciation for all items in this category. Book Value = the difference.
                If there is an odd number in "Total Depreciated" that doesn't match any item, it is usually
                left over from correcting an old transaction — a small note will explain it.
            </p>
        </div>

        <div class="rental-fixed-assets-tour-step hidden" data-target="#fixedAssetFormSection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Add Asset</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Register a new item here — this <strong>also records its purchase transaction</strong>
                (money really going out), just like Expenses, except it lands in an asset account.
            </p>
        </div>

        @php
            $simAccountId = \App\Models\Account::where('code', '1650')->value('id');
            $simPaymentAccountId = \App\Models\Account::where('code', '1100')->value('id');
            $simCanRun = \App\Support\AccountingTrialMode::enabled() && $simAccountId && $simPaymentAccountId;
        @endphp
        @if($simCanRun)
        <div id="rentalFixedAssetsTourSimStep" class="rental-fixed-assets-tour-step hidden" data-target="#fixedAssetSubmitBtn" data-tutorial-form="fixedAssetForm">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Try recording one yourself (practice)</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                The form above has been filled with an example: <strong>"Practice Broom" Rp 25.000</strong>, category
                Supplies (Capital Asset), paid from Store Cash. Check the entry first, then click
                <strong>"Add Asset"</strong>. Safe to try — because Trial Mode is ON, this is
                recorded as practice data, not real data.
            </p>
        </div>
        @else
        <div class="rental-fixed-assets-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Try recording one yourself (practice)</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                To try recording directly without affecting real data, turn on <strong>Trial
                Mode</strong> on the Settings page first, then reopen this guide from the start.
            </p>
        </div>
        @endif

        <div class="rental-fixed-assets-tour-step hidden" data-target="#fixedAssetListSection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Asset List</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                All registered items, with 2 buttons per row: <strong>"Depreciate"</strong> and
                <strong>"History"</strong> — you'll try them for real in the next 2 steps.
            </p>
        </div>

        @php
            $hasAnyAsset = \App\Models\FixedAsset::query()->exists();
        @endphp
        {{-- Depreciate slot before History slot on purpose — recording one
             first means the very next step's History has something real to
             show instead of "Not depreciated yet.", and it matches the
             natural order anyway (nothing to look back at before something
             happened). Both slots always render exactly one step div, real
             or fallback, so the total step count (and the
             accounting-help-fab's endStep below) stays constant regardless
             of runtime data. --}}
        @if($hasAnyAsset && $simCanRun)
        <div id="rentalFixedAssetsTourSusutkanStep" class="rental-fixed-assets-tour-step hidden" data-target="#depreciateSubmitBtn" data-tutorial-form="depreciateForm">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Try depreciating (practice)</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                The "Depreciate" form has been opened automatically for one of the items, with a sample amount already
                filled in: <strong>Rp 5.000</strong>, and a sample note too. Check it, then click
                <strong>"Record Depreciation"</strong>. Safe to try — because Trial Mode is ON, this is
                recorded as practice data, not real data.
            </p>
        </div>
        @else
        <div class="rental-fixed-assets-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The "Depreciate" button</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                @if(! $hasAnyAsset)
                    No items are registered to try yet — register 1 first via "Add Asset" above.
                @else
                    To try recording directly without affecting real data, turn on <strong>Trial
                    Mode</strong> on the Settings page first, then reopen this guide from the start.
                @endif
            </p>
        </div>
        @endif

        @if($hasAnyAsset)
        <div class="rental-fixed-assets-tour-step hidden" data-target="[data-riwayat-trigger]" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Try viewing the History</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "History"</strong> on any row anytime — it's view only, it doesn't
                change any data.
                @if($simCanRun)
                    The practice depreciation from just now is deliberately not shown here, so that History
                    only contains real data.
                @endif
                It contains the date, amount, and note of the depreciation, for that item only (not mixed with
                other items in the same category). If it has never been depreciated, it says "Not
                depreciated yet."
            </p>
        </div>
        @else
        <div class="rental-fixed-assets-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The "History" button</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                No items are registered to try yet — register 1 first via "Add Asset" above,
                then this button appears on every row of the Asset List.
            </p>
        </div>
        @endif

        <div class="rental-fixed-assets-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                That's how it works. Click the "?" button on this page anytime if you want to see it again.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalFixedAssetsTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalFixedAssetsTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalFixedAssetsTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalFixedAssetsTour',
    stepClass: 'rental-fixed-assets-tour-step',
    storageKey: 'rental_fixed_assets_tour_seen_v1_{{ Auth::id() }}',
    resumeKey: 'rental_fixed_assets_tour_resume_v1_{{ Auth::id() }}',
    triggerSelector: '[data-open-fixed-assets-tour]',
    openFnName: 'rentalOpenFixedAssetsTour',
    debugFnName: 'rentalFixedAssetsTourJumpTo',
    autoOpenOnFirstVisit: false,
});

(function () {
    function ensureAccountingSubmenuOpen() {
        var toggle = document.getElementById('rentalTourNavAccountingToggle');
        var probe = document.getElementById('rentalTourNavFixedAssets')
            || document.getElementById('rentalTourNavAccountingDashboard')
            || document.getElementById('rentalTourNavChartOfAccounts');
        if (toggle && probe && probe.offsetParent === null) {
            toggle.click();
        }
    }

    document.querySelectorAll('[data-open-fixed-assets-tour]').forEach(function (btn) {
        btn.addEventListener('click', ensureAccountingSubmenuOpen);
    });

    if (sessionStorage.getItem('rental_fixed_assets_tour_resume_v1_{{ Auth::id() }}')) {
        ensureAccountingSubmenuOpen();
    }
})();

(function () {
    // History/Depreciate modals are real page UI, not the tour's own — a real
    // click on either can leave one open across a step change (Back, Next,
    // or resuming after a reload), and neither modal auto-closes on its
    // own. Landing on ANY step with a stray modal still open makes the tour
    // look broken (wrong content behind the tour dialog, spotlight pointing
    // at a button that's no longer clickable) — reset both to closed on
    // every step transition, before that step's own logic (if any) runs.
    var stepsContainer = document.getElementById('rentalFixedAssetsTourSteps');
    if (!stepsContainer) return;
    function closeStrayModals() {
        var history = document.getElementById('historyModal');
        if (history && !history.classList.contains('hidden') && typeof window.closeHistoryModal === 'function') {
            window.closeHistoryModal();
        }
        var depreciate = document.getElementById('depreciateModal');
        // The Depreciate step opens depreciateModal itself right after this
        // runs — closing it here first (not skipping it) keeps that step's
        // own open-fresh logic the single source of truth for when it
        // should be open, rather than two places disagreeing about it.
        if (depreciate && !depreciate.classList.contains('hidden') && typeof window.closeDepreciateModal === 'function') {
            window.closeDepreciateModal();
        }
    }
    closeStrayModals();
    new MutationObserver(closeStrayModals).observe(stepsContainer, { subtree: true, attributes: true, attributeFilter: ['class'] });
})();

@if($simCanRun)
(function () {
    var step = document.getElementById('rentalFixedAssetsTourSimStep');
    if (!step) return;
    var filled = false;
    function fillPracticeEntry() {
        if (filled || step.classList.contains('hidden')) return;
        filled = true;
        var name = document.getElementById('fixedAssetName');
        var date = document.querySelector('#fixedAssetForm input[name="purchase_date"]');
        var price = document.getElementById('fixedAssetPrice');
        var account = document.getElementById('fixedAssetAccountInput');
        var payment = document.getElementById('fixedAssetPaymentInput');
        if (name && !name.value) name.value = 'Practice Broom';
        if (date && !date.value) date.value = new Date().toISOString().slice(0, 10);
        if (price && !price.value) { price.value = '25.000'; }
        // dispatchEvent('change') after each: these two are wrapped into a
        // searchable combobox (searchable-select.js), whose visible label
        // only re-syncs on the select's own 'change' event.
        if (account && !account.value) {
            account.value = '{{ $simAccountId }}';
            account.dispatchEvent(new Event('change'));
        }
        if (payment && !payment.value) {
            payment.value = '{{ $simPaymentAccountId }}';
            payment.dispatchEvent(new Event('change'));
        }
    }
    fillPracticeEntry();
    new MutationObserver(fillPracticeEntry).observe(step, { attributes: true, attributeFilter: ['class'] });
})();
@endif

@if($hasAnyAsset && $simCanRun)
(function () {
    // "Try depreciating (practice)" targets #depreciateSubmitBtn, which lives
    // inside the normally-hidden Depreciate modal. Unlike a real sidebar link
    // click (which the tour engine advances past via a page navigation +
    // resume), clicking [data-susutkan-trigger] only opens a modal on the
    // SAME page — the engine has no generic "wait for this same-page
    // mutation, then advance" hook for an arbitrary modal the way it does
    // for the rental edit modal specifically. Scripting the open here
    // (same pattern as the Add Asset practice step's pre-fill) sidesteps
    // that gap entirely: by the time this step is visible, the modal is
    // already open with real item data, and only the actual submit click
    // is left for the user to do for real.
    var step = document.getElementById('rentalFixedAssetsTourSusutkanStep');
    if (!step) return;
    // No one-shot "already ran" guard here on purpose — the sibling
    // closeStrayModals() observer above reacts to the SAME class mutation
    // and may run before or after this one (separate MutationObserver
    // instances have no guaranteed relative order). Each call below just
    // corrects whatever's currently true (modal closed → open it, amount
    // empty → fill it) instead of "did this already run once", so it
    // converges to the right state regardless of which observer fired
    // first, including if closeStrayModals closes this same modal AFTER
    // this function already opened it.
    function openAndFillPractice() {
        if (step.classList.contains('hidden')) return;
        var modal = document.getElementById('depreciateModal');
        var justOpened = false;
        if (modal && modal.classList.contains('hidden')) {
            var trigger = document.querySelector('[data-susutkan-trigger]');
            if (trigger) { trigger.click(); justOpened = true; }
        }
        var amount = document.getElementById('depreciateAmount');
        if (amount && !amount.value) {
            amount.value = '5.000';
            amount.dispatchEvent(new Event('input', { bubbles: true }));
        }
        var note = document.getElementById('depreciateNote');
        if (note && !note.value) {
            note.value = 'Annual depreciation — practice';
            note.dispatchEvent(new Event('input', { bubbles: true }));
        }
        if (justOpened) {
            // render() (which positions the spotlight) already ran
            // synchronously when this step became active, BEFORE the modal
            // existed — #depreciateSubmitBtn had zero size/position at that
            // moment, so the spotlight box stayed wherever it was for the
            // PREVIOUS step. The engine's own resize listener re-runs
            // render()/repositions the spotlight around whatever the
            // current step's real target now is — reuse that instead of
            // reaching into the engine's closure.
            requestAnimationFrame(function () {
                window.dispatchEvent(new Event('resize'));
            });
        }
    }
    openAndFillPractice();
    new MutationObserver(openAndFillPractice).observe(step, { attributes: true, attributeFilter: ['class'] });
    // closeStrayModals() above can close THIS step's own modal too (e.g. if
    // it re-fires for an unrelated later mutation while still on this
    // step) — watch the modal's own hidden class directly so it reopens
    // immediately rather than waiting on the next step-level mutation.
    var depreciateModalEl = document.getElementById('depreciateModal');
    if (depreciateModalEl) {
        new MutationObserver(openAndFillPractice).observe(depreciateModalEl, { attributes: true, attributeFilter: ['class'] });
    }
})();
@endif
</script>
@endif
