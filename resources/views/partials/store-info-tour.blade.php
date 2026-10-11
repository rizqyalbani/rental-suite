{{-- Standalone, manually-triggered explainer for what a Store is and how store
     scoping works — purely informational (no real targets to spotlight, no real
     actions to perform), same lightweight modal pattern as my-requests-info-tour.blade.php
     and role-info-tour.blade.php. Available to every role — understanding why data is
     split per store matters even for roles that can't manage store assignment. --}}
<div id="rentalStoreInfoTourOverlay" class="hidden fixed inset-0 z-[210] bg-black/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-5 shrink-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
                What is a Store?
                <span id="rentalStoreInfoTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
            </p>
            <button type="button" id="rentalStoreInfoTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
        </div>

        <div id="rentalStoreInfoTourSteps" class="px-5 pb-2 pt-3">
            <div class="rental-store-info-tour-step">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">What is a Store?</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    A Store is a separate physical store branch/location in this system — each one has its own name,
                    logo, contact details, items, and reports.
                </p>
            </div>

            <div class="rental-store-info-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">What does that affect?</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Items, rentals, and shifts are all tied to one store. If your active store is
                    store A, you <strong>won't see</strong> the items or rentals of store B, and the other way
                    around. New rentals are automatically recorded to whichever store is active when they're created.
                </p>
            </div>

            <div class="rental-store-info-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Who can access which store?</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Admin and Supervisor can only access the stores they're assigned to — if they aren't assigned
                    to any, they default to the first store automatically. <strong>Management and Super Admin can
                    access all stores</strong>, including the "All stores" mode, which combines the data from all
                    stores at once.
                </p>
            </div>

            <div class="rental-store-info-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Switching stores &amp; who manages assignments</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    The active store can be switched with the store picker. Only <strong>Management and Super Admin</strong>
                    can create new stores and decide which staff are assigned to which store, on the
                    Stores page (Settings menu).
                </p>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
            <div class="flex items-center gap-1.5" id="rentalStoreInfoTourDots" aria-hidden="true"></div>
            <div class="flex items-center gap-2">
                <button type="button" id="rentalStoreInfoTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
                <button type="button" id="rentalStoreInfoTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('rentalStoreInfoTourOverlay');
    var stepEls = Array.prototype.slice.call(document.querySelectorAll('.rental-store-info-tour-step'));
    var total = stepEls.length;
    var current = 0;
    var backBtn = document.getElementById('rentalStoreInfoTourBack');
    var nextBtn = document.getElementById('rentalStoreInfoTourNext');
    var skipBtn = document.getElementById('rentalStoreInfoTourSkip');
    var dotsWrap = document.getElementById('rentalStoreInfoTourDots');
    var stepCounterEl = document.getElementById('rentalStoreInfoTourStepCounter');

    for (var i = 0; i < total; i++) {
        var dot = document.createElement('span');
        dot.className = 'h-1.5 w-1.5 rounded-full bg-gray-300';
        dotsWrap.appendChild(dot);
    }

    function render() {
        stepEls.forEach(function (el, idx) { el.classList.toggle('hidden', idx !== current); });
        Array.prototype.forEach.call(dotsWrap.children, function (dot, idx) {
            dot.className = 'h-1.5 w-1.5 rounded-full ' + (idx === current ? 'bg-indigo-600' : 'bg-gray-300');
        });
        backBtn.classList.toggle('hidden', current === 0);
        nextBtn.textContent = current === total - 1 ? 'Got it' : 'Next';
        stepCounterEl.textContent = '(' + (current + 1) + '/' + total + ')';
    }

    function open() {
        current = 0;
        overlay.classList.remove('hidden');
        document.body.style.overflow = 'hidden';
        render();
    }

    function close() {
        overlay.classList.add('hidden');
        document.body.style.overflow = '';
    }

    nextBtn.addEventListener('click', function () {
        if (current === total - 1) { close(); return; }
        current++;
        render();
    });
    backBtn.addEventListener('click', function () {
        if (current > 0) { current--; render(); }
    });
    skipBtn.addEventListener('click', close);
    overlay.addEventListener('click', function (e) { if (e.target === overlay) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !overlay.classList.contains('hidden')) close();
    });

    document.querySelectorAll('[data-open-store-info-tour]').forEach(function (btn) {
        btn.addEventListener('click', open);
    });
})();
</script>
