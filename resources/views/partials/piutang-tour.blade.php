@if(Auth::check() && Auth::user()->mayViewAccountingDashboard())
{{-- Standalone walkthrough for the Receivables (Piutang, AR) page — read-only, same
     shape as ppn-tour.blade.php: no practice step, since there's no form
     here to safely try. --}}
<div id="rentalPiutangTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalPiutangTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalPiutangTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalPiutangTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            Receivables
            <span id="rentalPiutangTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalPiutangTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalPiutangTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-piutang-tour-step" data-step="0">
            <h3 id="rentalPiutangTourTitle" class="text-lg font-semibold text-gray-900 mb-2">What is this page for?</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                A list of <strong>rentals that still have a receivable</strong> — a deposit (DP) has been received, but
                the rental isn't fully paid yet. These numbers aren't calculated specially for this page; the data has been
                recorded from the start every time a deposit payment came in, there just wasn't a place to view it before.
            </p>
        </div>

        <div class="rental-piutang-tour-step hidden" data-target="#rentalTourNavAccountingToggle" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The menu is in the sidebar</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Accounting"</strong> to open its submenu.
            </p>
        </div>

        <div class="rental-piutang-tour-step hidden" data-target="#rentalTourNavPiutang">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Getting to this page</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Receivables"</strong> in the Reports submenu.
            </p>
        </div>

        <div id="rentalPiutangTourReadStep" class="rental-piutang-tour-step hidden" data-target="#piutangContent" data-target-readonly="1" data-prefer-left="1" data-scroll-block="start">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">How to read this list</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Each row represents one rental that isn't fully paid: the customer name, the rental number (clickable, it
                opens that rental), the rental start date, and the balance still to be collected.
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                Once the customer settles the payment, the row <strong>disappears automatically</strong> from
                this list — no need to delete it manually. If the table behind this tutorial shows grey italic
                rows, they are only examples (the store has no real receivables right now) — it doesn't
                mean the feature is broken.
            </p>
        </div>

        <div class="rental-piutang-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                That's how it works. Click the "?" button on this page anytime if you want to see it again.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalPiutangTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalPiutangTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalPiutangTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalPiutangTour',
    stepClass: 'rental-piutang-tour-step',
    storageKey: 'rental_piutang_tour_seen_v1_{{ Auth::id() }}',
    resumeKey: 'rental_piutang_tour_resume_v1_{{ Auth::id() }}',
    triggerSelector: '[data-open-piutang-tour]',
    openFnName: 'rentalOpenPiutangTour',
    debugFnName: 'rentalPiutangTourJumpTo',
    autoOpenOnFirstVisit: false,
});

(function () {
    function ensureAccountingSubmenuOpen() {
        var toggle = document.getElementById('rentalTourNavAccountingToggle');
        var probe = document.getElementById('rentalTourNavPiutang')
            || document.getElementById('rentalTourNavAccountingDashboard')
            || document.getElementById('rentalTourNavChartOfAccounts');
        if (toggle && probe && probe.offsetParent === null) {
            toggle.click();
        }
    }

    document.querySelectorAll('[data-open-piutang-tour]').forEach(function (btn) {
        btn.addEventListener('click', ensureAccountingSubmenuOpen);
    });

    if (sessionStorage.getItem('rental_piutang_tour_resume_v1_{{ Auth::id() }}')) {
        ensureAccountingSubmenuOpen();
    }
})();

(function () {
    // Swaps the "no receivables" message for the example rows in the real
    // Receivables table (see the empty-list branch of
    // accounting/reports/piutang.blade.php) only while this tour is actually
    // showing its "How to read this list" step — the example replaces that
    // message in place rather than stacking below it, and never appears at
    // all for a viewer who isn't running the tutorial.
    var overlay = document.getElementById('rentalPiutangTourOverlay');
    var readStep = document.getElementById('rentalPiutangTourReadStep');
    var exampleRows = document.querySelectorAll('.piutang-example-row');
    var emptyMessageRows = document.querySelectorAll('.piutang-empty-message');
    if (!overlay || !readStep || !exampleRows.length) return;

    function syncExampleRows() {
        var show = !overlay.classList.contains('hidden') && !readStep.classList.contains('hidden');
        exampleRows.forEach(function (row) { row.classList.toggle('hidden', !show); });
        emptyMessageRows.forEach(function (row) { row.classList.toggle('hidden', show); });
    }

    new MutationObserver(syncExampleRows).observe(overlay, {
        attributes: true, attributeFilter: ['class'], subtree: true,
    });
    syncExampleRows();
})();
</script>
@endif
