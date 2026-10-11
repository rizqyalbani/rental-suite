<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Help &amp; Tutorials
        </h2>
    </x-slot>

    {{-- Plain margin-top on each block, not space-y-* utilities: this project has two
         conflicting Tailwind versions installed (tailwindcss@3 + @tailwindcss/vite@4),
         which silently fails to generate sibling-combinator utilities like space-y-*
         and divide-y (confirmed — computed gap was 0px). Same reasoning as
         activity-log-panel.blade.php. Dividers below use an explicit border-t instead
         of divide-y for the same reason. --}}
    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">

            <div class="max-w-2xl">
                <h1 class="text-lg font-semibold text-gray-900">Practice guides</h1>
                <p class="text-sm text-gray-500 leading-relaxed mt-1">
                    Learn the real app, step by step. Nothing here affects real customer data.
                </p>
            </div>

            @if(in_array(auth()->user()->normalizedRole(), ['admin', 'management', 'super_admin'], true))
            <div class="mt-10">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-4">System readiness test</h3>
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                    <button type="button" data-start-readiness
                        class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900">System readiness test</p>
                            <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">An A-to-Z checklist of tests run after
                                every update. It only reads real data; write tests go through the practice guides. The
                                checklist panel floats on screen and records any errors that appear.</p>
                        </div>
                        <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                    </button>
                </div>
            </div>
            @endif

            <div class="mt-10">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-4">All roles</h3>
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                    <button type="button" data-open-approval-tour
                        class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900">How approvals work</p>
                            <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">Walks you live through the rental
                                approval flow for your role (Admin, Supervisor, or Management).</p>
                        </div>
                        <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                    </button>
                    <button type="button" data-open-late-done-tour
                        class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900">Forgot to click pickup / return</p>
                            <p class="text-sm text-gray-600 mt-1.5 leading-relaxed">How to report an item that was already
                                picked up or returned but not clicked, so it is not charged a wrong late fee.</p>
                        </div>
                        <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                    </button>
                    <button type="button" data-open-activity-log-tour
                        class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900">What is the Activity Log?</p>
                            <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">What the Activity Log is and how to
                                reach it: where every rental change is recorded, plus the two ways to open it.</p>
                        </div>
                        <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                    </button>
                    <button type="button" data-open-role-info-tour
                        class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900">Admin, Supervisor &amp; Management</p>
                            <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">What each role actually does:
                                why Admin changes need approval but Supervisor and Management changes do not, what
                                neither Admin nor Supervisor can do, and how Management differs from both.</p>
                        </div>
                        <span class="shrink-0 text-indigo-600 text-sm font-medium">View &rarr;</span>
                    </button>
                    <button type="button" data-open-store-info-tour
                        class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900">What is a Store?</p>
                            <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">What a Store is, why
                                items/rentals/shifts are separated per store, who can access which store, and who
                                manages store assignments.</p>
                        </div>
                        <span class="shrink-0 text-indigo-600 text-sm font-medium">View &rarr;</span>
                    </button>
                    <a href="{{ route('help.release-notes') }}"
                        class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                        <div class="min-w-0">
                            <p class="font-semibold text-gray-900">Release Notes</p>
                            <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">New features and fixes added to the
                                system, from the latest back to the older history.</p>
                        </div>
                        <span class="shrink-0 text-indigo-600 text-sm font-medium">Read &rarr;</span>
                    </a>
                </div>
            </div>

            @if($canOperateShifts)
                @php
                    // Names only the viewer's own role, not every role this
                    // section is shared with — a static "Admin & Supervisor"
                    // label was technically wrong for Super Admin (also
                    // covered by $canOperateShifts, i.e. "! isManagement()",
                    // but never named in that string).
                    $shiftSectionRoleLabel = auth()->user()->isSupervisor()
                        ? 'Supervisor'
                        : (auth()->user()->isSuperAdmin() ? 'Super Admin' : 'Admin');
                @endphp
                <div class="mt-10">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-4">{{ $shiftSectionRoleLabel }}</h3>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                        <button type="button" data-open-shift-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">Open, close, and report a shift</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">Actually open and close a practice
                                    shift with sample transactions, complete with its report: safe and separate from
                                    real shifts and reports.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                        </button>
                        <button type="button" data-open-store-info-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">Which store does my expense go to?</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">If you are assigned to more than
                                    1 store: an expense you record always goes to the currently active store, not
                                    automatically to all stores. How to check and switch the active store is shown here.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">View &rarr;</span>
                        </button>
                    </div>
                </div>
            @endif

            @if($isDecider)
                @php
                    // Same reasoning as $shiftSectionRoleLabel above — a
                    // static "Supervisor & Management" label never named
                    // Super Admin, even though $isDecider covers them too.
                    $deciderSectionRoleLabel = auth()->user()->isSupervisor()
                        ? 'Supervisor'
                        : (auth()->user()->isSuperAdmin() ? 'Super Admin' : 'Management');
                @endphp
                <div class="mt-10">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-4">{{ $deciderSectionRoleLabel }}</h3>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                        <button type="button" data-open-removal-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">How item removal works</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">Approving a request to remove
                                    an item from an already paid rental, including the money details.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                        </button>
                        <button type="button" data-open-info-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">What is an approval request?</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">A short explanation of why some
                                    changes need someone else's approval.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">View &rarr;</span>
                        </button>
                    </div>
                </div>
            @endif

            @if(Auth::user()->mayParticipateInStockOpname())
                <div class="mt-10">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-4">Stock Opname</h3>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                        <button type="button" data-open-stock-opname-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">How Stock Opname works</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">Match the physical items in the
                                    store against system data: how to start a session, mark missing/damaged items
                                    (including photo attachments), finish the opname, and download its PDF report.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                        </button>
                        <button type="button" data-open-scan-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">Scanning a QR from outside the app</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">When someone scans an item's QR
                                    with another camera (Google Lens, etc.) instead of the Scan QR button inside the
                                    app. An opname session must be started first.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">View &rarr;</span>
                        </button>
                    </div>
                </div>
            @endif

            @if($isStoreManager)
                @php
                    $storeManagerSectionRoleLabel = auth()->user()->isSuperAdmin() ? 'Super Admin' : 'Management';
                @endphp
                <div class="mt-10">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-4">{{ $storeManagerSectionRoleLabel }}</h3>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                        <button type="button" onclick="window.rentalOpenWhatsNewAccounting && window.rentalOpenWhatsNewAccounting()"
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">
                                    New feature: Accounting Module
                                    <span class="ml-1.5 align-middle inline-block rounded-full bg-indigo-100 px-2 py-0.5 text-[10px] font-semibold text-indigo-700">New</span>
                                </p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">A short summary of what was just
                                    added: Dashboard, Chart of Accounts, Expenses, Cash In/Out, and 4 financial
                                    reports.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">View &rarr;</span>
                        </button>
                        <button type="button" data-open-store-user-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">Manage Stores &amp; Staff</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">How to switch the active store,
                                    add new staff accounts, give access to stores, and reset a staff member's forgotten
                                    opname PIN. It really creates a practice account and assigns it, marked safe and
                                    deleted automatically once the guide is closed.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                        </button>
                    </div>
                </div>
            @endif

            @if($mayAccounting)
                <div class="mt-10">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-4">Accounting</h3>
                    <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                        <button type="button" data-open-accounting-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">What is Accounting?</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">What the Dashboard, Chart of
                                    Accounts, Expenses, and Cash In/Out are for, and how to use each page.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                        </button>
                        <button type="button" data-open-financial-reports-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">What are Financial Reports?</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">What Trial Balance,
                                    Balance Sheet, Profit &amp; Loss, and General Ledger are for: where the data comes
                                    from, what it is used for, and how to read it.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                        </button>
                        <button type="button" data-open-fixed-assets-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">What are Fixed Assets?</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">How to register items (brooms,
                                    hangers, etc.), record their depreciation without choosing accounts manually, and
                                    see their history per item.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                        </button>
                        <button type="button" data-open-ppn-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">What is VAT (Preview)?</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">How to see the estimated VAT
                                    to be remitted for a date range, without recording or changing any data.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                        </button>
                        <a href="{{ route('help.accounting-terms') }}"
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">Accounting Terms</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">A reference of terms (Chart of
                                    Accounts, Journal, Entry Correction, etc.), what is recorded automatically vs
                                    manually, and access rights per role, suited for the accounting team/accountant.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">Read &rarr;</span>
                        </a>
                        <button type="button" data-open-piutang-tour
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">What are Receivables?</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">How to see which rentals still
                                    owe money (deposit paid, not fully paid) and how much is left.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">Start &rarr;</span>
                        </button>
                        @if($canViewPpnBriefing)
                        <a href="{{ route('help.ppn-briefing') }}"
                            class="w-full flex items-center justify-between gap-4 p-5 text-left hover:bg-gray-50 cursor-pointer border-t border-gray-100">
                            <div class="min-w-0">
                                <p class="font-semibold text-gray-900">VAT Questions for the Client</p>
                                <p class="text-sm text-gray-500 mt-1.5 leading-relaxed">A checklist of 10 questions to
                                    ask before real VAT posting is built, with fields to fill in the answers, saved
                                    for follow-up.</p>
                            </div>
                            <span class="shrink-0 text-indigo-600 text-sm font-medium">Fill in &rarr;</span>
                        </a>
                        @endif
                    </div>
                </div>
            @endif

        </div>
    </div>
</x-app-layout>
