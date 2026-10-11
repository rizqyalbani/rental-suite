@php
    $tourUser = Auth::user();
    $tourIsAdmin = $tourUser->isAdmin();
    $tourIsDecider = $tourUser->isManagement() || $tourUser->isSupervisor() || $tourUser->isSuperAdmin();
    // Super Admin gets grouped into "Management" for this label, same as the
    // $isMgmt convention already used elsewhere (navigation.blade.php) — the
    // intro's "(You)" tag needs to land on whichever of the two decider
    // roles the actual logged-in user has, not a role that's always the same
    // regardless of who's viewing it.
    $tourIsSupervisorRole = $tourUser->isSupervisor();
@endphp
@if($tourIsAdmin || $tourIsDecider)
{{-- Blocks every real click on the page except inside the spotlighted target
     (a clip-path "hole" matching the spotlight rect, kept in sync with it) —
     without this, the darkened backdrop is purely visual (spotlight itself is
     pointer-events-none) and a misclick anywhere else, e.g. another sidebar
     link, does a real navigation the tour never intended and has no resume
     step queued for. Sits just below the spotlight/dialog (z-198 < 199/200)
     so the tour's own UI is never blocked by its own blocker. --}}
<div id="rentalTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            How approvals work
            <span id="rentalTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        {{-- Steps are plain divs, toggled via JS, in DOM order. data-target (a CSS
             selector) makes a step "interactive": the real element gets spotlighted.
             Nav links (no data-target-readonly) advance the tour on a real click, which
             navigates like any other nav click — the tour resumes on the next page load
             via sessionStorage. data-target-readonly spotlights a real element for visual
             guidance only (Back/Next drive it) — used for anything that would mutate data
             on a real click (Create Rent, Submit for Approval, Approve/Reject). Steps
             without data-target are plain centered dialogue, for content that depends on
             data we can't guarantee exists, or belongs to a different account's screen —
             those stay as accurate mockups built from the real components' own markup. --}}

        @if($tourIsAdmin)
            {{-- ADMIN STEPS --}}
            <div class="rental-tour-step" data-step="0">
                <h3 id="rentalTourTitle" class="text-lg font-semibold text-gray-900 mb-2">There are 3 roles here</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <strong>Admin</strong> (you), <strong>Supervisor</strong>, <strong>Management</strong>. Supervisor
                    &amp; Management edit rentals directly. You (Admin) edit an existing rental? It has to be approved first.
                </p>
                <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                    Safe to try. This is practice data and doesn't affect the real data.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-target="#rentalTourNavRents">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Start here</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <strong>Click "Rents"</strong> to continue.
                </p>
            </div>

            @include('partials.approval-tour-new-rent-steps')

            <div class="rental-tour-step hidden" data-target="#rentalCards" data-target-first-child="1" data-auto-advance="edit-opened" data-tutorial-form="editForm">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Now try an edit</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    For a rental that <strong>already exists</strong>, pick one below and then <strong>click Edit</strong>.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-target="#editOutfitCheckboxList" data-target-readonly="1" data-auto-advance="items-changed" data-tutorial-form="editForm">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Change something</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <strong>Tick or untick one item</strong> here.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-target="#editCartSection" data-target-readonly="1" data-prefer-left="1" data-scroll-block="start" data-tutorial-form="editForm">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">This is what changed</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Your change appears in <strong>Selected items</strong>.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-target="#editSaveChangesBtn" data-auto-advance="edit-submitted" data-tutorial-form="editForm">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">The Save button changes</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    It now becomes <strong>Submit for Approval</strong>. <strong>Click</strong> to submit the request.
                    The change only takes effect once it's approved.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-wide-card="1" data-post-submit-landing="1">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Submitted!</h3>
                <p class="text-sm text-gray-600 leading-relaxed mb-3">
                    Your superior will see a card like this. The rental keeps using the old data until it's approved, and
                    you can cancel it anytime.
                </p>
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-4 text-left lg:grid lg:grid-cols-5 lg:gap-x-5">
                    <div class="lg:col-span-3 min-w-0">
                        <div class="flex items-center justify-between gap-2 border-b border-gray-100 pb-2.5 mb-3">
                            <h4 class="text-sm font-semibold text-gray-900 min-w-0">Quick edit</h4>
                            <span class="text-[10px] px-2.5 py-1 rounded-full bg-amber-100 text-amber-900 font-bold uppercase tracking-wide shrink-0">Pending</span>
                        </div>
                        <p class="text-xs text-amber-900/90 mb-3 font-medium">
                            Staff asked to change an <strong>existing</strong> booking. The rent stays in the list with old
                            details until you approve.
                        </p>
                        <div class="mb-3 rounded-md border border-amber-300 bg-amber-50/90 px-3 py-2 text-xs text-amber-950">
                            <p class="font-semibold text-amber-950">
                                Rent #915
                                <span class="font-normal text-amber-900/85">·</span>
                                <span class="tabular-nums font-medium">01 Aug 2026 &rarr; 05 Aug 2026</span>
                            </p>
                        </div>
                        <div class="mb-2.5 flex items-center gap-2 rounded-md bg-gray-50 border border-gray-200 px-3 py-1.5">
                            <svg class="h-4 w-4 text-gray-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                            <p class="text-sm text-gray-900 min-w-0">Rental Suite · 08788888888</p>
                        </div>
                        <div class="mb-2">
                            <p class="text-xs font-medium text-emerald-800 uppercase tracking-wide mb-1">Ready &mdash; no conflict (stays on this rent)</p>
                            <div class="flex flex-wrap gap-1.5">
                                <span class="inline-flex items-center gap-1 rounded-md border border-emerald-300 bg-emerald-50 px-2 py-1 text-xs font-mono font-semibold text-emerald-900 shadow-sm">
                                    <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                                    BR 08
                                </span>
                            </div>
                        </div>
                        <details class="mt-2.5 group">
                            <summary class="cursor-pointer select-none text-xs font-semibold text-indigo-700 hover:text-indigo-900 inline-flex items-center gap-1">
                                <svg class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                View details
                                <span class="font-normal text-emerald-700">(1 changed)</span>
                            </summary>
                            <div class="mt-1.5 rounded-lg border border-gray-200 divide-y divide-gray-100 overflow-hidden">
                                <div class="flex items-center gap-2 px-2.5 py-1.5 text-xs bg-emerald-50">
                                    <span class="w-28 shrink-0 text-gray-500">End date</span>
                                    <span class="text-gray-400 line-through">03 Aug 2026</span>
                                    <svg class="h-3 w-3 text-emerald-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                                    <span class="font-semibold text-emerald-800">05 Aug 2026</span>
                                </div>
                            </div>
                        </details>
                        <p class="text-xs italic text-gray-600 mt-2.5 leading-snug">
                            Approving applies the requested dates, items, and customer/payment snapshot to rent #915.
                            Rejecting leaves the saved rent unchanged.
                        </p>
                    </div>
                    <div class="lg:col-span-2 lg:border-l lg:border-gray-200 lg:pl-5 lg:mt-0 mt-3 flex flex-col gap-2">
                        <details class="group">
                            <summary class="cursor-pointer select-none text-xs font-semibold text-gray-700 hover:text-gray-900 inline-flex items-center gap-1">
                                <svg class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                Add note <span class="font-normal text-gray-500">(optional, e.g. buffer OK)</span>
                            </summary>
                            <textarea rows="2" placeholder="Enter note…" disabled aria-label="Optional note (example)" class="form-textarea w-full text-xs py-1.5 rounded-lg bg-indigo-50/40 mt-1.5"></textarea>
                        </details>
                        <details class="group">
                            <summary class="cursor-pointer select-none text-xs font-semibold text-gray-700 hover:text-gray-900 inline-flex items-center gap-1">
                                <svg class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                                Add reason for staff <span class="font-normal text-gray-500">(optional, shown on reject)</span>
                            </summary>
                            <textarea rows="2" placeholder="Enter reason…" disabled aria-label="Optional reason for staff (example)" class="form-textarea w-full text-xs py-1.5 rounded-lg bg-indigo-50/40 mt-1.5"></textarea>
                        </details>
                        <div class="flex gap-2 pt-1 lg:mt-auto">
                            <span class="inline-flex flex-1 items-center gap-1.5 rounded-lg border border-green-700 bg-green-600 px-3 py-2.5 text-xs font-semibold text-white justify-center">
                                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                Approve &amp; update rent
                            </span>
                            <span class="inline-flex items-center gap-1.5 rounded-lg border border-red-700 bg-red-600 px-4 py-2.5 text-xs font-semibold text-white justify-center">
                                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                Reject
                            </span>
                        </div>
                        <p class="flex items-center justify-end gap-1.5 text-xs text-gray-500 pt-1">
                            <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                            <span>Requested by <strong>Admin</strong> · 2 minutes ago</span>
                        </p>
                    </div>
                </div>
            </div>

            <div class="rental-tour-step hidden" data-target="#rentalTourNavMyRequests">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">All requests are here</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Check the status in <strong>My Requests</strong>. Your practice request is there right now.
                    <strong>Click "My Requests"</strong>.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-target="#myRequestsFirstPendingCard" data-target-readonly="1">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">This is your request</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    This card is the edit request you just submitted, still <strong>Pending</strong>.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-target="#myRequestsFirstPendingRentLink">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">See the original rental</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <strong>Click "Rent #..."</strong> to open the rental directly.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-finish-action="1">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Still the old data</h3>
                <p class="text-sm text-gray-600 leading-relaxed mb-3">
                    Nothing has changed yet. It only takes effect once it's approved. In short: <strong>create/edit a rental &rarr; submit
                    &rarr; reviewed by a superior &rarr; track it in My Requests</strong>.
                </p>
                <p class="text-xs text-gray-400 mb-3">
                    This practice request is cancelled automatically as soon as you click Done.
                </p>
                <button type="button" id="rentalTourFinishPractice" class="w-full px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Done</button>
            </div>
        @else
            {{-- SUPERVISOR / MANAGEMENT STEPS --}}
            <div class="rental-tour-step" data-step="0">
                <h3 id="rentalTourTitle" class="text-lg font-semibold text-gray-900 mb-2">Your job: reviewing</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Admin,
                    @if($tourIsSupervisorRole)
                        <strong>Supervisor</strong> (you), Management.
                    @else
                        Supervisor, <strong>Management</strong> (you).
                    @endif
                    If <strong>Admin</strong> edits an existing rental, the request comes to you to
                    approve or reject. Let's try it right now.
                </p>
                <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                    Safe to try. This is practice data and doesn't affect the real data.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-target="#rentalTourNavEditRequests" data-tutorial-seed="edit-request">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Now: approving a request</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Edits from Admin arrive in <strong>Edit Requests</strong>. <strong>Click "Edit Requests"</strong>.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-target="#editRequestsPendingList" data-target-first-child="1" data-target-readonly="1" data-wide-card="1" data-requires-pending-seed="edit-request">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">The card looks like this</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    This is an example (dummy data) of your own practice request. The real card from Admin will look
                    exactly the same.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-target="[id^='view-details-']" data-target-readonly="1" data-requires-pending-seed="edit-request">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">See the details of the change</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <strong>Click "View details"</strong> to see what changed, compared with the data that is
                    currently saved.
                </p>
            </div>

            <div class="rental-tour-step hidden" data-target="[id^='approval-actions-']" data-auto-advance="approval-decided" data-requires-pending-seed="edit-request">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Approve or Reject</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <strong>Approve</strong> = the change is applied. <strong>Reject</strong> = the rental stays the same.
                    <strong>Click either one.</strong> This is practice, safe to try.
                </p>
            </div>

            <div class="rental-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">What the Admin sees while waiting</h3>
                <p class="text-sm text-gray-600 leading-relaxed mb-3">
                    Before you decide, the Admin still sees the old data, and can cancel it themselves anytime.
                </p>
                <div class="rounded-lg border border-amber-400 bg-amber-50 px-3 py-2.5 text-sm text-amber-950">
                    <p class="font-semibold">Waiting for a manager to approve</p>
                    <p class="text-xs mt-1 text-amber-900/90">This rent won't change until then.</p>
                    <span class="mt-2 inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700">Cancel request</span>
                </div>
            </div>

            <div class="rental-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Done! The summary</h3>
                <p class="text-sm text-gray-600 leading-relaxed mb-3">
                    In short: <strong>edit a rental &rarr; it arrives here &rarr; you Approve/Reject &rarr; the requester
                    sees the result</strong>.
                </p>
                <p class="text-xs text-gray-400">
                    Removing an item from a rental that has already been paid works a bit differently — there is a separate
                    guide, check <strong>"How item removal works"</strong> in the sidebar.
                </p>
            </div>
        @endif
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>

rentalCreateTour({
    idPrefix: 'rentalTour',
    stepClass: 'rental-tour-step',
    storageKey: 'rental_tour_seen_v1_{{ $tourUser->id }}',
    resumeKey: 'rental_tour_resume_v1_{{ $tourUser->id }}',
    triggerSelector: '[data-open-approval-tour]',
    openFnName: 'rentalOpenApprovalTour',
    debugFnName: 'rentalTourJumpTo',
    autoOpenOnFirstVisit: true,
    tutorialSeedConfig: {
        'edit-request': { navLinkId: 'rentalTourNavEditRequests', endpoint: @json(route('approvals.tutorial-seed-edit-request')) },
    },
});
</script>
@endif
