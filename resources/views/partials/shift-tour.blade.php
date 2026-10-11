@php
    $shiftTourUser = Auth::user();
    $shiftTourCanOperate = ! $shiftTourUser->isManagement();
@endphp
@if($shiftTourCanOperate)
{{-- Standalone, manually-triggered walkthrough for Shift Control (open, close, and
     the full report) — reuses the SAME real-spotlight engine as the other tours
     (rentalCreateTour(), see approval-tour.blade.php).

     Unlike the other real-click steps, opening/closing the REAL shift has real
     consequences (real daily totals, real reports), so this tour doesn't touch the
     real Open/Close/Report buttons at all. Instead it switches Shift Control's single
     Open/Close slot to a "Practice" tab (see shift-control.blade.php) backed by its
     own isolated table (TutorialShiftSession + TutorialRental) — every click here is
     a REAL click on a REAL button, it just all happens on a shift and two example
     transactions only that tab (and this tutorial) can see. Switching back to the
     "Real" tab never touches that practice data; only "Exit practice" wipes it.
     Management doesn't get this tour: they see a read-only "who has a shift open"
     summary instead of any shift controls, real or practice, so there's nothing here
     for them to do. --}}
<div id="rentalShiftTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalShiftTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalShiftTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalShiftTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            Open, close, and shift report
            <span id="rentalShiftTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalShiftTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalShiftTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-shift-tour-step" data-step="0">
            <h3 id="rentalShiftTourTitle" class="text-lg font-semibold text-gray-900 mb-2">Why do you need a shift?</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Every rental transaction is recorded under one shift. <strong>Open the shift</strong> before you start work,
                <strong>close it</strong> when you're done — so the daily report is accurate and matches the right cashier.
            </p>
            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                Safe to try. This guide uses its own practice shift with example data, separate from the real shifts and
                reports.
            </p>
        </div>

        <div class="rental-shift-tour-step hidden" data-target="#rentalTourNavRents">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Getting here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Rents"</strong> in the sidebar — Shift Control is at the top of that page, and the "Practice"
                tab is already there.
            </p>
        </div>

        <div class="rental-shift-tour-step hidden" data-target="#tutorialShiftOpenBtn">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Open the shift</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Choose <strong>Morning</strong> or <strong>Afternoon</strong>, then click <strong>Open Shift</strong>.
                Two example transactions will appear in it right away.
            </p>
        </div>

        <div class="rental-shift-tour-step hidden" data-target="#tutorialShiftFinancialSummary" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Running summary</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                These are the two example transactions that were just created. While the shift is running, the total paid
                here keeps updating in real time whenever there's a new transaction.
            </p>
        </div>

        <div class="rental-shift-tour-step hidden" data-target="#tutorialShiftCloseBtn">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Close the shift</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Close Shift"</strong> when you finish work. Once it's closed, the totals are locked and
                go into the report.
            </p>
        </div>

        {{-- Readonly, unlike the other real-click steps: the report is a standalone
             printable page with no app chrome (no sidebar, no tour engine at all), so a
             real click through to it would leave this tour with nowhere to show its
             closing step. Spotlight it here instead and let them click it themselves
             once the tutorial is done. --}}
        <div class="rental-shift-tour-step hidden" data-target="#tutorialShiftFullReportLink" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Full report</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Full report &amp; print"</strong> to see the full details of this shift. It's exactly the
                same page as the real shift report, including the <strong>Notes</strong> column for each
                transaction if a note was written when the rental was created.
            </p>
        </div>

        <div class="rental-shift-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                That's how it works. When you're done practising, click <strong>"Exit practice"</strong> to
                clear the example data. You can replay this guide anytime from the Help page.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalShiftTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalShiftTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalShiftTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalShiftTour',
    stepClass: 'rental-shift-tour-step',
    storageKey: 'rental_shift_tour_seen_v1_{{ $shiftTourUser->id }}',
    resumeKey: 'rental_shift_tour_resume_v1_{{ $shiftTourUser->id }}',
    triggerSelector: '[data-open-shift-tour]',
    openFnName: 'rentalOpenShiftTour',
    debugFnName: 'rentalShiftTourJumpTo',
    autoOpenOnFirstVisit: false,
});

(function () {
    // The Real/Practice tab switch on Shift Control only exists while a
    // practice session is ongoing — this tour is the ONLY place that starts
    // one (no manual "start" button on the Rents page itself anymore), so it
    // begins the session itself the moment "Start" is clicked here, and ends
    // it (wiping the practice shift) the moment this tour closes, however it
    // closes — Skip, Got it on the last step, Escape, or the backdrop.
    // That keeps "outside the tutorial" strictly production-only, matching
    // every other page load that never touched this tour.
    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function postTutorial(path) {
        return fetch(path, {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken() },
            credentials: 'same-origin',
            keepalive: true,
        }).catch(function () {});
    }

    document.querySelectorAll('[data-open-shift-tour]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            postTutorial('/shifts/tutorial/start');
        });
    });

    var overlay = document.getElementById('rentalShiftTourOverlay');
    if (overlay) {
        var wasOpen = false;
        new MutationObserver(function () {
            var isOpen = ! overlay.classList.contains('hidden');
            if (wasOpen && ! isOpen) {
                postTutorial('/shifts/tutorial/exit');
            }
            wasOpen = isOpen;
        }).observe(overlay, { attributes: true, attributeFilter: ['class'] });
    }
})();
</script>
@endif
