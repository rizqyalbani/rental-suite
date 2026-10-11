@php
    $removalTourUser = Auth::user();
    $removalTourIsDecider = $removalTourUser->isManagement() || $removalTourUser->isSupervisor() || $removalTourUser->isSuperAdmin();
@endphp
@if($removalTourIsDecider)
{{-- Standalone, separately-triggered walkthrough for "Removal Requests" —
     pulled out of the main "How approvals work" tour so it doesn't force
     Admin (who never sees this queue at all) or a Supervisor/Management user
     who only wants the edit-approval walkthrough to sit through it. Reuses
     the SAME real-spotlight engine as the main tour (rentalCreateTour(), see
     approval-tour.blade.php) via its own element id prefix, step class, and
     storage keys — this file only supplies content + config, not engine code. --}}
<div id="rentalRemovalTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalRemovalTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalRemovalTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalRemovalTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            How item removal works
            <span id="rentalRemovalTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalRemovalTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalRemovalTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-removal-tour-step" data-step="0">
            <h3 id="rentalRemovalTourTitle" class="text-lg font-semibold text-gray-900 mb-2">Removing an item works differently</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Removing an item from a rental that has <strong>already been paid</strong> always needs approval from
                another superior first, no matter who removes it.
            </p>
            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                Safe to try. This is practice data and doesn't affect the real data.
            </p>
        </div>

        <div class="rental-removal-tour-step hidden" data-target="#rentalTourNavRemovalRequests" data-tutorial-seed="item-removal">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Getting here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Removal Requests"</strong>.
            </p>
        </div>

        <div class="rental-removal-tour-step hidden" data-target="#itemRemovalPendingList" data-target-first-child="1" data-target-readonly="1" data-requires-pending-seed="item-removal">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Here's the card</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                An item removal request always shows 3 numbers: the current total, the amount already paid, and the
                new total if approved.
            </p>
        </div>

        <div class="rental-removal-tour-step hidden" data-target="[id^='item-removal-financial-']" data-target-readonly="1" data-requires-pending-seed="item-removal">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The total goes down</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Item removed &rarr; <strong>New total</strong> is lower than <strong>Current total</strong>. That is
                the rental's bill if this request is approved.
            </p>
        </div>

        <div class="rental-removal-tour-step hidden" data-target="[id^='item-removal-overpayment-']" data-target-readonly="1" data-requires-pending-seed="item-removal">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The money becomes an overpayment</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Amount paid</strong> here is higher than the new total. It means the customer has paid
                too much — check the refund first before approving.
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                A <strong>"Refund from account"</strong> dropdown also appears next to the Approve button — choose Store
                Cash or Bank, depending on where the refund money really comes out of. Once approved, the system
                automatically records it as real money going out, not just a number on screen.
            </p>
        </div>

        <div class="rental-removal-tour-step hidden" data-target="[id^='item-removal-actions-']" data-auto-advance="approval-decided" data-requires-pending-seed="item-removal">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Approve or Reject</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click either one.</strong> This is practice, safe to try.
            </p>
        </div>

        <div class="rental-removal-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                That's how it works. You can replay this guide anytime from the sidebar.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalRemovalTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalRemovalTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalRemovalTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalRemovalTour',
    stepClass: 'rental-removal-tour-step',
    storageKey: 'rental_removal_tour_seen_v1_{{ $removalTourUser->id }}',
    resumeKey: 'rental_removal_tour_resume_v1_{{ $removalTourUser->id }}',
    triggerSelector: '[data-open-removal-tour]',
    openFnName: 'rentalOpenRemovalTour',
    debugFnName: 'rentalRemovalTourJumpTo',
    autoOpenOnFirstVisit: false,
    tutorialSeedConfig: {
        'item-removal': { navLinkId: 'rentalTourNavRemovalRequests', endpoint: @json(route('approvals.tutorial-seed-item-removal-request')) },
    },
});
</script>
@endif
