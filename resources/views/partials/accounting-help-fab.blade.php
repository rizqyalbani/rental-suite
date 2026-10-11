{{-- Small floating "?" shortcut for Accounting-module pages: jumps straight into the
     relevant tour step(s) for the page it's on, instead of making the viewer restart the
     whole tour from the sidebar. Include with:
       @include('partials.accounting-help-fab', ['tour' => 'module', 'step' => 3, 'endStep' => 7])
     'tour' is 'module' (rentalOpenAccountingTour, from accounting-tour.blade.php),
     'reports' (rentalOpenFinancialReportsTour, from financial-reports-tour.blade.php),
     'journal-entry' (rentalOpenManualJournalEntryTour, from
     manual-journal-entry-tour.blade.php), or 'fixed-assets'
     (rentalOpenFixedAssetsTour, from fixed-assets-tour.blade.php).
     'step'/'endStep' are that tour's 0-indexed steps — keep in sync if a tour's step
     order changes. 'endStep' bounds Next/Back to this page's own steps only (see
     tour-engine.blade.php's open(startAt, endAt)) — without it Next would keep
     walking into the NEXT page's nav-click steps, which is wrong for a "just explain
     this page" shortcut. Default endStep to step itself (a single-step page) when omitted.

     Positioned above the default bottom-right corner (bottom-24, not bottom-4) so it
     doesn't sit under the Management/Super Admin pending-approvals dock, which floats
     at bottom-4 on any non-Rentals page (layouts/app.blade.php). Still possible for a
     heavily expanded dock to reach up and overlap it — a rare, cosmetic-only edge case. --}}
@php
    $accountingHelpOpenFn = match ($tour ?? 'module') {
        'reports' => 'rentalOpenFinancialReportsTour',
        'journal-entry' => 'rentalOpenManualJournalEntryTour',
        'fixed-assets' => 'rentalOpenFixedAssetsTour',
        'ppn' => 'rentalOpenPpnTour',
        'piutang' => 'rentalOpenPiutangTour',
        default => 'rentalOpenAccountingTour',
    };
    $accountingHelpEndStep = (int) ($endStep ?? $step);
@endphp
{{-- First-timer nudge: a one-time speech bubble pointing at the "?" button, telling a
     viewer who's never seen it before that help exists here (or on the Help page).
     Shown once ever across ALL 8 accounting pages (one shared localStorage flag, not
     per-page) — seeing it on the Dashboard means it shouldn't repeat on Expenses. --}}
<div id="accountingHelpFirstTimerHint"
    class="hidden fixed right-4 bottom-[9.5rem] z-[90] max-w-[15rem] rounded-lg bg-gray-900 text-white text-xs leading-relaxed px-3 py-2.5 shadow-lg">
    <button type="button" onclick="document.getElementById('accountingHelpFirstTimerHint').classList.add('hidden')"
        class="absolute -top-1.5 -right-1.5 h-4 w-4 flex items-center justify-center rounded-full bg-gray-700 hover:bg-gray-600 cursor-pointer" aria-label="Close">
        <span class="text-[10px] leading-none">&times;</span>
    </button>
    Need an explanation of this page? Click the <strong>?</strong> button at the bottom right anytime, or visit the
    <a href="{{ route('help.index') }}" class="underline font-medium">Help page</a>.
    <div class="absolute -bottom-1.5 right-6 h-3 w-3 rotate-45 bg-gray-900"></div>
</div>
<button type="button" onclick="{{ $accountingHelpOpenFn }}({{ (int) $step }}, {{ $accountingHelpEndStep }}); document.getElementById('accountingHelpFirstTimerHint').classList.add('hidden');"
    class="fixed right-4 bottom-24 z-[90] flex h-11 w-11 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg hover:bg-indigo-700 transition cursor-pointer"
    aria-label="Help for this page" title="Help for this page">
    <span class="text-lg font-bold leading-none">?</span>
</button>
<script>
(function () {
    var KEY = 'rental_accounting_help_hint_seen_v1';
    try {
        if (localStorage.getItem(KEY)) return;
    } catch (e) { return; }
    var hint = document.getElementById('accountingHelpFirstTimerHint');
    if (!hint) return;
    hint.classList.remove('hidden');
    try { localStorage.setItem(KEY, '1'); } catch (e) {}
})();
</script>
