@if(\App\Support\AccountingTrialMode::enabled())
    <div class="mb-4 bg-amber-50 border border-amber-300 text-amber-800 px-4 py-3 rounded-lg text-sm">
        <strong>Trial Mode is ON</strong> — everything recorded here right now is tagged as trial data. It'll
        disappear from the Dashboard, History, and balances the moment Trial Mode is turned off in Settings
        (nothing gets deleted; it can be cleared later from Settings too). A tutorial's own guided practice step
        never counts either way, on or off — always safe to try.
    </div>
@endif
