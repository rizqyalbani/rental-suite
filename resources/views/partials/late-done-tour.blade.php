@php
    $lateDoneTourUser = Auth::user();
    $lateDoneTourIsDecider = $lateDoneTourUser->isManagement() || $lateDoneTourUser->isSupervisor() || $lateDoneTourUser->isSuperAdmin();
@endphp
{{-- "Report a missed pickup/return" walkthrough (RentalLateDone). Same spotlight
     engine as the other tours (rentalCreateTour, tour-engine.blade.php). The form
     runs on the real screens with practice data only: a practice rent is seeded
     (seedTutorialLateDoneRent), the user opens it, sends a real report through
     the real form (stored in the tutorial tables), and Supervisor/Management then
     approve it on Edit Requests — nothing touches real rents, payments or reports. --}}
<div id="rentalLateDoneTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalLateDoneTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalLateDoneTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalLateDoneTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            Report a missed pickup/return
            <span id="rentalLateDoneTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalLateDoneTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalLateDoneTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-late-done-tour-step" data-step="0">
            <h3 id="rentalLateDoneTourTitle" class="text-lg font-semibold text-gray-900 mb-2">Forgot to click pickup / return?</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                The items were picked up and returned, but the <strong>Mark picked up</strong> /
                <strong>Return</strong> button was never clicked. The rental then looks late and can get a
                <strong>Rp 100.000 late fee</strong> even though it wasn't late.
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                Don't pay the late fee. Report what actually happened, then a superior approves it.
            </p>
            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                Safe to try. This is practice data and doesn't affect the real data.
            </p>
        </div>

        <div class="rental-late-done-tour-step hidden" data-target="#rentalTourNavRents" data-tutorial-seed="late-done-rent">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Start here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                One practice rental that was never returned has been prepared. <strong>Click "Rents"</strong>.
            </p>
        </div>

        {{-- Lands right after the Rents navigation. Untargeted on purpose: the
             rentals list loads by AJAX, so it is still empty when the page's resume
             check runs (an empty target restarts the tour). This step switches the
             list to practice data (data-tutorial-form) before the next one points at it. --}}
        <div class="rental-late-done-tour-step hidden" data-tutorial-form="editForm">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">This is the Rents page</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                During practice, the rental list below only shows your <strong>practice rentals</strong>.
                Click <strong>Next</strong>.
            </p>
        </div>

        <div class="rental-late-done-tour-step hidden" data-target="#rentalCards" data-target-first-child="1" data-auto-advance="edit-opened" data-tutorial-form="editForm">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">This is the practice rental</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Still <strong>Active</strong>, past its end date, and charged a Rp 100.000 late fee even though it has been returned.
                <strong>Click Edit</strong>.
            </p>
        </div>

        <div class="rental-late-done-tour-step hidden" data-target="#editLateDoneBtn" data-auto-advance="late-done-sent" data-tutorial-form="editForm">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Click this button</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Report pickup/return&hellip;"</strong>, then fill in: <strong>Picked up and returned</strong>,
                the actual pickup &amp; return times, and who handed over / received the items.
                Then <strong>Send for approval</strong>.
            </p>
            <p class="text-xs text-gray-400 mt-2">Enter a return date on or before the rental end date, so the late fee is removed.</p>
        </div>

        <div class="rental-late-done-tour-step hidden" data-tutorial-form="editForm">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Sent!</h3>
            <ul class="text-sm text-gray-600 leading-relaxed list-disc pl-5 space-y-1">
                <li>The rental <strong>doesn't change</strong> until it's approved.</li>
                <li>Once approved: it's marked as picked up &amp; returned at the reported times.</li>
                <li>Returned <strong>on time</strong> &rarr; the unpaid automatic late fee is <strong>removed</strong>. If it really was late, the fee stays.</li>
                <li>History records the times, the staff who handled it, who reported it, and who approved it.</li>
            </ul>
            @unless($lateDoneTourIsDecider)
                <p class="text-sm text-gray-600 leading-relaxed mt-2">
                    Approved by: <strong>Supervisor or Management</strong> (not the person who reported it).
                </p>
            @endunless
        </div>

        @if($lateDoneTourIsDecider)
            <div class="rental-late-done-tour-step hidden" data-target="#rentalTourNavEditRequests" data-tutorial-seed="late-done">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Now the superior's side</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    The report just went into the approval queue. <strong>Click "Edit Requests"</strong>.
                </p>
            </div>

            <div class="rental-late-done-tour-step hidden" data-target="[id^='late-done-details-']" data-target-readonly="1" data-requires-pending-seed="late-done">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">This is the report</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Check the pickup &amp; return times, and who handled it. Because it was returned on time, there is a note
                    that the <strong>Rp 100.000 late fee will be removed</strong>.
                </p>
            </div>

            <div class="rental-late-done-tour-step hidden" data-target="[id^='late-done-actions-']" data-auto-advance="approval-decided" data-requires-pending-seed="late-done">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Approve or Reject</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <strong>Click either one.</strong> This is practice, safe to try. With real data, you can't
                    approve a report you made yourself.
                </p>
            </div>
        @endif

        <div class="rental-late-done-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Remember: customer <strong>cancelled</strong> &rarr; use Cancel. Rental <strong>entered wrongly</strong> &rarr; use
                Delete with a reason. You can replay this guide from the Help menu.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalLateDoneTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalLateDoneTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalLateDoneTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalLateDoneTour',
    stepClass: 'rental-late-done-tour-step',
    storageKey: 'rental_late_done_tour_seen_v1_{{ $lateDoneTourUser->id }}',
    resumeKey: 'rental_late_done_tour_resume_v1_{{ $lateDoneTourUser->id }}',
    triggerSelector: '[data-open-late-done-tour]',
    openFnName: 'rentalOpenLateDoneTour',
    debugFnName: 'rentalLateDoneTourJumpTo',
    autoOpenOnFirstVisit: false,
    tutorialSeedConfig: {
        'late-done-rent': { navLinkId: 'rentalTourNavRents', endpoint: @json(route('tutorial.late-done-rent')) },
        @if($lateDoneTourIsDecider)
        'late-done': { navLinkId: 'rentalTourNavEditRequests', endpoint: @json(route('approvals.tutorial-seed-late-done-request')) },
        @endif
    },
});
</script>
