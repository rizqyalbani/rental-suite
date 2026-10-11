{{-- Standalone, manually-triggered explainer for what Admin, Supervisor, and Management
     each do — purely informational (no real targets to spotlight, no real actions to
     perform), so it skips the guided-tour engine's positioning/spotlight/resume
     machinery entirely, same as my-requests-info-tour.blade.php. Available to every
     role, since Super Admin may also want to reference it when explaining the roles
     to staff. --}}
<div id="rentalRoleInfoTourOverlay" class="hidden fixed inset-0 z-[210] bg-black/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-5 shrink-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
                Admin, Supervisor &amp; Management
                <span id="rentalRoleInfoTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
            </p>
            <button type="button" id="rentalRoleInfoTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
        </div>

        <div id="rentalRoleInfoTourSteps" class="px-5 pb-2 pt-3">
            <div class="rental-role-info-tour-step">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Why are there 3 roles?</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    All three can be involved in rental operations, but in different portions: who does the
                    daily transactions, who approves changes after a rental is created, and who oversees all
                    stores at once.
                </p>
            </div>

            <div class="rental-role-info-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Admin</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Handles the daily operations: creating new rentals, receiving payments, printing receipts.
                    But changing an existing rental (discount, dates, items) or cancelling it
                    <strong>doesn't take effect right away</strong> — it goes into the approval queue first, waiting
                    for a Supervisor or Management to approve.
                </p>
            </div>

            <div class="rental-role-info-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Supervisor</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Can do everything an Admin can, but their changes <strong>take effect immediately</strong> — no need
                    to wait for approval. A Supervisor is also one of the roles that approve or reject
                    requests from Admin, and approve the removal of items from rentals that have already been paid.
                </p>
            </div>

            <div class="rental-role-info-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Management</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    An oversight role, not a daily operator — <strong>can't open/close shifts themselves</strong>,
                    they only see a summary of who is on shift across all stores. But like a Supervisor,
                    their changes take effect immediately and they can approve/reject Admin requests as well as
                    item removals. Management is also the only role (besides Super Admin) that can
                    change item/inventory data, and can see data in <strong>all stores</strong>, not
                    only the stores they're assigned to.
                </p>
            </div>

            <div class="rental-role-info-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">What Admin &amp; Supervisor can't do</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Neither Admin nor Supervisor can change item/inventory data, and both
                    only see the data of the stores they're assigned to — not all stores like
                    Management/Super Admin.
                </p>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
            <div class="flex items-center gap-1.5" id="rentalRoleInfoTourDots" aria-hidden="true"></div>
            <div class="flex items-center gap-2">
                <button type="button" id="rentalRoleInfoTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
                <button type="button" id="rentalRoleInfoTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('rentalRoleInfoTourOverlay');
    var stepEls = Array.prototype.slice.call(document.querySelectorAll('.rental-role-info-tour-step'));
    var total = stepEls.length;
    var current = 0;
    var backBtn = document.getElementById('rentalRoleInfoTourBack');
    var nextBtn = document.getElementById('rentalRoleInfoTourNext');
    var skipBtn = document.getElementById('rentalRoleInfoTourSkip');
    var dotsWrap = document.getElementById('rentalRoleInfoTourDots');
    var stepCounterEl = document.getElementById('rentalRoleInfoTourStepCounter');

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

    document.querySelectorAll('[data-open-role-info-tour]').forEach(function (btn) {
        btn.addEventListener('click', open);
    });
})();
</script>
