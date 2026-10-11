@if(Auth::check() && Auth::user()->mayViewAccountingDashboard())
{{-- Standalone, manually-triggered walkthrough for the 4 financial reports (Trial
     Balance / Balance Sheet / Profit & Loss / General Ledger) — deliberately its own
     tour, separate from accounting-tour.blade.php's "What is Accounting?", so that
     tour stays focused on recording (Expenses/Cash In-Out/CoA) and this one on
     reading the ledger back as statements. Same real-spotlight engine
     (rentalCreateTour(), see approval-tour.blade.php) via its own element id prefix,
     step class, and storage keys.

     Gated to mayViewAccountingDashboard() only — matches the reports' own route
     middleware (role:super_admin,management,accounting) exactly; Admin can record
     expenses but doesn't get these summary statements. --}}
<div id="rentalFinancialReportsTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalFinancialReportsTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalFinancialReportsTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalFinancialReportsTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            What are the Financial Reports?
            <span id="rentalFinancialReportsTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalFinancialReportsTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalFinancialReportsTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-financial-reports-tour-step" data-step="0">
            <h3 id="rentalFinancialReportsTourTitle" class="text-lg font-semibold text-gray-900 mb-2">What are the Financial Reports?</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                These four reports each answer a different question: whether the books balance, what the business
                owns and how much it still owes, whether this month made a profit or a loss, and, if a number looks
                odd, which transaction it can be traced back to. Everything is automatic from the data already
                recorded, with no manual input, and the first three can be downloaded as PDFs to give to the accountant.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden" data-target="#rentalTourNavAccountingToggle" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The menu is in the sidebar</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Accounting"</strong> to open its submenu, then look for the "Reports" section.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden" data-target="#rentalTourNavTrialBalance">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Start with the Trial Balance</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Trial Balance"</strong> in the submenu.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden" data-target="#trialBalanceContent" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Trial Balance</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Think of it as double-checking the calculator before trusting its result. Every transaction always
                has two sides (debit and credit) that must be equal. The Trial Balance gathers all accounts and
                their balances, then proves that the two sides really are balanced. If they aren't, something was
                recorded wrongly, and the three other reports below can't be trusted until this is sorted out.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden" data-target="#rentalTourNavBalanceSheet">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">On to the Balance Sheet</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Balance Sheet"</strong> in the submenu.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden" data-target="#balanceSheetContent" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Balance Sheet</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                This is a snapshot of the business's wealth on exactly one chosen date. It answers: if we checked
                today, what does the business own (cash, receivables, assets), and how much does it still owe? What it
                owns (Assets) must always equal what it owes plus the owner's capital (Liabilities + Equity),
                including the current profit/loss line, which is calculated automatically because this system
                doesn't have a monthly "close the books" button yet.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden" data-target="#rentalTourNavProfitLoss">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">On to Profit &amp; Loss</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Profit &amp; Loss"</strong> in the submenu.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden" data-target="#profitLossContent" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Profit &amp; Loss</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                This answers the question business owners ask most: <strong>did we make a profit or a
                loss this month?</strong> Unlike the two previous reports, which count from the first day the business
                started, this report looks at one period only, for example one month. All the revenue that
                came in minus all the expenses that went out in that period gives the profit or loss of the business
                for that period.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden" data-target="#rentalTourNavGeneralLedger">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Lastly, the General Ledger</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "General Ledger"</strong> in the submenu.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden" data-target="#generalLedgerContent" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">General Ledger</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                If the three reports above are the summaries, this is the raw record book: every transaction is
                recorded one by one, as it is. Useful when a number looks odd in another report and you want to
                trace it all the way back to the original transaction. It can be filtered by account or date.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden" data-target="#generalLedgerContent" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Tracing corrections</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                If an entry was ever corrected, click that row to see the original entry and its
                <strong>reversing journal</strong> side by side — a new entry that cancels out the old one, rather than
                overwriting its value.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden" data-target="#generalLedgerContent" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Two small labels</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Trial</strong>: recorded while Trial Mode was on, not a real transaction.
                <strong>Reversal</strong>: this entry cancels out another entry. Both can appear together
                if a trial entry was also corrected.
            </p>
        </div>

        <div class="rental-financial-reports-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                That's how it works. You can replay this guide anytime from the Help page.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalFinancialReportsTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalFinancialReportsTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalFinancialReportsTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalFinancialReportsTour',
    stepClass: 'rental-financial-reports-tour-step',
    storageKey: 'rental_financial_reports_tour_seen_v1_{{ Auth::id() }}',
    resumeKey: 'rental_financial_reports_tour_resume_v1_{{ Auth::id() }}',
    triggerSelector: '[data-open-financial-reports-tour]',
    openFnName: 'rentalOpenFinancialReportsTour',
    debugFnName: 'rentalFinancialReportsTourJumpTo',
    autoOpenOnFirstVisit: false,
});

(function () {
    // Same "force the submenu open" trick as accounting-tour.blade.php — the
    // "Click Trial Balance" etc. steps spotlight links inside the collapsible
    // Accounting submenu, which is display:none until the real toggle is
    // clicked at least once this session.
    function ensureAccountingSubmenuOpen() {
        var toggle = document.getElementById('rentalTourNavAccountingToggle');
        var probe = document.getElementById('rentalTourNavTrialBalance')
            || document.getElementById('rentalTourNavAccountingDashboard');
        if (toggle && probe && probe.offsetParent === null) {
            toggle.click();
        }
    }

    document.querySelectorAll('[data-open-financial-reports-tour]').forEach(function (btn) {
        btn.addEventListener('click', ensureAccountingSubmenuOpen);
    });

    if (sessionStorage.getItem('rental_financial_reports_tour_resume_v1_{{ Auth::id() }}')) {
        ensureAccountingSubmenuOpen();
    }
})();
</script>
@endif
