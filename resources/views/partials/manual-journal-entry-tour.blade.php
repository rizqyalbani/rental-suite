@if(Auth::check() && Auth::user()->mayManageChartOfAccounts())
{{-- Standalone walkthrough for the Manual Journal Entry page — own tour instance
     (own idPrefix/step class/storage keys) via the SAME rentalCreateTour() engine as
     accounting-tour.blade.php, kept in its own file instead of appended to that one
     so its steps never need renumbering when either tour changes (accounting-tour's
     Expense-offset comment in expenses/index.blade.php shows how painful that
     coupling gets otherwise).

     Gated the same way the "Manual Journal Entry" nav link and route themselves are
     (mayManageChartOfAccounts — Management/Super Admin only), so there's no
     Admin/Accounting-role variant to account for, unlike the main tour.

     The practice step reuses the exact pattern proven on Expenses' practice step:
     pre-fill the REAL form with a realistic scenario (hanger depreciation: Debit
     5900 Miscellaneous Expense, Credit 1650 Supplies (Capital Asset), Rp 25.000) and spotlight
     the real submit button — only rendered when Trial Mode is on, so the resulting
     entry is tagged is_tutorial=true by JournalPostingService's own default
     (AccountingTrialMode::enabled()) and never touches real balances.

     Also explains the optional "Asset being depreciated" field (links an entry to one
     FixedAsset row, see fixed-assets.blade.php) — the practice fill deliberately
     leaves it blank since there's no guaranteed real registered item to pick from,
     rather than seeding a fake one just for this walkthrough. --}}
<div id="rentalJournalEntryTourClickBlocker" class="hidden fixed inset-0 z-[198]"></div>
<div id="rentalJournalEntryTourSpotlight" class="hidden fixed z-[199] rounded-lg pointer-events-none transition-all duration-300" style="box-shadow: 0 0 0 9999px rgba(17,24,39,0.65), 0 0 0 3px #6366f1;"></div>

<div id="rentalJournalEntryTourOverlay" class="hidden fixed z-[200] bg-white rounded-2xl shadow-2xl w-[calc(100%-2rem)] max-w-lg max-h-[calc(100vh-32px)] flex flex-col overflow-hidden transition-[max-width] duration-150" role="dialog" aria-modal="true" aria-labelledby="rentalJournalEntryTourTitle">
    <div class="flex items-center justify-between px-5 pt-5 shrink-0">
        <p class="text-xs font-semibold uppercase tracking-wide text-indigo-600">
            Manual Journal Entry
            <span id="rentalJournalEntryTourStepCounter" class="ml-1 font-normal normal-case text-gray-400"></span>
        </p>
        <button type="button" id="rentalJournalEntryTourSkip" class="text-gray-400 hover:text-gray-600 text-sm cursor-pointer">Skip</button>
    </div>

    <div id="rentalJournalEntryTourSteps" class="px-5 pb-2 pt-3 overflow-y-auto min-h-0">
        <div class="rental-journal-entry-tour-step" data-step="0">
            <h3 id="rentalJournalEntryTourTitle" class="text-lg font-semibold text-gray-900 mb-2">What is this page for?</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                For adjustments that <strong>don't involve cash/bank at all</strong> — no money moves, the value is just
                moved from one account to another. The most common example: depreciation (recording that an item
                loses value through use, for example hangers).
            </p>
            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                If money really goes out/in or moves between cash/bank, use <strong>Expenses</strong>
                or <strong>Cash In/Out</strong> — not this page.
            </p>
        </div>

        <div class="rental-journal-entry-tour-step hidden" data-target="#rentalTourNavAccountingToggle" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">The menu is in the sidebar</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Accounting"</strong> to open its submenu.
            </p>
        </div>

        <div class="rental-journal-entry-tour-step hidden" data-target="#rentalTourNavJournalEntries">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Getting here</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                <strong>Click "Manual Journal Entry"</strong> in the submenu.
            </p>
        </div>

        <div class="rental-journal-entry-tour-step hidden" data-target="#journalEntryFormSection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Fill in the form</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                Date, amount, which account <strong>increases (Debit)</strong>, which account is the
                <strong>source (Credit)</strong>, and a memo explaining why. The system only checks that the Debit and
                Credit totals are equal — not whether the combination of accounts makes sense, so double-check
                before saving.
            </p>
            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                There is also an optional <strong>"Asset being depreciated"</strong> field — fill it in if the entry is
                the depreciation of one specific item that is already registered on the
                <a href="{{ route('accounting.reports.fixed-assets') }}" class="underline font-medium">Fixed Assets</a> page,
                so that item's "Total Depreciated" is counted separately — not only added to the account's
                total. If the item isn't registered there yet, you can leave it empty.
            </p>
        </div>

        @php
            $simDebitAccountId = \App\Models\Account::where('code', '5900')->value('id');
            $simCreditAccountId = \App\Models\Account::where('code', '1650')->value('id');
            $simCanRun = \App\Support\AccountingTrialMode::enabled() && $simDebitAccountId && $simCreditAccountId;
        @endphp
        @if($simCanRun)
        <div id="rentalJournalEntryTourSimStep" class="rental-journal-entry-tour-step hidden" data-target="#journalEntrySubmitBtn" data-tutorial-form="journalEntryForm">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Try recording one yourself (practice)</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                The form above has been filled with an example: <strong>hanger depreciation Rp 25.000</strong> — Debit
                "Miscellaneous Expense" (expenses go up), Credit "Supplies (Capital Asset)" (the asset's value goes down).
                Check the entry first, then click <strong>"Record Journal Entry"</strong> to actually record it. Safe
                to try — because Trial Mode is ON, this is recorded as practice data, not real data.
            </p>
            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                This example deliberately leaves "Asset being depreciated" empty — if the hanger is already really
                registered as an item on the Fixed Assets page, choose its name there too before saving.
            </p>
        </div>
        @else
        <div class="rental-journal-entry-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Try recording one yourself (practice)</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                To try recording directly without affecting real data, turn on <strong>Trial
                Mode</strong> on the Settings page first, then reopen this guide from the start.
            </p>
        </div>
        @endif

        <div class="rental-journal-entry-tour-step hidden" data-target="#journalEntryHistorySection" data-target-readonly="1">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Manual entry history</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                All recorded manual entries, which can be filtered by date. These entries also appear automatically in the
                General Ledger and affect the Trial Balance/Balance Sheet/Profit &amp; Loss, just like entries
                from Expenses and Cash In/Out.
            </p>
            <p class="text-xs text-gray-400 mt-3 leading-relaxed">
                The practice entry you just recorded appears in the top row with the label
                <strong>"Practice (preview)"</strong> — it's only a one-time view for confirmation, not data
                that is saved in this list or in the real reports, so it won't be counted anywhere.
            </p>
        </div>

        <div class="rental-journal-entry-tour-step hidden">
            <h3 class="text-lg font-semibold text-gray-900 mb-2">Done!</h3>
            <p class="text-sm text-gray-600 leading-relaxed">
                That's how it works. Click the "?" button on this page anytime if you want to see it again.
            </p>
        </div>
    </div>

    <div class="flex items-center justify-between gap-3 px-5 py-4 bg-gray-50 border-t border-gray-100 shrink-0">
        <div class="flex items-center gap-1.5" id="rentalJournalEntryTourDots" aria-hidden="true"></div>
        <div class="flex items-center gap-2">
            <button type="button" id="rentalJournalEntryTourBack" class="hidden px-3 py-2 rounded-md text-sm font-medium text-gray-600 hover:bg-gray-100 cursor-pointer">Back</button>
            <button type="button" id="rentalJournalEntryTourNext" class="px-4 py-2 rounded-md text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 cursor-pointer">Next</button>
        </div>
    </div>
</div>

<script>
rentalCreateTour({
    idPrefix: 'rentalJournalEntryTour',
    stepClass: 'rental-journal-entry-tour-step',
    storageKey: 'rental_journal_entry_tour_seen_v1_{{ Auth::id() }}',
    resumeKey: 'rental_journal_entry_tour_resume_v1_{{ Auth::id() }}',
    triggerSelector: '[data-open-journal-entry-tour]',
    openFnName: 'rentalOpenManualJournalEntryTour',
    debugFnName: 'rentalJournalEntryTourJumpTo',
    autoOpenOnFirstVisit: false,
});

(function () {
    // Same "force the collapsible submenu open" trick accounting-tour.blade.php
    // uses for its own nav-click steps — this tour's first two steps spotlight
    // links INSIDE that same submenu, which stays display:none until either the
    // real toggle is clicked or the current page is already an accounting route
    // (server-rendered open via $accountingActive).
    function ensureAccountingSubmenuOpen() {
        var toggle = document.getElementById('rentalTourNavAccountingToggle');
        var probe = document.getElementById('rentalTourNavJournalEntries')
            || document.getElementById('rentalTourNavAccountingDashboard')
            || document.getElementById('rentalTourNavChartOfAccounts');
        if (toggle && probe && probe.offsetParent === null) {
            toggle.click();
        }
    }

    document.querySelectorAll('[data-open-journal-entry-tour]').forEach(function (btn) {
        btn.addEventListener('click', ensureAccountingSubmenuOpen);
    });

    if (sessionStorage.getItem('rental_journal_entry_tour_resume_v1_{{ Auth::id() }}')) {
        ensureAccountingSubmenuOpen();
    }
})();

@if($simCanRun)
(function () {
    // Pre-fills the real Manual Journal Entry form the moment its practice step
    // becomes visible — same MutationObserver-on-the-step's-own-hidden-class
    // pattern as accounting-tour.blade.php's Expense practice step.
    var step = document.getElementById('rentalJournalEntryTourSimStep');
    if (!step) return;
    var filled = false;
    function fillPracticeEntry() {
        if (filled || step.classList.contains('hidden')) return;
        filled = true;
        var amount = document.getElementById('journalEntryAmount');
        var debit = document.getElementById('journalEntryDebitInput');
        var credit = document.getElementById('journalEntryCreditInput');
        var memo = document.getElementById('journalEntryMemo');
        if (amount && !amount.value) amount.value = '25.000';
        // dispatchEvent('change') after each: the Debit/Kredit selects are
        // wrapped into a searchable combobox (searchable-select.js), whose
        // visible text label only re-syncs on the select's own 'change'
        // event — a plain .value= from here wouldn't otherwise reach it.
        if (debit && !debit.value) {
            debit.value = '{{ $simDebitAccountId }}';
            debit.dispatchEvent(new Event('change'));
        }
        if (credit && !credit.value) {
            credit.value = '{{ $simCreditAccountId }}';
            credit.dispatchEvent(new Event('change'));
        }
        if (memo && !memo.value) memo.value = 'Hanger depreciation — practice';
    }
    fillPracticeEntry();
    new MutationObserver(fillPracticeEntry).observe(step, { attributes: true, attributeFilter: ['class'] });
})();
@endif
</script>
@endif
