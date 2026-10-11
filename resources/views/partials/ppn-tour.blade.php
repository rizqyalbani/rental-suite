@if(Auth::check() && Auth::user()->mayViewAccountingDashboard())
{{-- Standalone walkthrough for the PPN (Preview) page — own tour instance via
     the same rentalCreateTour() engine as the other accounting page tours.
     No practice step: the whole page is read-only (a date filter and 3
     numbers), nothing here ever posts data, so there's nothing to safely
     "try" the way Add Asset/Depreciate/Manual Journal Entry have. --}}
<div id="rentalPpnTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalPpnTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalPpnTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalPpnTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            PPN (Preview)
            <span id="rentalPpnTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalPpnTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalPpnTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-ppn-tour-step" data-step="0">
            <h3 id="rentalPpnTourTitle" class="text-lg font-semibold text-gray-900 mb-2">What is this page for?</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                An estimate of how much <strong>VAT (PPN) needs to be remitted</strong> for a date range —
                calculated from the rental revenue already recorded, using the tax rate that's active in
                Settings.
            </p>
            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                This is only an estimate for your own use or to hand to an accountant — <strong>this page
                doesn't record or post anything</strong> and doesn't change any other report at all.
            </p>
        </div>

        <div class="rental-ppn-tour-step hidden" data-target="#rentalTourNavAccountingToggle" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The menu is in the sidebar</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Accounting"</strong> to open its submenu.
            </p>
        </div>

        <div class="rental-ppn-tour-step hidden" data-target="#rentalTourNavPpn">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Getting here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "PPN (Preview)"</strong> in the Reports submenu.
            </p>
        </div>

        <div class="rental-ppn-tour-step hidden" data-target="#ppnFilterForm">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Try picking a date range</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Change the "from" and/or "to" date in the highlighted box, then <strong>click
                Filter</strong> for real — it's safe, it only refreshes the numbers below and nothing gets recorded.
            </p>
        </div>

        <div class="rental-ppn-tour-step hidden" data-target="#ppnPreviewContent" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Read the result</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Rental Revenue (gross)</strong> — the total received in this range.
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                <strong>Taxable Base (DPP)</strong> — the part that isn't tax (the original price).
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                <strong>Estimated VAT Payable</strong> — the difference; this is the number you want to see.
            </p>
        </div>

        <div class="rental-ppn-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                That's how it works. Click the "?" button on this page anytime to see it again.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalPpnTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalPpnTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalPpnTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalPpnTour',
    stepClass: 'rental-ppn-tour-step',
    storageKey: 'rental_ppn_tour_seen_v1_{{ Auth::id() }}',
    resumeKey: 'rental_ppn_tour_resume_v1_{{ Auth::id() }}',
    triggerSelector: '[data-open-ppn-tour]',
    openFnName: 'rentalOpenPpnTour',
    debugFnName: 'rentalPpnTourJumpTo',
    autoOpenOnFirstVisit: false,
});

(function () {
    function ensureAccountingSubmenuOpen() {
        var toggle = document.getElementById('rentalTourNavAccountingToggle');
        var probe = document.getElementById('rentalTourNavPpn')
            || document.getElementById('rentalTourNavAccountingDashboard')
            || document.getElementById('rentalTourNavChartOfAccounts');
        if (toggle && probe && probe.offsetParent === null) {
            toggle.click();
        }
    }

    document.querySelectorAll('[data-open-ppn-tour]').forEach(function (btn) {
        btn.addEventListener('click', ensureAccountingSubmenuOpen);
    });

    if (sessionStorage.getItem('rental_ppn_tour_resume_v1_{{ Auth::id() }}')) {
        ensureAccountingSubmenuOpen();
    }
})();
</script>
@endif
