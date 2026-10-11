@if(Auth::check() && Auth::user()->mayParticipateInStockOpname())
{{-- Standalone walkthrough for Stock Opname — REAL interactive practice, not a
     read-only mockup: the "Start Practice Session" step (below) starts a genuine
     StockOpname row tagged is_tutorial_practice=true, scoped to the store's
     is_tutorial=true practice Outfits (e.g. "Practice Broom (Tutorial)") instead
     of its real inventory. The rest of the tour then spotlights the REAL show
     page — the user really clicks Missing/Damaged (a real fetch, real badge
     update) and really clicks Complete Opname (a real submit + confirm).
     Tagging the session is_tutorial_practice keeps it out of the real
     history list and out of the one-active-session-per-store check
     (StockOpnameController@index/store), so practicing never blocks — or is
     blocked by — a real session. See StockOpnameController::startTutorial()
     and ::tutorialCleanup(). --}}
<div id="rentalStockOpnameTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalStockOpnameTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalStockOpnameTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalStockOpnameTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            Stock Opname
            <span id="rentalStockOpnameTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalStockOpnameTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalStockOpnameTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-stock-opname-tour-step" data-step="0">
            <h3 id="rentalStockOpnameTourTitle" class="text-lg font-semibold text-gray-900 mb-2">What is Stock Opname for?</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                A way to match the <strong>physical items in the store</strong> against the data in the system — which
                are still there, which are missing or damaged. The result is saved as history, which can be viewed
                again anytime.
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                <strong>Admin</strong> can help mark the items being checked. <strong>Starting and completing</strong>
                a session is for Supervisor and above only.
            </p>
            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                This guide is practiced for real, not just viewed — there will be a special practice session for you
                to try on your own, safe and not affecting the store's real data.
            </p>
        </div>

        <div class="rental-stock-opname-tour-step hidden" data-wide-card="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Who can do what</h3>
            <div class="rounded-lg border border-gray-200 overflow-hidden text-sm">
                <table class="min-w-full divide-y divide-gray-200">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Start Session</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Mark Items</th>
                            <th class="px-3 py-2 text-center text-xs font-medium text-gray-500 uppercase">Complete</th>
                            <th class="px-3 py-2 text-left text-xs font-medium text-gray-500 uppercase">Store</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-gray-200">
                        <tr>
                            <td class="px-3 py-2 font-medium">Admin</td>
                            <td class="px-3 py-2 text-center text-gray-300">—</td>
                            <td class="px-3 py-2 text-center text-green-600">✓</td>
                            <td class="px-3 py-2 text-center text-gray-300">—</td>
                            <td class="px-3 py-2 text-gray-500">Assigned stores</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 font-medium">Supervisor</td>
                            <td class="px-3 py-2 text-center text-green-600">✓</td>
                            <td class="px-3 py-2 text-center text-green-600">✓</td>
                            <td class="px-3 py-2 text-center text-green-600">✓</td>
                            <td class="px-3 py-2 text-gray-500">Assigned stores</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 font-medium">Management</td>
                            <td class="px-3 py-2 text-center text-green-600">✓</td>
                            <td class="px-3 py-2 text-center text-green-600">✓</td>
                            <td class="px-3 py-2 text-center text-green-600">✓</td>
                            <td class="px-3 py-2 text-gray-500">All stores</td>
                        </tr>
                        <tr>
                            <td class="px-3 py-2 font-medium">Super Admin</td>
                            <td class="px-3 py-2 text-center text-green-600">✓</td>
                            <td class="px-3 py-2 text-center text-green-600">✓</td>
                            <td class="px-3 py-2 text-center text-green-600">✓</td>
                            <td class="px-3 py-2 text-gray-500">All stores</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p class="text-xs text-gray-400 mt-2 leading-relaxed">
                Accounting has no access to Stock Opname at all. Super Admin is also the only one who can
                use "Mark All" (fills in all items that are still Unchecked at once) to close a session quickly.
            </p>
        </div>

        <div class="rental-stock-opname-tour-step hidden" data-target="#rentalTourNavStockOpname">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Getting here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Stock Opname"</strong> in the sidebar.
            </p>
        </div>

        <div class="rental-stock-opname-tour-step hidden" data-target="#stockOpnameIndexCard" data-target-readonly="1" data-scroll-block="start">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">This store's opname history</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                The table here keeps <strong>all opname sessions</strong> this store has ever done — when it was
                started, who started it, whether it's finished or not, and how big a difference was found.
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                In the next step we'll try a special <strong>practice session</strong> — it only has 2 sample items
                marked "(Tutorial)", so it's safe to try and won't show up in this history table.
            </p>
        </div>

        <div class="rental-stock-opname-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Start a practice session</h3>
            <p class="text-sm text-gray-600 leading-relaxed mb-3">
                This is a private session for you to try on your own — not the store's real opname session, so it
                doesn't block anyone who wants to start a real opname.
            </p>
            <button type="button" id="rentalStockOpnameTourStartPractice"
                class="w-full px-4 py-2.5 bg-indigo-600 hover:bg-indigo-700 text-white text-sm font-semibold rounded-md cursor-pointer disabled:opacity-60 disabled:cursor-wait">
                Start Practice Session Now
            </button>
        </div>

        <div class="rental-stock-opname-tour-step hidden" data-target="#opnameTable tbody tr:first-child [data-status-buttons]" data-auto-advance="opname-marked" data-scroll-block="start">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Try marking this item</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                All items are <strong>"Unchecked"</strong> by default — they are not automatically assumed to be "Present".
                Each one has to be actually checked and marked one by one, so nobody just clicks through without looking
                at the item.
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                <strong>Click "Missing" or "Damaged"</strong> for real on this row. A small box appears for an
                optional note (can be left empty) — once it's saved, the status changes right away.
            </p>
        </div>

        <div class="rental-stock-opname-tour-step hidden" data-target="#opnameTable tbody tr:nth-child(2) [data-status-buttons]" data-auto-advance="opname-marked" data-scroll-block="start">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Photo evidence for Damaged items</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Try clicking <strong>"Damaged"</strong> on this second item. A photo as evidence is <strong>required</strong> —
                on a phone, the camera opens right after you click; on a computer, just upload a photo file. For
                <strong>"Missing"</strong> the photo is still optional (makes sense, the item isn't there to be
                photographed).
            </p>
            <p class="text-xs text-gray-400 mt-2 leading-relaxed">
                The photo is stored safely — it can only be seen by fellow staff taking part in the opname, and it isn't on
                any public page. Editing the note later doesn't require uploading the photo again.
            </p>
        </div>

        <div class="rental-stock-opname-tour-step hidden" data-target="#opnameSearch" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Nice, it's marked</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Items that <strong>are actually there</strong> also have to be clicked "Present" one by one, not left as
                "Unchecked" — that's the only way to know they've really been physically checked.
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                Marked wrongly? Click another status anytime to change it — for example click <strong>"Present"</strong> to
                fix an item that was wrongly marked Missing/Damaged.
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                There is a search box above the table to find a specific item code/name — no need to scroll manually
                when there are many items.
            </p>
        </div>

        <div class="rental-stock-opname-tour-step hidden" data-target="#stockOpnameCompleteBtn" data-scroll-block="start">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Complete the Opname</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                When everything has been checked, click <strong>"Complete Opname"</strong> (you can add a session note
                first if needed), then confirm. For this practice session you can go ahead and try it — for a real
                session, this is for Supervisor and above only.
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                Once completed, the session becomes <strong>read-only</strong> — it only shows the items with problems, and can't
                be re-marked. Marking an item <strong>doesn't automatically change anything</strong> in the system
                (items are not deleted/deactivated by themselves) — it's purely a record/report to be reviewed manually.
            </p>
        </div>

        <div class="rental-stock-opname-tour-step hidden" data-target="#stockOpnameDownloadPdfLink" data-target-readonly="1" data-scroll-block="start">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Download the PDF report</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Once the session is completed, a <strong>"Download PDF"</strong> link appears here — it contains the
                session summary plus the list of Missing/Damaged items (including their photos, if any), ready to print
                or send to the store owner.
            </p>
        </div>

        <div class="rental-stock-opname-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">In case you forget to close it</h3>
            <p class="text-sm text-gray-600 leading-relaxed mb-3">
                A session left open for more than <strong>3 days</strong> without being completed triggers a reminder on
                the Dashboard — for Supervisor and above, so that no session is left "hanging" for too long.
            </p>
            <div class="border border-amber-200 bg-amber-50 rounded-lg p-3 text-sm">
                <p class="font-medium text-amber-800 mb-1">1 Stock Opname session has been open for more than 3 days — don't forget to complete it.</p>
                <p class="text-amber-700 underline text-xs">Demo Store Two — opened 12 Sep 2026 by Staff A (5 days ago)</p>
            </div>
            <p class="text-xs text-gray-400 mt-2">Example display — not real data.</p>
        </div>

        <div class="rental-stock-opname-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                That's how it works — and that was a real opname session, only with practice items in it. The practice
                session is cleaned up automatically as soon as this guide is closed. Click the "?" button on the Stock
                Opname page anytime if you want to see it again.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalStockOpnameTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalStockOpnameTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalStockOpnameTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalStockOpnameTour',
    stepClass: 'rental-stock-opname-tour-step',
    storageKey: 'rental_stock_opname_tour_seen_v1_{{ Auth::id() }}',
    resumeKey: 'rental_stock_opname_tour_resume_v1_{{ Auth::id() }}',
    triggerSelector: '[data-open-stock-opname-tour]',
    openFnName: 'rentalOpenStockOpnameTour',
    debugFnName: 'rentalStockOpnameTourJumpTo',
    autoOpenOnFirstVisit: false,
    cleanupEndpoint: @json(route('stock-opname.tutorial-cleanup')),
});

// #rentalTourNavStockOpname now lives inside the collapsible "Inventory"
// dropdown (Alpine x-show), so it's display:none — and un-measurable/
// unclickable — until that dropdown is expanded. Poll only while this
// tour's own dialog is open, so a hidden link never derails a step this
// tour never asked about.
(function () {
    var overlay = document.getElementById('rentalStockOpnameTourOverlay');
    var toggle = document.getElementById('rentalTourNavInventoryToggle');
    var link = document.getElementById('rentalTourNavStockOpname');
    if (!overlay || !toggle || !link) return;
    setInterval(function () {
        if (overlay.classList.contains('hidden')) return;
        if (link.offsetParent === null) toggle.click();
    }, 300);
})();

// "Start Practice Session Now" isn't a real nav link the engine can wire
// itself (there's nothing to click until the practice session exists) — it
// starts one via startTutorial(), then hands off to the real show page the
// same way a real nav-link click does: persist where to resume, then
// navigate for real.
(function () {
    var btn = document.getElementById('rentalStockOpnameTourStartPractice');
    if (!btn) return;
    btn.addEventListener('click', function () {
        // Captured synchronously, before the fetch below — this is the
        // step containing the button that was just clicked, so its DOM
        // index IS this step's tour index regardless of timing.
        var stepEls = document.querySelectorAll('.rental-stock-opname-tour-step');
        var currentIdx = Array.prototype.indexOf.call(stepEls, btn.closest('.rental-stock-opname-tour-step'));
        btn.disabled = true;
        btn.textContent = 'Preparing…';
        fetch(@json(route('stock-opname.tutorial-start')), {
            method: 'POST',
            headers: {
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').getAttribute('content'),
                'Accept': 'application/json',
            },
        }).then(function (res) { return res.json(); }).then(function (data) {
            if (!data.ok) {
                btn.disabled = false;
                btn.textContent = 'Start Practice Session Now';
                window.Swal && window.Swal.fire({ icon: 'error', title: 'Failed to start', text: data.error || 'Please try again.' });
                return;
            }
            if (currentIdx >= 0) window.rentalStockOpnameTourPersistResume(currentIdx + 1);
            window.location.href = data.redirect;
        }).catch(function () {
            btn.disabled = false;
            btn.textContent = 'Start Practice Session Now';
            window.Swal && window.Swal.fire({ icon: 'error', title: 'Failed to start', text: 'Please try again.' });
        });
    });
})();
</script>
@endif
