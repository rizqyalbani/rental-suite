@if(Auth::check() && Auth::user()->mayParticipateInStockOpname())
{{-- Separate from stock-opname-tour.blade.php on purpose — that one covers the
     in-app "Scan QR" button on the Stock Opname page (a real camera opened
     inside this app). This one covers scanning an item's printed QR with
     ANY other camera (Google Lens, the phone's own Camera app, a second
     phone) — the resulting page (OutfitController::renderItemStatus /
     outfits/status.blade.php) is a wholly separate, standalone HTML document
     with no sidebar and no tour-engine script of its own, so it can never be
     spotlighted live from inside this app. Every step here is a mockup
     (data-wide-card) built to look like that real page instead. --}}
<div id="rentalScanTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalScanTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalScanTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalScanTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            Scan a QR from Outside the App
            <span id="rentalScanTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalScanTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalScanTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-scan-tour-step" data-step="0">
            <h3 id="rentalScanTourTitle" class="text-lg font-semibold text-gray-900 mb-2">Different from the "Scan QR" button in Stock Opname</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                The other Stock Opname guide shows the <strong>"Scan QR"</strong> button inside the app — the camera
                opens inside that page. This is different: when someone scans with <strong>another camera app</strong>
                (Google Lens, the phone's built-in Camera app, someone else's phone), the link opens in a browser —
                completely outside this app.
            </p>
            <p class="text-sm text-amber-800 bg-amber-50 border border-amber-200 rounded-md px-3 py-2 leading-relaxed mt-3">
                <strong>Important:</strong> that store's opname session must <strong>already be started</strong>
                (Supervisor and above, from the Stock Opname page) before this is of any use. If no session is running,
                the scan only shows the normal item info — there are no marking buttons at all.
            </p>
        </div>

        <div class="rental-scan-tour-step hidden" data-wide-card="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Every item has its own QR</h3>
            <p class="text-sm text-gray-600 leading-relaxed mb-3">
                It exists automatically as soon as the item is created — no need to generate it manually. Print it from
                the <strong>Items</strong> page:
            </p>
            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-lg border border-gray-200 p-3">
                    <p class="text-xs font-semibold text-gray-700">One item</p>
                    <p class="text-xs text-gray-500 mt-1 leading-relaxed">Click the <strong>"QR"</strong> button on the
                        item's row → the QR image appears → <strong>"Download PNG for print"</strong>.</p>
                </div>
                <div class="rounded-lg border border-gray-200 p-3">
                    <p class="text-xs font-semibold text-gray-700">All at once</p>
                    <p class="text-xs text-gray-500 mt-1 leading-relaxed">The <strong>"Export all QR (ZIP)"</strong> button
                        above the Items table — one ZIP with the QRs of all items, ready to print.</p>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-3">This QR is permanent — print it once, no need to reprint even if the item's data changes.</p>
        </div>

        <div class="rental-scan-tour-step hidden" data-wide-card="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">What shows up once it's scanned</h3>
            <p class="text-sm text-gray-600 leading-relaxed mb-3">
                This page is <strong>outside the app</strong> — no sidebar, and no login needed to see the basic
                info. Anyone who scans it (including customers) can see this:
            </p>
            <div class="rounded-xl border border-gray-200 p-4 bg-gray-50">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-[10px] uppercase tracking-wide text-gray-500">Item Availability</p>
                        <p class="text-lg font-bold text-gray-900 mt-0.5">BR 12</p>
                        <p class="text-xs text-gray-600 mt-0.5">Silver Floral</p>
                    </div>
                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold bg-green-100 text-green-700">Available</span>
                </div>
                <div class="grid grid-cols-3 gap-2 mt-3">
                    <div class="rounded-md border border-gray-200 bg-white p-2 text-center">
                        <p class="text-[9px] text-gray-500 uppercase">Stock</p>
                        <p class="text-sm font-semibold">1</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-white p-2 text-center">
                        <p class="text-[9px] text-gray-500 uppercase">Active Rent</p>
                        <p class="text-sm font-semibold">0</p>
                    </div>
                    <div class="rounded-md border border-gray-200 bg-white p-2 text-center">
                        <p class="text-[9px] text-gray-500 uppercase">Available Now</p>
                        <p class="text-sm font-semibold">1</p>
                    </div>
                </div>
            </div>
            <p class="text-xs text-gray-400 mt-2">Example display (not a real item) — just for illustration.</p>
        </div>

        <div class="rental-scan-tour-step hidden" data-wide-card="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">When an opname session is running</h3>
            <p class="text-sm text-gray-600 leading-relaxed mb-3">
                Below the item info, a section to log in appears — <strong>but only if</strong> that store's opname
                session is currently running. There are 2 ways to log in, switchable with the tabs:
            </p>
            <div class="rounded-xl border border-gray-200 p-4 bg-gray-50">
                <div class="flex items-center justify-between gap-3 mb-3">
                    <p class="text-xs text-gray-600">Staff? Log in to mark this item.</p>
                    <div class="flex text-[11px] border border-gray-300 rounded-md overflow-hidden bg-white">
                        <span class="px-2 py-1 bg-gray-800 text-white">PIN</span>
                        <span class="px-2 py-1 border-l border-gray-300 text-gray-600">Username &amp; Password</span>
                    </div>
                </div>
                <div class="flex gap-2 justify-end">
                    <span class="w-24 px-2 py-1.5 text-xs border border-gray-300 rounded-md text-center tracking-widest text-gray-400 bg-white">6-digit PIN</span>
                    <span class="px-3 py-1.5 text-xs rounded-md bg-gray-800 text-white">Log in</span>
                </div>
            </div>
            <p class="text-sm text-gray-600 leading-relaxed mt-3">
                <strong>6-digit PIN</strong> — quick, set by each staff member on the Profile page, different from the
                account password. <strong>Username &amp; Password</strong> — the full account normally used to log in.
                Either way you go straight back to this item's page, not to the Dashboard.
            </p>
        </div>

        <div class="rental-scan-tour-step hidden" data-wide-card="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Once logged in, you can mark it right away</h3>
            <div class="rounded-xl border border-gray-200 p-4 bg-gray-50">
                <div class="flex items-center justify-between gap-2 mb-2">
                    <p class="text-sm font-semibold text-gray-800">Stock Opname Session in Progress</p>
                    <span class="px-2 py-0.5 text-[10px] font-semibold rounded-full bg-gray-100 text-gray-500">Unchecked</span>
                </div>
                <p class="text-[11px] text-gray-500 mb-3">Mark this item — logged in as <strong>Made (Admin)</strong>.</p>
                <div class="flex gap-1.5">
                    <span class="flex-1 text-center px-2 py-1.5 text-xs rounded-md border border-gray-300 bg-white">Present</span>
                    <span class="flex-1 text-center px-2 py-1.5 text-xs rounded-md border border-gray-300 bg-white">Missing</span>
                    <span class="flex-1 text-center px-2 py-1.5 text-xs rounded-md border border-gray-300 bg-white">Damaged</span>
                </div>
                <p class="text-[10px] text-gray-400 mt-2 text-right">Not Made? Log out</p>
            </div>
            <p class="text-sm text-gray-600 leading-relaxed mt-3">
                One tap and it's saved right away — <strong>exactly the same data</strong> that shows up on the
                regular Stock Opname page, connected in real time, not a separate system.
            </p>
        </div>

        <div class="rental-scan-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">In short</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                An item's QR can be scanned with any camera, with no need to open this app first. But if that store
                <strong>has no opname session started</strong>, the scan only shows the plain item info — start the
                session first from the Stock Opname page (Supervisor and above), and then this method works.
            </p>
            <p class="text-sm text-gray-600 leading-relaxed mt-2">
                Click the "?" button on the Help page anytime if you want to see this again.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalScanTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalScanTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalScanTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalScanTour',
    stepClass: 'rental-scan-tour-step',
    storageKey: 'rental_scan_tour_seen_v1_{{ Auth::id() }}',
    resumeKey: 'rental_scan_tour_resume_v1_{{ Auth::id() }}',
    triggerSelector: '[data-open-scan-tour]',
    openFnName: 'rentalOpenScanTour',
    debugFnName: 'rentalScanTourJumpTo',
    autoOpenOnFirstVisit: false,
});
</script>
@endif
