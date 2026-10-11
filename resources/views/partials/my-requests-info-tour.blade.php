@php
    $infoTourUser = Auth::user();
    $infoTourIsDecider = $infoTourUser->isManagement() || $infoTourUser->isSupervisor() || $infoTourUser->isSuperAdmin();
@endphp
@if($infoTourIsDecider)
{{-- Standalone, manually-triggered explainer for Supervisor/Management about what an
     approval request is — separate from the main "How approvals work" guided tour
     (approval-tour.blade.php), which walks through real actions step by step. This one
     is purely informational (no real targets to spotlight, no real actions to perform),
     so it skips that engine's positioning/spotlight/resume machinery entirely and is
     just a small centered modal, manually opened every time from the sidebar. --}}
<div id="rentalInfoTourOverlay" class="hidden fixed inset-0 z-[210] bg-black/50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md flex flex-col overflow-hidden">
        <div class="flex items-center justify-between px-5 pt-5 shrink-0">
            <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
                What is an approval request?
                <span id="rentalInfoTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
            </p>
            <button type="button" id="rentalInfoTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
        </div>

        <div id="rentalInfoTourSteps" class="px-5 pb-2 pt-3">
            <div class="rental-info-tour-step">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">What is an approval request?</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    A request that must be approved before it is applied to a rental. It appears for risky changes,
                    for example removing an item from a rental that has already been paid.
                </p>
            </div>

            <div class="rental-info-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">When you are the requester</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Removing an item from a paid rental also needs approval from another superior, even if you are
                    a Supervisor/Management. That request appears in <strong>My Requests</strong> with Pending status.
                </p>
            </div>

            <div class="rental-info-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">Request statuses</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    <strong>Pending</strong>: still waiting. <strong>Approved</strong>: applied.
                    <strong>Rejected</strong>: not applied, the rental stays as it was. You can cancel it yourself
                    anytime while it is still Pending.
                </p>
            </div>

            <div class="rental-info-tour-step hidden">
                <h3 class="text-lg font-semibold text-gray-900 mb-2">When you are the reviewer</h3>
                <p class="text-sm text-gray-600 leading-relaxed">
                    Requests from Admin arrive in <strong>Edit Requests</strong> or <strong>Removal
                    Requests</strong> in the sidebar, when you are the one who has to approve or reject them.
                </p>
            </div>
        </div>

        <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
            <div class="flex items-center gap-1.5" id="rentalInfoTourDots" aria-hidden="true"></div>
            <div class="flex items-center gap-2">
                <button type="button" id="rentalInfoTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
                <button type="button" id="rentalInfoTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var overlay = document.getElementById('rentalInfoTourOverlay');
    var stepEls = Array.prototype.slice.call(document.querySelectorAll('.rental-info-tour-step'));
    var total = stepEls.length;
    var current = 0;
    var backBtn = document.getElementById('rentalInfoTourBack');
    var nextBtn = document.getElementById('rentalInfoTourNext');
    var skipBtn = document.getElementById('rentalInfoTourSkip');
    var dotsWrap = document.getElementById('rentalInfoTourDots');
    var stepCounterEl = document.getElementById('rentalInfoTourStepCounter');

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

    document.querySelectorAll('[data-open-info-tour]').forEach(function (btn) {
        btn.addEventListener('click', open);
    });
})();
</script>
@endif
