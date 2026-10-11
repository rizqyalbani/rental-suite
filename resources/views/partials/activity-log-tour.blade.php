@if(Auth::check() && Auth::user()->mayViewActivityLog())
{{-- Standalone, manually-triggered walkthrough for the Activity Log — available to
     every role (mayViewActivityLog() is true for all). Reuses the SAME real-spotlight
     engine as the other tours (rentalCreateTour(), see approval-tour.blade.php) via its
     own element id prefix, step class, and storage keys — this file only supplies
     content + config, not engine code. --}}
<div id="rentalActivityLogTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalActivityLogTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalActivityLogTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalActivityLogTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            What is the Activity Log?
            <span id="rentalActivityLogTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalActivityLogTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalActivityLogTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-activity-log-tour-step" data-step="0">
            <h3 id="rentalActivityLogTourTitle" class="text-lg font-semibold text-gray-900 mb-2">A history of all activity</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Every time a rental is created, edited, approved, rejected, or an item is removed — it's all recorded,
                along with who did it and when. Available to Admin, Supervisor, and Management.
            </p>
        </div>

        <div class="rental-activity-log-tour-step hidden" data-target="#rentalTourNavActivityLog">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Getting here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Activity Log"</strong> in the sidebar.
            </p>
        </div>

        <div class="rental-activity-log-tour-step hidden" data-target="#activityLogFilterForm" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">You can filter it</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Search by activity type, the staff member who did it, or a date range.
            </p>
        </div>

        <div class="rental-activity-log-tour-step hidden" data-target="#activityLogTimelineList" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Here's the list</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Newest first. Each entry shows the change <strong>before &rarr; after</strong> —
                for example items added/removed or payment changes.
            </p>
        </div>

        <div class="rental-activity-log-tour-step hidden" data-target="#activityLogEdgeToggle" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">A quick way, from any page</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                No need to switch pages — this small arrow sits on the right edge of the screen, on every page. Click
                it for a quick panel of recent activity.
            </p>
        </div>

        <div class="rental-activity-log-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                That's how it works. You can replay this guide anytime from the Help page.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalActivityLogTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalActivityLogTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalActivityLogTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalActivityLogTour',
    stepClass: 'rental-activity-log-tour-step',
    storageKey: 'rental_activity_log_tour_seen_v1_{{ Auth::id() }}',
    resumeKey: 'rental_activity_log_tour_resume_v1_{{ Auth::id() }}',
    triggerSelector: '[data-open-activity-log-tour]',
    openFnName: 'rentalOpenActivityLogTour',
    debugFnName: 'rentalActivityLogTourJumpTo',
    autoOpenOnFirstVisit: false,
});
</script>
@endif
