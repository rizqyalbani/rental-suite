@if(Auth::check() && (Auth::user()->isManagement() || Auth::user()->isSuperAdmin()))
{{-- Standalone, manually-triggered walkthrough for Store & User management —
     Management/Super Admin only, since those pages are gated the same way. Reuses the
     SAME real-spotlight engine as the other tours (rentalCreateTour(), see
     approval-tour.blade.php) via its own element id prefix, step class, and storage
     keys — this file only supplies content + config, not engine code.

     Unlike the approval/removal tours, this one deliberately avoids the shared
     engine's data-auto-advance/tutorial-seed machinery (which is wired specifically
     to rental/approval creation) — the Add User and Assign User steps use plain
     click-to-advance on the real submit buttons instead, same mechanism as the
     nav-link steps. Real click, real HTTP request, real page reload; the tour
     resumes on the next page exactly like clicking a real sidebar link already
     does elsewhere.

     Isolation for the one genuinely consequential action (creating a real User
     row) comes from a hidden is_tutorial field on the real Add User form
     (resources/views/users/index.blade.php) that this file's own script flips on
     for as long as this tour is open, and a dedicated cleanup endpoint
     (users.tutorial.exit) that sweeps every is_tutorial user — fired when the tour
     closes, however it closes, mirroring shift-tour.blade.php's Practice-session
     cleanup. Assigning that tutorial user to a real store afterward is left going
     through the real assign endpoint unmodified: linking a clearly-tagged, soon-to
     -be-deleted dummy account to a real store has no real-world effect, so it
     doesn't need its own isolated endpoint the way creating the account did. --}}
<div id="rentalStoreUserTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalStoreUserTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalStoreUserTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalStoreUserTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            Manage Stores &amp; Staff
            <span id="rentalStoreUserTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalStoreUserTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalStoreUserTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-store-user-tour-step" data-step="0">
            <h3 id="rentalStoreUserTourTitle" class="text-lg font-semibold text-gray-900 mb-2">Manage Stores &amp; Staff</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                This guide is for <strong>Management &amp; Super Admin</strong>: how to switch the active store, add new
                staff accounts, and give them access to specific stores.
            </p>
            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                Safe to try. Users created here are tagged as practice accounts and are deleted automatically as soon
                as this guide is closed.
            </p>
        </div>

        <div class="rental-store-user-tour-step hidden" data-target="#rentalTourNavStores">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Getting here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Stores"</strong> in the sidebar.
            </p>
        </div>

        <div class="rental-store-user-tour-step hidden" data-target="#storeSwitcherForm" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Switch the active store</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                This store picker is in the bottom-left corner, on every page. Choose another store here and the items,
                rentals, shifts, and reports shown switch to that store automatically.
            </p>
        </div>

        <div class="rental-store-user-tour-step hidden" data-target="#storeAddSection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Adding a new store</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Enter a name and code, then click <strong>Create</strong> to open a new branch. Each store can also
                have its own logo, colors, and contact details set on the card below this list.
            </p>
        </div>

        <div class="rental-store-user-tour-step hidden" data-target="#rentalTourNavUsers">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Now over here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Users"</strong> in the sidebar.
            </p>
        </div>

        <div class="rental-store-user-tour-step hidden" data-target="#addUserSubmitBtn">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Add a new staff member</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Fill in Name, Email, Password, and Role with sample data in the form above, then click <strong>Create
                User</strong> — this is a real click, the practice account is really created (tagged
                <span class="px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">🎓 Practice</span>
                in the list), then it is deleted automatically as soon as this guide is closed.
            </p>
        </div>

        <div class="rental-store-user-tour-step hidden" data-target="#allUsersSection" data-target-readonly="1" data-scroll-block="start">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">It's already there</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                The practice user now shows up in the list, tagged <strong>🎓 Practice</strong>. The
                <strong>Edit</strong> and <strong>Delete</strong> buttons on this row work like they do for a real account.
            </p>
        </div>

        <div class="rental-store-user-tour-step hidden" data-target="#tutorialResetPinBtn" data-scroll-block="start">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Reset a forgotten staff PIN</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                This practice user was given a sample opname PIN, so the <strong>"Reset PIN"</strong> button appears
                on their row — just like for a real staff member who forgot their PIN. <strong>Click it for real</strong> to
                try: the PIN is removed, and that staff member simply sets a new PIN themselves on the Profile page.
            </p>
            <p class="text-xs text-gray-400 mt-2 leading-relaxed">
                Management/Super Admin never see or choose a staff member's PIN — they can only delete it so that
                staff member sets it again themselves.
            </p>
        </div>

        <div class="rental-store-user-tour-step hidden" data-target="#rentalTourNavStores">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Back to Stores</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Stores"</strong> again to give that user access to a store.
            </p>
        </div>

        <div class="rental-store-user-tour-step hidden" data-target="#storeAccessSaveBtnFirst" data-scroll-block="start">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Give access to a store</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                On the first store's card, find your practice user in the <strong>"Users with access"</strong> list,
                tick it, then click <strong>Save Access</strong> — also a real click, the same as giving access
                to a real staff member.
            </p>
        </div>

        <div class="rental-store-user-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                That's how it works. The practice user has now been deleted automatically. You can replay this guide
                anytime from the Help page.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalStoreUserTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalStoreUserTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalStoreUserTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalStoreUserTour',
    stepClass: 'rental-store-user-tour-step',
    storageKey: 'rental_store_user_tour_seen_v1_{{ Auth::id() }}',
    resumeKey: 'rental_store_user_tour_resume_v1_{{ Auth::id() }}',
    triggerSelector: '[data-open-store-user-tour]',
    openFnName: 'rentalOpenStoreUserTour',
    debugFnName: 'rentalStoreUserTourJumpTo',
    autoOpenOnFirstVisit: false,
});

(function () {
    // Flips the real Add User form's hidden is_tutorial field on for as long as
    // this tour is open (regardless of which step), same "session-scoped, not
    // step-scoped" toggle shift-tour.blade.php uses for its own Real/Practice
    // switch — the form is only meaningfully submitted while someone is actively
    // on this tour's Add User step anyway, so step-level precision isn't needed.
    function setTutorialFlag(on) {
        var f = document.getElementById('addUserIsTutorial');
        if (f) f.value = on ? '1' : '0';
    }

    function csrfToken() {
        var meta = document.querySelector('meta[name="csrf-token"]');
        return meta ? meta.getAttribute('content') : '';
    }

    function cleanupTutorialUsers() {
        return fetch(@json(route('users.tutorial.exit')), {
            method: 'POST',
            headers: { 'X-CSRF-TOKEN': csrfToken() },
            credentials: 'same-origin',
            keepalive: true,
        }).catch(function () {});
    }

    document.querySelectorAll('[data-open-store-user-tour]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            setTutorialFlag(true);
        });
    });

    var overlay = document.getElementById('rentalStoreUserTourOverlay');
    if (overlay) {
        var wasOpen = false;
        new MutationObserver(function () {
            var isOpen = ! overlay.classList.contains('hidden');
            if (wasOpen && ! isOpen) {
                setTutorialFlag(false);
                cleanupTutorialUsers();
            }
            wasOpen = isOpen;
        }).observe(overlay, { attributes: true, attributeFilter: ['class'] });
    }

    // The tour can resume on a fresh page load (e.g. right after the real Add
    // User submit reloads to /users) where the overlay starts already-open —
    // the MutationObserver above only catches OPEN→CLOSED transitions from here
    // on, so re-assert the flag on load too in case this page load is resuming
    // mid-tour, past the Add User step's own trigger-click moment.
    if (sessionStorage.getItem('rental_store_user_tour_resume_v1_{{ Auth::id() }}')) {
        setTutorialFlag(true);
    }
})();
</script>
@endif
