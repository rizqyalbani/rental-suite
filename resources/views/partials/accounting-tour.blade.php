@if(Auth::check() && (Auth::user()->mayViewAccountingDashboard() || Auth::user()->mayManageChartOfAccounts() || Auth::user()->mayRecordAccountingEntries()))
{{-- Standalone, manually-triggered walkthrough for the Accounting module — reuses the
     SAME real-spotlight engine as the other tours (rentalCreateTour(), see
     approval-tour.blade.php) via its own element id prefix, step class, and storage
     keys — this file only supplies content + config, not engine code.

     Read-only/explanatory throughout, same spirit as activity-log-tour.blade.php: no
     tutorial-seeded data, no is_tutorial isolation. The Expense/Cash In-Out steps
     spotlight the real Add forms (readonly, like activity-log-tour's filter-form step)
     without instructing the viewer to submit — if they choose to anyway, that's a real
     entry, same as using the page without the tour.

     Access is asymmetric by role (accounts.* is Management/Super Admin only; the
     Dashboard adds Accounting-role; Expenses/Cash In-Out add Admin too — see the
     role: middleware on each route in routes/web.php), so the Dashboard and Chart of
     Accounts step blocks are wrapped in the same permission checks that gate their
     routes/nav links, keeping every viewer's path limited to pages they can actually
     open. --}}
<div id="rentalAccountingTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalAccountingTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalAccountingTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalAccountingTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            What is Accounting?
            <span id="rentalAccountingTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalAccountingTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalAccountingTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-accounting-tour-step" data-step="0">
            <h3 id="rentalAccountingTourTitle" class="text-lg font-semibold text-gray-900 mb-2">What is Accounting?</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                The module where all of the store's money transactions are recorded neatly, from daily expenses to
                rental payments — so the store's financial condition can always be checked, and it's easy to hand
                over to a real accountant if needed.
            </p>
            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                The menus shown in this guide adapt to your access: Management and Super Admin see everything,
                other roles only see part of it.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">What gets recorded here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                There are 3 places to record: store expenses (<strong>Expenses</strong>), money coming in/out of cash
                (<strong>Cash In/Out</strong>), and the account structure that underlies them (<strong>Chart of
                Accounts</strong>). Rental payments from customers are also recorded here automatically as soon as
                they are received — no need to enter them manually again.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The reports</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                From all that recording, there are <strong>4 standard financial reports</strong> you can read: Trial
                Balance, Balance Sheet, Profit &amp; Loss, and General Ledger. There is a separate guide just for
                these reports, "What are the Financial Reports?".
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#rentalTourNavAccountingToggle" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The menu is in the sidebar</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Accounting"</strong> to open its submenu.
            </p>
        </div>

        @if(Auth::user()->mayViewAccountingDashboard())
        <div class="rental-accounting-tour-step hidden" data-target="#rentalTourNavAccountingDashboard">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Getting here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Dashboard"</strong> in the submenu.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#accountingDashboardKpis" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Revenue &amp; expense summary</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Think of it like a car dashboard: at a glance you know the store's financial condition for the
                selected date range. This page is purely for <strong>monitoring</strong>, not recording; all the
                numbers are read-only. These cards answer: how much rental money came in
                (<strong>Cash Collected from Rentals</strong>), how much rental revenue is recognised, how much cash came in and went out,
                and the total expenses. If the numbers look different from the reports below,
                there is an explanation box right below these cards.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#accountingDashboardFilterForm" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Change the date range</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Choose a start and end date, then click <strong>Filter</strong> to update the numbers above.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#accountingDashboardReports" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The four financial reports</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Trial Balance, Balance Sheet, Profit &amp; Loss, and General Ledger, all built from the journals
                that have been recorded. Click any one to open it, or see the "What are the Financial Reports?" guide
                on the Help page for an explanation of each report.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#accountingDashboardShiftLookup" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Shift Lookup</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Choose a date to see who opened a shift that day, the number of transactions, and the revenue
                per shift, then click to open its full shift closing report.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#accountingDashboardOrders" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Order Summary</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                The number and value of rentals created in this period. The full list is in the
                <strong>Rents</strong> menu.
            </p>
        </div>
        @endif

        @if(Auth::user()->mayViewChartOfAccounts())
        <div class="rental-accounting-tour-step hidden" data-target="#rentalTourNavChartOfAccounts">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Now to the Chart of Accounts</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Chart of Accounts"</strong> in the submenu.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#accountsTableSection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The standard account structure</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                This is the "dictionary" of financial categories used across the whole system: Cash, Bank, Parking Fees,
                Rental Revenue, and the like. Every Expense, Cash In/Out, and rental payment always refers to one of the
                accounts here. Accounts are arranged in levels (parent &rarr; child); those marked <strong>Cash/Bank</strong>
                can be used as the cash account in Cash In/Out. Applies to all stores at once.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#accountsTableSection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Who can change it</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                @if(Auth::user()->mayManageChartOfAccounts())
                    Only Management/Super Admin can add or change accounts here, so that the account structure
                    used by all stores doesn't get changed carelessly.
                @else
                    You can view all the accounts here, but adding or changing them is still the job of
                    Management/Super Admin.
                @endif
            </p>
        </div>
        @endif

        <div class="rental-accounting-tour-step hidden" data-target="#rentalTourNavExpenses">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Getting to Expenses</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Expenses"</strong> in the submenu.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#storeSwitcherSelect" data-target-optional="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Expenses go to the active store</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                A recorded expense always goes to the <strong>store that is currently active</strong> in this store picker.
                If you are assigned to more than 1 store, check which store is active before recording — switch
                it with this dropdown if needed.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#expenseAddSection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Record an expense</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Whenever the store spends money on operational needs, for example paying for parking, salaries, or
                electricity, record it here. Why does it matter? So that at the end of the month, the cash that should
                be in the store really matches what is recorded, with nothing vanishing without a trace. Fill in the
                date, amount, what the expense category is, and which cash/bank the money was taken from. Once saved,
                that cash account's balance automatically goes down.
            </p>
        </div>

        @php
            // Resolved fresh here (not passed from a controller) since this
            // partial is included on every authenticated page, not just
            // Expenses — a cheap, indexed lookup by code, IDs vary per install.
            $simCategoryAccountId = \App\Models\Account::where('code', '5400')->value('id');
            $simPaymentAccountId = \App\Models\Account::where('code', '1100')->value('id');
            $simCanRun = \App\Support\AccountingTrialMode::enabled() && $simCategoryAccountId && $simPaymentAccountId;
        @endphp
        @if($simCanRun)
        <div id="rentalAccountingTourExpenseSimStep" class="rental-accounting-tour-step hidden" data-target="#expenseSubmitBtn" data-tutorial-form="expenseForm">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Try recording one yourself (practice)</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                The form above has been filled with an example: buying <strong>a gallon of drinking water Rp 20.000</strong>, paid from Store
                Cash. Check the entry first, then click <strong>"Record Expense"</strong> to actually record it.
                Safe to try — because Trial Mode is ON, this is recorded as practice data, not real data.
            </p>
        </div>
        @else
        <div class="rental-accounting-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Try recording one yourself (practice)</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                To try recording directly without affecting real data, turn on <strong>Trial
                Mode</strong> on the Settings page first, then reopen this guide from the start.
            </p>
        </div>
        @endif

        <div class="rental-accounting-tour-step hidden" data-target="#expenseHistorySection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Expense history</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                All the expenses recorded, which can be filtered by date, including who recorded each one.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#expenseHistorySection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Recorded something wrong?</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                @if(Auth::user()->mayEditAccountingEntries())
                    There is an <strong>Edit</strong> button on each row to correct it. The system doesn't overwrite
                    the old record, but creates a <strong>reversing journal</strong> (a new entry with the same value
                    but with debit and credit swapped, so they cancel each other out) and then records it again with the
                    correct value, so the original history stays and can be traced.
                @else
                    A mistyped amount or category can still be corrected, but through Management, Super Admin,
                    or Accounting, not from an Admin account.
                @endif
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#rentalTourNavCashMovements">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Lastly, Cash In/Out</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Cash In/Out"</strong> in the submenu.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#cashMovementAddSection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Record cash coming in/out</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Unlike Expenses, this isn't for money that is spent, but money that is only
                <strong>moved around</strong>. For example, depositing cash from the cashier's drawer into a bank
                account — the money is still there, it just moved, it isn't an expense. Or the owner adding capital
                to the store's cash (Cash In). Choose the cash/bank account and the counter account, where the money
                moves from/to.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#cashMovementHistorySection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Cash in/out history</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                All recorded cash movements, marked <strong>In</strong>/<strong>Out</strong>, which can be filtered
                by date.
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden" data-target="#cashMovementHistorySection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Recorded something wrong?</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                @if(Auth::user()->mayEditAccountingEntries())
                    Same as Expenses, each row has an <strong>Edit</strong> button: the old record isn't
                    overwritten, a <strong>reversing journal</strong> is created and the correct entry is recorded
                    again, so the history can still be traced.
                @else
                    It can still be corrected, but through Management, Super Admin, or Accounting, not from an
                    Admin account.
                @endif
            </p>
        </div>

        <div class="rental-accounting-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                That's how it works. You can replay this guide anytime from the Help page.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalAccountingTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalAccountingTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalAccountingTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalAccountingTour',
    stepClass: 'rental-accounting-tour-step',
    storageKey: 'rental_accounting_tour_seen_v1_{{ Auth::id() }}',
    resumeKey: 'rental_accounting_tour_resume_v1_{{ Auth::id() }}',
    triggerSelector: '[data-open-accounting-tour]',
    openFnName: 'rentalOpenAccountingTour',
    debugFnName: 'rentalAccountingTourJumpTo',
    autoOpenOnFirstVisit: false,
});

(function () {
    // The "Getting here" / "Now to ..." steps spotlight links INSIDE the
    // collapsible Accounting submenu (navigation.blade.php's x-data
    // accountingOpen toggle) — if the viewer never actually clicks the real
    // toggle themselves (very common: people drive the tour purely via
    // Next/Back), the submenu is still display:none when one of those
    // steps tries to measure the target's position, and the spotlight/hole
    // gets drawn at a bogus (0-size) rect. Force it open the moment the tour
    // starts, same "session-scoped, not step-scoped" trick shift-tour and
    // store-user-tour already use for their own toggles — cheap, and it's a
    // real click so Alpine's own state stays authoritative.
    function ensureAccountingSubmenuOpen() {
        var toggle = document.getElementById('rentalTourNavAccountingToggle');
        var probe = document.getElementById('rentalTourNavAccountingDashboard')
            || document.getElementById('rentalTourNavChartOfAccounts')
            || document.getElementById('rentalTourNavExpenses')
            || document.getElementById('rentalTourNavCashMovements');
        if (toggle && probe && probe.offsetParent === null) {
            toggle.click();
        }
    }

    document.querySelectorAll('[data-open-accounting-tour]').forEach(function (btn) {
        btn.addEventListener('click', ensureAccountingSubmenuOpen);
    });

    // Resuming after a real page navigation lands on an accounting route,
    // where the submenu is already server-rendered open ($accountingActive)
    // — this only matters for the sliver of resume states that somehow land
    // back on a non-accounting page (e.g. Help, if resume fires there).
    if (sessionStorage.getItem('rental_accounting_tour_resume_v1_{{ Auth::id() }}')) {
        ensureAccountingSubmenuOpen();
    }
})();

@if($simCanRun)
(function () {
    // Pre-fills the real Record Expense form the moment its practice step
    // becomes visible (Next, Back, or resumed directly here after a
    // page reload) — a MutationObserver on the step's own hidden class
    // rather than hooking the shared tour-engine's render(), since this
    // behavior is specific to this one step, not generic tour machinery.
    var step = document.getElementById('rentalAccountingTourExpenseSimStep');
    if (!step) return;
    var filled = false;
    function fillPracticeExpense() {
        if (filled || step.classList.contains('hidden')) return;
        filled = true;
        var amount = document.getElementById('expenseAmount');
        var category = document.getElementById('expenseCategoryInput');
        var paidFrom = document.getElementById('expensePaymentAccountInput');
        var desc = document.getElementById('expenseDescriptionInput');
        if (amount && !amount.value) amount.value = '20.000';
        // dispatchEvent('change') after each: both are wrapped into a
        // searchable combobox (searchable-select.js), whose visible label
        // only re-syncs on the select's own 'change' event.
        if (category && !category.value) {
            category.value = '{{ $simCategoryAccountId }}';
            category.dispatchEvent(new Event('change'));
        }
        if (paidFrom && !paidFrom.value) {
            paidFrom.value = '{{ $simPaymentAccountId }}';
            paidFrom.dispatchEvent(new Event('change'));
        }
        if (desc && !desc.value) desc.value = 'Drinking water gallon';
    }
    fillPracticeExpense();
    new MutationObserver(fillPracticeExpense).observe(step, { attributes: true, attributeFilter: ['class'] });
})();
@endif
</script>
@endif
