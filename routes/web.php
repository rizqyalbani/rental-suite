<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AccountingDashboardController;
use App\Http\Controllers\AccountingReportController;
use App\Http\Controllers\CashMovementController;
use App\Http\Controllers\ManualJournalEntryController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FixedAssetController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\ManagerReportController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\RentalActivityLogController;
use App\Http\Controllers\RentalApprovalController;
use App\Http\Controllers\RentalController;
use App\Http\Controllers\SecurityController;
use App\Http\Controllers\ShiftController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\OutfitController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\WhatsappRedirectController;
use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Rental;
use App\Models\StockOpname;
use App\Models\StoreSetting;
use App\Models\Outfit;
use App\Support\AccountingTrialMode;
use App\Support\AdminDeleteRestriction;
use App\Support\FonnteSettings;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

Route::get('/', function () {
    return redirect()->route('dashboard');
});

Route::get('/dashboard', function (Request $request) {
    $stats = [
        'active_rentals' => Rental::where('status', '=', 'active', 'and')->count('*'),
        'total_outfits' => Outfit::count('*'),
        'total_rentals' => Rental::count('*'),
        'returned_today' => Rental::where('status', '=', 'active', 'and')
            ->where('end_date', '<', now()->toDateString())
            ->count('*'),
    ];

    // Nudges Supervisor+ about a Stock Opname session left open too long —
    // without this, a forgotten session just sits "In Progress" indefinitely
    // with nothing surfacing it outside the Stock Opname page itself.
    $staleOpnameSessions = $request->user()->mayManageStockOpname()
        ? StockOpname::whereNull('completed_at')
            ->where('is_tutorial_practice', false)
            ->where('started_at', '<', now()->subDays(3))
            ->with(['startedBy', 'store'])
            ->orderBy('started_at')
            ->get()
        : collect();

    return view('dashboard', compact('stats', 'staleOpnameSessions'));
})->middleware(['auth', 'verified', 'set.store'])->name('dashboard');

Route::get('/help', function () {
    $user = auth()->user();

    return view('help.index', [
        'isDecider' => $user->isManagement() || $user->isSupervisor() || $user->isSuperAdmin(),
        'canOperateShifts' => ! $user->isManagement(),
        'isStoreManager' => $user->isManagement() || $user->isSuperAdmin(),
        'mayAccounting' => $user->mayViewAccountingDashboard() || $user->mayManageChartOfAccounts() || $user->mayRecordAccountingEntries(),
        'canViewPpnBriefing' => $user->mayManageChartOfAccounts(),
    ]);
})->middleware(['auth', 'verified', 'set.store'])->name('help.index');

Route::get('/help/accounting-terms', function () {
    $user = auth()->user();
    abort_unless(
        $user->mayViewAccountingDashboard() || $user->mayManageChartOfAccounts() || $user->mayRecordAccountingEntries(),
        403
    );

    return view('help.accounting-terms');
})->middleware(['auth', 'verified', 'set.store'])->name('help.accounting-terms');

// Plain changelog for whoever uses the app day to day — open to every logged-in
// role (same as most of Help), not just Management/Super Admin. Content lives
// in App\Support\ReleaseNotes, maintained by hand outside the app (append an
// entry there when real work ships) — no admin UI to add/archive entries.
Route::get('/help/release-notes', function () {
    return view('help.release-notes', [
        'current' => \App\Support\ReleaseNotes::current(),
        'archived' => \App\Support\ReleaseNotes::archived(),
    ]);
})->middleware(['auth', 'verified', 'set.store'])->name('help.release-notes');

// Fixed checklist of questions for the client (and, where flagged, a real
// tax consultant) to answer before real VAT posting gets built — see the
// "Output VAT Briefing" artifact this mirrors. Answers are saved so a
// non-accountant freelancer can hand the client this page, then come back
// later and follow up on exactly what's still open, instead of re-asking
// from memory or re-explaining each question from scratch.
Route::get('/help/ppn-briefing', function () {
    $user = auth()->user();
    abort_unless($user->mayManageChartOfAccounts(), 403);

    $saved = \App\Models\PpnBriefingAnswer::query()->with('answeredBy')->get()->keyBy('question_key');

    return view('help.ppn-briefing', [
        'questions' => \App\Support\PpnBriefingQuestions::all(),
        'saved' => $saved,
    ]);
})->middleware(['auth', 'verified', 'set.store'])->name('help.ppn-briefing');

Route::post('/help/ppn-briefing', function (\Illuminate\Http\Request $request) {
    $user = $request->user();
    abort_unless($user->mayManageChartOfAccounts(), 403);

    $questionKeys = array_keys(\App\Support\PpnBriefingQuestions::all());
    $validated = $request->validate([
        'answers' => 'required|array',
        'answers.*' => 'nullable|string|max:2000',
    ]);

    foreach ($questionKeys as $key) {
        $answer = trim((string) ($validated['answers'][$key] ?? ''));
        \App\Models\PpnBriefingAnswer::updateOrCreate(
            ['question_key' => $key],
            [
                'answer' => $answer !== '' ? $answer : null,
                'answered_by_user_id' => $answer !== '' ? $user->id : null,
                'answered_at' => $answer !== '' ? now() : null,
            ]
        );
    }

    return redirect()->route('help.ppn-briefing')->with('success', 'Answers saved.');
})->middleware(['auth', 'verified', 'set.store'])->name('help.ppn-briefing.save');

Route::post('/csp-report', [SecurityController::class, 'cspReport'])->name('security.csp-report');

if (app()->environment('local') && config('app.debug')) {
    // Receives the tail of the client-side bug-trap log via sendBeacon right
    // before the page unloads — the one moment fetch() can't be trusted to
    // finish, but sendBeacon can. Writes to its own log file so it's a single
    // grep away instead of needing the user to open devtools.
    Route::post('/debug/bugtrap-report', function (\Illuminate\Http\Request $request) {
        $entries = $request->input('entries', []);
        $user = $request->user();
        $line = sprintf(
            "[%s] user=%s (%s) reason=%s\n%s\n",
            now()->toDateTimeString(),
            $user?->id ?? 'guest',
            $user?->email ?? '-',
            (string) $request->input('reason', 'unknown'),
            json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
        );
        \Illuminate\Support\Facades\File::append(storage_path('logs/bugtrap.log'), $line."\n");

        return response()->json(['ok' => true]);
    })->name('debug.bugtrap-report');
}

Route::middleware(['auth', 'set.store'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::put('/profile/opname-pin', [ProfileController::class, 'updateOpnamePin'])->name('profile.opname-pin.update');
    Route::post('/stores/switch', [StoreController::class, 'switch'])->name('stores.switch');

    Route::get('/outfits/{outfit}/qr.png', [OutfitController::class, 'qrPngDownload'])->name('outfits.qr-download');
    Route::get('/outfits/qr-export.zip', [OutfitController::class, 'qrExportZip'])->name('outfits.qr-export-zip');
    Route::resource('outfits', OutfitController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::post('/outfits/quick-add', [OutfitController::class, 'quickAdd'])->name('outfits.quick-add');
    Route::post('/outfits/bulk', [OutfitController::class, 'bulkStore'])->name('outfits.bulk');
    Route::post('/outfits/bulk-new-code', [OutfitController::class, 'bulkNewCode'])->name('outfits.bulk-new-code');
    Route::post('/outfits/bulk-price', [OutfitController::class, 'bulkPrice'])->name('outfits.bulk-price');
    Route::post('/outfits/bulk-discount', [OutfitController::class, 'bulkDiscount'])->name('outfits.bulk-discount');
    Route::post('/outfits/bulk-update', [OutfitController::class, 'bulkUpdate'])->name('outfits.bulk-update');
    Route::post('/outfits/apply-tax-prices', [OutfitController::class, 'applyTaxToPrices'])->name('outfits.apply-tax-prices');
    Route::post('/outfits/sync-rental-outfit-tax', [OutfitController::class, 'syncRentalOutfitTax'])->name('outfits.sync-rental-outfit-tax');
    Route::post('/outfits/bulk-delete', [OutfitController::class, 'bulkDelete'])->name('outfits.bulk-delete');
    Route::post('/outfits/transfer-stock', [OutfitController::class, 'transferStock'])->name('outfits.transfer-stock');
    Route::get('/outfits/available', [OutfitController::class, 'available'])->name('outfits.available');
    Route::get('/outfits/unique-check', [OutfitController::class, 'uniqueCheckForBulkEdit'])->name('outfits.unique-check');
    Route::resource('rentals', RentalController::class)->only(['index', 'store', 'update', 'destroy']);

    // Stock Opname — Admin helps with counting, Supervisor+ starts/finishes a
    // session (App\Models\User::mayParticipateInStockOpname/mayManageStockOpname
    // enforce the split; the route middleware only needs to let Admin through).
    Route::get('/stock-opname', [\App\Http\Controllers\StockOpnameController::class, 'index'])->name('stock-opname.index');
    Route::post('/stock-opname', [\App\Http\Controllers\StockOpnameController::class, 'store'])->name('stock-opname.store');
    Route::get('/stock-opname/{stockOpname}', [\App\Http\Controllers\StockOpnameController::class, 'show'])->name('stock-opname.show');
    Route::post('/stock-opname/{stockOpname}/mark', [\App\Http\Controllers\StockOpnameController::class, 'mark'])->name('stock-opname.mark');
    Route::post('/stock-opname/{stockOpname}/scan', [\App\Http\Controllers\StockOpnameController::class, 'scan'])->name('stock-opname.scan');
    Route::post('/stock-opname/{stockOpname}/complete', [\App\Http\Controllers\StockOpnameController::class, 'complete'])->name('stock-opname.complete');
    Route::post('/stock-opname/{stockOpname}/bulk-mark', [\App\Http\Controllers\StockOpnameController::class, 'bulkMark'])
        ->middleware('role:super_admin')->name('stock-opname.bulk-mark');
    Route::get('/stock-opname/outfits/{outfit}/rental-detail', [\App\Http\Controllers\StockOpnameController::class, 'rentalDetail'])->name('stock-opname.rental-detail');
    Route::get('/stock-opname/outfits/{outfit}/history', [\App\Http\Controllers\StockOpnameController::class, 'itemHistory'])->name('stock-opname.item-history');
    Route::get('/stock-opname/items/{stockOpnameItem}/photo', [\App\Http\Controllers\StockOpnameController::class, 'itemPhoto'])->name('stock-opname.item-photo');
    Route::get('/stock-opname/{stockOpname}/pdf', [\App\Http\Controllers\StockOpnameController::class, 'downloadPdf'])->name('stock-opname.download-pdf');
    // Interactive tutorial only — see StockOpnameController::startTutorial().
    Route::post('/stock-opname/tutorial/start', [\App\Http\Controllers\StockOpnameController::class, 'startTutorial'])->name('stock-opname.tutorial-start');
    Route::post('/stock-opname/tutorial/cleanup', [\App\Http\Controllers\StockOpnameController::class, 'tutorialCleanup'])->name('stock-opname.tutorial-cleanup');
    Route::post('/shifts/open', [ShiftController::class, 'open'])->name('shifts.open');
    Route::post('/shifts/close', [ShiftController::class, 'close'])->name('shifts.close');
    Route::post('/shifts/tutorial/start', [ShiftController::class, 'tutorialStart'])->name('shifts.tutorial.start');
    Route::post('/shifts/tutorial/view-real', [ShiftController::class, 'tutorialViewReal'])->name('shifts.tutorial.view-real');
    Route::post('/shifts/tutorial/exit', [ShiftController::class, 'tutorialExit'])->name('shifts.tutorial.exit');
    Route::post('/shifts/{shift}/reopen', [ShiftController::class, 'reopen'])->name('shifts.reopen');
    Route::get('/shifts/by-date', [ShiftController::class, 'byDate'])->name('shifts.by-date');
    Route::get('/shifts/{shift}/report', [ShiftController::class, 'report'])->name('shifts.report');
    Route::get('/shifts/{shift}/report/download-pdf', [ShiftController::class, 'downloadPdf'])->name('shifts.report.download-pdf');
    Route::get('/shifts/{shift}/report/pdf-debug', [ShiftController::class, 'pdfDebug'])->name('shifts.report.pdf-debug');
    // Practice shift ids collide with real shift ids (both tables auto-increment from 1
    // independently) — a dedicated {tutorialShift} route/binding avoids that ambiguity
    // entirely, rather than trying to disambiguate a shared {shift} parameter.
    Route::get('/shifts/tutorial/{tutorialShift}/report', [ShiftController::class, 'tutorialReport'])->name('shifts.tutorial.report');
    Route::get('/whatsapp/redirect', [WhatsappRedirectController::class, 'redirect'])->name('whatsapp.redirect');

    // Invoice generation & download (signed links)
    Route::post('/rentals/{rental}/invoice/generate', [InvoiceController::class, 'generate'])->withTrashed()->name('invoices.generate');
    Route::post('/rentals/{rental}/invoice/send-whatsapp', [InvoiceController::class, 'sendWhatsapp'])->withTrashed()->name('invoices.send-whatsapp');
    Route::get('/rentals/{rental}/invoice/debug', [InvoiceController::class, 'debugView'])->withTrashed()->name('invoices.debug-view');
    Route::get('/rentals/{rental}/invoice/template-debug', [InvoiceController::class, 'debugTemplatePreview'])->withTrashed()->name('invoices.template-debug');
    Route::post('/rentals/bulk-import', [RentalController::class, 'bulkImport'])->name('rentals.bulk-import');
    Route::get('/rentals/search', [RentalController::class, 'search'])->name('rentals.search');
    Route::get('/rentals/detail-fragment', [RentalController::class, 'detailFragment'])->name('rentals.detail-fragment');
    Route::get('/rentals/shift-control-fragment', [RentalController::class, 'shiftControlFragment'])->name('rentals.shift-control-fragment');
    Route::post('/rentals/cooldown-approval-request', [RentalController::class, 'storeCooldownApprovalRequest'])->name('rentals.cooldown-approval-request');
    Route::post('/rentals/{rental}/status', [RentalController::class, 'updateStatus'])->name('rentals.status');
    Route::post('/rentals/{rental}/pickup', [RentalController::class, 'updatePickup'])->name('rentals.pickup');
    Route::post('/rentals/{rental}/free-request', [RentalController::class, 'requestFree'])->name('rentals.free-request');
    Route::post('/rentals/{rental}/late-done-request', [RentalController::class, 'requestLateDone'])->name('rentals.late-done-request');
    Route::get('/approvals/rentals/pending-snapshot', [RentalApprovalController::class, 'pendingSnapshot'])->name('approvals.rentals.pending-snapshot');
    Route::post('/approvals/rentals/{approval}/cancel', [RentalApprovalController::class, 'cancel'])->name('approvals.rentals.cancel');
    Route::post('/approvals/tutorial-cleanup', [RentalApprovalController::class, 'tutorialCleanup'])->name('approvals.tutorial-cleanup');
    Route::get('/my-requests', [RentalApprovalController::class, 'myRequestsIndex'])->name('my-requests.index');
    Route::post('/tutorial/late-done-rent', [RentalApprovalController::class, 'seedTutorialLateDoneRent'])->name('tutorial.late-done-rent');
    Route::post('/payments/{paymentId}/assign-shift', [\App\Http\Controllers\PaymentShiftAssignmentController::class, 'request'])->whereNumber('paymentId')->name('payments.assign-shift');
    Route::post('/payment-assign/{approval}/accept', [\App\Http\Controllers\PaymentShiftAssignmentController::class, 'accept'])->name('payment-assign.accept');
    Route::post('/payment-assign/{approval}/reject', [\App\Http\Controllers\PaymentShiftAssignmentController::class, 'reject'])->name('payment-assign.reject');

    Route::middleware('role:super_admin,management,supervisor')->group(function () {
        Route::get('/approvals/item-removal', [RentalApprovalController::class, 'itemRemovalIndex'])->name('approvals.item-removal.index');
        Route::post('/approvals/item-removal/{approval}/approve', [RentalApprovalController::class, 'approveItemRemoval'])->name('approvals.item-removal.approve');
        Route::post('/approvals/item-removal/{approval}/reject', [RentalApprovalController::class, 'reject'])->name('approvals.item-removal.reject');
        Route::get('/approvals/edit-requests', [RentalApprovalController::class, 'editRequestsIndex'])->name('approvals.edit-requests.index');
        // approve/reject must allow the same roles as editRequestsIndex above — that page
        // renders Approve/Reject buttons pointing at these routes for supervisors too, so
        // restricting them to management-only (as they used to be, matching the inline
        // dock's queue-fragment) 403'd every supervisor click.
        Route::post('/approvals/rentals/{approval}/approve', [RentalApprovalController::class, 'approve'])->name('approvals.rentals.approve');
        Route::post('/approvals/rentals/{approval}/reject', [RentalApprovalController::class, 'reject'])->name('approvals.rentals.reject');
        Route::post('/approvals/tutorial-seed-edit-request', [RentalApprovalController::class, 'seedTutorialEditRequest'])->name('approvals.tutorial-seed-edit-request');
        Route::post('/approvals/tutorial-seed-late-done-request', [RentalApprovalController::class, 'seedTutorialLateDoneRequest'])->name('approvals.tutorial-seed-late-done-request');
        Route::post('/approvals/tutorial-seed-item-removal-request', [RentalApprovalController::class, 'seedTutorialItemRemovalRequest'])->name('approvals.tutorial-seed-item-removal-request');
    });

    // Activity log is visible to every role in the same store — not approval-only.
    Route::get('/rentals-activity-log', [RentalActivityLogController::class, 'index'])->name('rentals.activity-log.index');
    Route::get('/activity-log-panel', [RentalActivityLogController::class, 'panel'])->name('rentals.activity-log.panel');

    Route::resource('users', UserController::class)->only(['index', 'store', 'update', 'destroy'])->middleware('role:super_admin,management');
    // HR is planned but not built: this is a read-only "coming soon" page.
    Route::view('/hr', 'hr.coming-soon')->middleware('role:super_admin,management')->name('hr.index');

    Route::post('/users/{user}/reset-opname-pin', [UserController::class, 'resetOpnamePin'])->name('users.reset-opname-pin')->middleware('role:super_admin,management');
    Route::post('/users/tutorial/exit', [UserController::class, 'tutorialExit'])->name('users.tutorial.exit')->middleware('role:super_admin,management');
    Route::get('/stores', [StoreController::class, 'index'])->name('stores.index')->middleware('role:super_admin,management');
    Route::post('/stores', [StoreController::class, 'store'])->name('stores.store')->middleware('role:super_admin,management');
    Route::put('/stores/{store}', [StoreController::class, 'update'])->name('stores.update')->middleware('role:super_admin,management');
    Route::post('/stores/{store}/users', [StoreController::class, 'assignUsers'])->name('stores.users.assign')->middleware('role:super_admin,management');

    // Accounting module (Phase 1: Chart of Accounts + double-entry ledger +
    // expense/cash-in-out entry + read-only dashboard).
    Route::get('/accounting', [AccountingDashboardController::class, 'index'])->name('accounting.dashboard')
        ->middleware('role:super_admin,management,accounting');
    Route::post('/accounting/perlu-dicek/{rentalId}', [AccountingDashboardController::class, 'markChecked'])->whereNumber('rentalId')
        ->name('accounting.perlu-dicek.mark')->middleware('role:super_admin,management,accounting');
    Route::delete('/accounting/perlu-dicek/{rentalId}', [AccountingDashboardController::class, 'unmarkChecked'])->whereNumber('rentalId')
        ->name('accounting.perlu-dicek.unmark')->middleware('role:super_admin,management,accounting');
    // index is broader than store/update: an accountant reviewing reports needs
    // to see the account structure, but managing it (add/rename/deactivate)
    // stays Management/Super Admin only — see User::mayViewChartOfAccounts().
    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index')
        ->middleware('role:super_admin,management,accounting');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store')
        ->middleware('role:super_admin,management');
    Route::put('/accounts/{account}', [AccountController::class, 'update'])->name('accounts.update')
        ->middleware('role:super_admin,management');
    Route::delete('/accounts/{account}', [AccountController::class, 'destroy'])->name('accounts.destroy')
        ->middleware('role:super_admin,management');
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index')
        ->middleware('role:super_admin,management,admin,accounting');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store')
        ->middleware('role:super_admin,management,admin,accounting');
    Route::put('/expenses/{expense}', [ExpenseController::class, 'update'])->name('expenses.update')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/cash-movements', [CashMovementController::class, 'index'])->name('cash-movements.index')
        ->middleware('role:super_admin,management,admin,accounting');
    Route::post('/cash-movements', [CashMovementController::class, 'store'])->name('cash-movements.store')
        ->middleware('role:super_admin,management,admin,accounting');
    Route::put('/cash-movements/{cashMovement}', [CashMovementController::class, 'update'])->name('cash-movements.update')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/accounting/shifts-by-date', [ShiftController::class, 'accountingShiftsByDate'])->name('accounting.shifts-by-date')
        ->middleware('role:super_admin,management,accounting');

    // Manual journal entry — the one path for a non-cash reclassification
    // (e.g. depreciation) that neither Expenses nor Cash In/Out can express,
    // since both hard-require a cash/bank leg. Management/Super Admin only,
    // same level as Chart of Accounts management.
    Route::get('/accounting/journal-entries', [ManualJournalEntryController::class, 'index'])->name('accounting.journal-entries.index')
        ->middleware('role:super_admin,management');
    Route::post('/accounting/journal-entries', [ManualJournalEntryController::class, 'store'])->name('accounting.journal-entries.store')
        ->middleware('role:super_admin,management');

    Route::get('/accounting/reports/trial-balance', [AccountingReportController::class, 'trialBalance'])->name('accounting.reports.trial-balance')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/accounting/reports/trial-balance/pdf', [AccountingReportController::class, 'trialBalancePdf'])->name('accounting.reports.trial-balance.pdf')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/accounting/reports/balance-sheet', [AccountingReportController::class, 'balanceSheet'])->name('accounting.reports.balance-sheet')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/accounting/reports/balance-sheet/pdf', [AccountingReportController::class, 'balanceSheetPdf'])->name('accounting.reports.balance-sheet.pdf')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/accounting/reports/profit-loss', [AccountingReportController::class, 'profitLoss'])->name('accounting.reports.profit-loss')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/accounting/reports/profit-loss/pdf', [AccountingReportController::class, 'profitLossPdf'])->name('accounting.reports.profit-loss.pdf')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/accounting/reports/general-ledger', [AccountingReportController::class, 'generalLedger'])->name('accounting.reports.general-ledger')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/accounting/reports/general-ledger/{entry}', [AccountingReportController::class, 'generalLedgerEntry'])->name('accounting.reports.general-ledger.entry')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/accounting/reports/ppn', [AccountingReportController::class, 'ppn'])->name('accounting.reports.ppn')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/accounting/reports/piutang', [AccountingReportController::class, 'piutang'])->name('accounting.reports.piutang')
        ->middleware('role:super_admin,management,accounting');
    Route::get('/accounting/reports/fixed-assets', [FixedAssetController::class, 'index'])->name('accounting.reports.fixed-assets')
        ->middleware('role:super_admin,management,accounting');
    Route::post('/accounting/reports/fixed-assets', [FixedAssetController::class, 'store'])->name('accounting.fixed-assets.store')
        ->middleware('role:super_admin,management,accounting');
    Route::post('/accounting/reports/fixed-assets/{fixedAsset}/depreciate', [FixedAssetController::class, 'depreciate'])->name('accounting.fixed-assets.depreciate')
        ->middleware('role:super_admin,management,accounting');



    Route::get('/discounts/my-status', [\App\Http\Controllers\DiscountCheckController::class, 'myStatus'])->name('discounts.my-status');

    // Time-travel debug clock: fast-forward the app to test time-based rules (overdue fines, etc.).
    Route::post('/debug/fake-now', function (\Illuminate\Http\Request $request) {
        abort_unless(\App\Support\DebugClock::enabled(), 404);
        abort_unless(app()->environment('local') || $request->user()->isSuperAdmin(), 403);
        $validated = $request->validate(['fake_now' => 'nullable|date']);
        if (! empty($validated['fake_now'])) {
            cache()->forever('debug_fake_now', $validated['fake_now']);
        } else {
            cache()->forget('debug_fake_now');
        }

        return back();
    })->name('debug.fake-now');

    Route::middleware('role:super_admin,management')->group(function () {
        // Read-only check of old rentals whose bill differs between the old and the new day formula (decision D9).
        Route::get('/investigations', [\App\Http\Controllers\InvestigationController::class, 'index'])->name('investigations.index');
        Route::get('/reports/manager', [ManagerReportController::class, 'index'])->name('reports.manager');
        Route::get('/reports/manager/download-pdf', [ManagerReportController::class, 'downloadPdf'])->name('reports.manager.download-pdf');
        Route::get('/discounts', [\App\Http\Controllers\DiscountCheckController::class, 'index'])->name('discounts.index');
        Route::post('/rentals/deleted/bulk-destroy', [RentalController::class, 'bulkForceDelete'])->name('rentals.deleted.bulk-destroy');
        Route::post('/rentals/deleted/clear', [RentalController::class, 'clearDeleted'])->name('rentals.deleted.clear');
        Route::post('/discounts/{rental}/toggle', [\App\Http\Controllers\DiscountCheckController::class, 'toggle'])->name('discounts.toggle');
        Route::get('/approvals/rentals/queue-fragment', [RentalApprovalController::class, 'queueFragment'])->name('approvals.rentals.queue-fragment');

        Route::get('/settings', function () {
            $pricingEnabled = StoreCache::get('pricing_enabled', true);
            $pricingPricesIncludeTax = (bool) StoreCache::get('pricing_prices_include_tax', false);
            $defaultBufferDays = (int) config('app.rental_return_buffer_days', 1);
            $rentalReturnBufferDays = (int) StoreCache::get('rental_return_buffer_days', $defaultBufferDays);
            $pricingTaxPercent = (float) StoreCache::get('pricing_tax_percent', 0);
            $overdueFinePerDay = (float) StoreCache::get('overdue_fine_per_day', 100000);
            $overdueFineType = (string) StoreCache::get('overdue_fine_type', 'fixed');
            $overdueFinePercentPerDay = (float) StoreCache::get('overdue_fine_percent_per_day', 10);
            $whatsappPublicBaseUrl = (string) (StoreCache::get('whatsapp_public_base_url') ?? '');
            $invoiceSignedUrlDays = StoreCache::get('invoice_signed_url_days');
            if ($invoiceSignedUrlDays === null) {
                $invoiceSignedUrlDays = max(0, (int) env('INVOICE_SIGNED_URL_DAYS', 0));
            } else {
                $invoiceSignedUrlDays = max(0, (int) $invoiceSignedUrlDays);
            }

            $restrictAdminDeletes = AdminDeleteRestriction::enabled();
            $allowManagementDeleteRents = (bool) StoreCache::get('allow_management_delete_rents', true);
            $fonnteTokenSaved = FonnteSettings::hasSavedToken();
            $fonnteTokenSavedHint = FonnteSettings::savedTokenHint();
            $pricingLegacyCutOffDate = (string) (StoreCache::get('pricing_legacy_cut_off_date') ?? '');
            $adminTransactionEditMode = (bool) StoreCache::get('admin_transaction_edit_mode', false);
            $debugTimeTravelEnabled = \App\Support\DebugClock::enabled();
            $debugToolsEnabled = \App\Support\DebugTools::enabled();
            $loginMethod = (string) StoreCache::get('login_method', 'both');
            $accountingTrialModeEnabled = AccountingTrialMode::enabled();
            // Deliberately across every store (withoutGlobalScopes() also lifts
            // BelongsToStore's store_scope) — trial mode is a whole-business
            // switch, not a per-store one, so the count and the "clear" action
            // below both need to see everything.
            $accountingTrialDataCounts = [
                'accounts' => Account::withoutGlobalScopes()->where('is_tutorial', true)->count(),
                'expenses' => Expense::withoutGlobalScopes()->where('is_tutorial', true)->count(),
                'cash_movements' => CashMovement::withoutGlobalScopes()->where('is_tutorial', true)->count(),
            ];

            // Across every store, same reasoning as $accountingTrialDataCounts above.
            $rentalsMissingInvoiceNumberCount = \App\Models\Rental::withoutGlobalScope('store_scope')
                ->withTrashed()
                ->where('is_tutorial', false)
                ->whereNull('invoice_number')
                ->whereNotNull('store_id')
                ->count();

            $rentalLedgerDriftRows = \App\Support\RentalLedgerSync::driftRowsForDisplay();
            $rentalLedgerSyncPendingCount = \App\Support\RentalLedgerSync::pendingCount($rentalLedgerDriftRows);

            $cashEquivalentAccounts = Account::query()
                ->where('is_cash_equivalent', true)
                ->where('is_active', true)
                ->orderBy('code')
                ->get(['id', 'code', 'name']);
            $rentalRevenueCashAccountId = StoreCache::get('rental_revenue_cash_account_id')
                ?: Account::where('code', '1100')->value('id');
            $rentalRevenueNoncashAccountId = StoreCache::get('rental_revenue_noncash_account_id')
                ?: Account::where('code', '1200')->value('id');

            return view('settings.index', compact(
                'pricingEnabled',
                'pricingPricesIncludeTax',
                'rentalReturnBufferDays',
                'pricingTaxPercent',
                'overdueFinePerDay',
                'overdueFineType',
                'overdueFinePercentPerDay',
                'whatsappPublicBaseUrl',
                'invoiceSignedUrlDays',
                'restrictAdminDeletes',
                'allowManagementDeleteRents',
                'fonnteTokenSaved',
                'fonnteTokenSavedHint',
                'pricingLegacyCutOffDate',
                'adminTransactionEditMode',
                'debugTimeTravelEnabled',
                'debugToolsEnabled',
                'loginMethod',
                'accountingTrialModeEnabled',
                'accountingTrialDataCounts',
                'cashEquivalentAccounts',
                'rentalRevenueCashAccountId',
                'rentalRevenueNoncashAccountId',
                'rentalsMissingInvoiceNumberCount',
                'rentalLedgerSyncPendingCount',
                'rentalLedgerDriftRows',
            ));
        })->name('settings.index');

        Route::get('/settings/fonnte-token', function () {
            $token = StoreCache::get('fonnte_token');
            $value = is_string($token) ? trim($token) : '';
            if ($value === '') {
                $value = null;
            }

            return response()
                ->json(['token' => $value])
                ->header('Cache-Control', 'no-store, max-age=0');
        })->name('settings.fonnte-token');

        Route::post('/settings/pricing', function (Request $request) {
            $validated = $request->validate([
                'rental_return_buffer_days' => 'required|integer|min:0|max:30',
                'pricing_tax_percent' => 'required|numeric|min:0|max:100',
                'overdue_fine_per_day' => 'required|numeric|min:0|max:999999999.99',
                'overdue_fine_type' => 'nullable|in:fixed,percent,manual',
                'overdue_fine_percent_per_day' => 'nullable|numeric|min:0|max:100',
                'invoice_signed_url_days' => 'required|integer|min:0|max:365',
                'whatsapp_public_base_url' => 'nullable|string|max:500',
                'fonnte_token' => 'nullable|string|max:500',
                'pricing_legacy_cut_off_date' => 'nullable|date_format:Y-m-d',
                'rental_revenue_cash_account_id' => 'nullable|integer|exists:accounts,id',
                'rental_revenue_noncash_account_id' => 'nullable|integer|exists:accounts,id',
            ]);

            $base = trim((string) ($validated['whatsapp_public_base_url'] ?? ''));
            if ($base !== '' && ! filter_var($base, FILTER_VALIDATE_URL)) {
                return redirect()->route('settings.index')
                    ->withErrors(['whatsapp_public_base_url' => 'Must be a valid http(s) URL, or leave empty.'])
                    ->withInput();
            }

            $enabled = $request->boolean('pricing_enabled');
            StoreCache::forever('pricing_enabled', $enabled);
            StoreCache::forever('pricing_prices_include_tax', $request->boolean('pricing_prices_include_tax'));
            StoreCache::forever('rental_return_buffer_days', (int) $validated['rental_return_buffer_days']);
            StoreCache::forever('pricing_tax_percent', round((float) $validated['pricing_tax_percent'], 2));
            StoreCache::forever('overdue_fine_per_day', round((float) $validated['overdue_fine_per_day'], 2));
            StoreCache::forever('overdue_fine_type', $validated['overdue_fine_type'] ?? 'fixed');
            StoreCache::forever('overdue_fine_percent_per_day', round((float) ($validated['overdue_fine_percent_per_day'] ?? 10), 2));
            StoreCache::forever('invoice_signed_url_days', (int) $validated['invoice_signed_url_days']);
            $restrictAdminDeletes = $request->boolean('restrict_admin_deletes')
                || $request->boolean('restrict_admin_rent_transactions');
            StoreCache::forever('restrict_admin_deletes', $restrictAdminDeletes);
            StoreCache::forget('restrict_admin_rent_transactions');
            StoreCache::forever('allow_management_delete_rents', $request->boolean('allow_management_delete_rents'));
            StoreCache::forever('admin_transaction_edit_mode', $request->boolean('admin_transaction_edit_mode'));
            AccountingTrialMode::setEnabled($request->boolean('accounting_trial_mode_enabled'));

            if (! empty($validated['rental_revenue_cash_account_id'])) {
                StoreCache::forever('rental_revenue_cash_account_id', (int) $validated['rental_revenue_cash_account_id']);
            }
            if (! empty($validated['rental_revenue_noncash_account_id'])) {
                StoreCache::forever('rental_revenue_noncash_account_id', (int) $validated['rental_revenue_noncash_account_id']);
            }

            if ($request->user()->isSuperAdmin()) {
                \App\Support\DebugClock::setEnabled($request->boolean('debug_time_travel_enabled'));
                \App\Support\DebugTools::setEnabled($request->boolean('debug_tools_enabled'));
                $loginMethod = $request->input('login_method');
                if (in_array($loginMethod, ['email', 'username', 'both'], true)) {
                    StoreCache::forever('login_method', $loginMethod);
                }
            }

            $cutOffDate = trim((string) ($validated['pricing_legacy_cut_off_date'] ?? ''));
            if ($cutOffDate !== '') {
                StoreCache::forever('pricing_legacy_cut_off_date', $cutOffDate);
            } else {
                StoreCache::forget('pricing_legacy_cut_off_date');
            }

            if ($base === '') {
                StoreCache::forget('whatsapp_public_base_url');
            } else {
                StoreCache::forever('whatsapp_public_base_url', rtrim($base, '/'));
            }

            StoreCache::forget('fonnte_endpoint');

            // Only touch cached token when the field was submitted (present in the payload).
            // Password/API-token inputs may be omitted by the browser; treating that as "empty" used to wipe the saved token.
            if ($request->exists('fonnte_token')) {
                $fonnteToken = trim((string) $request->input('fonnte_token'));
                if ($fonnteToken !== '') {
                    // alsoGlobal: false — each store's WhatsApp token must stay
                    // isolated, never leaking to other stores as a fallback.
                    StoreCache::forever('fonnte_token', $fonnteToken, alsoGlobal: false);
                } else {
                    StoreCache::forget('fonnte_token');
                }
            }

            return redirect()->route('settings.index')->with('success', 'Settings updated successfully.');
        })->name('settings.pricing');

        // Permanently deletes every is_tutorial=true accounting row (across every
        // store — see the note on $accountingTrialDataCounts above). Real data is
        // never touched: JournalPostingService never lets a real entry reference a
        // trial account, and every write path stamps is_tutorial from the CURRENT
        // AccountingTrialMode state, so nothing here can ever be "real". Super
        // Admin only, unlike the rest of this settings page — this is the one
        // action here that's actually irreversible.
        Route::post('/settings/accounting-trial-data/clear', function () {
            DB::transaction(function () {
                $trialEntryIds = JournalEntry::withoutGlobalScopes()->where('is_tutorial', true)->pluck('id');
                // Deleted explicitly rather than relying on journal_entry_lines'
                // cascadeOnDelete — safe regardless of whether FK cascades happen
                // to be enforced on the current connection.
                JournalEntryLine::whereIn('journal_entry_id', $trialEntryIds)->delete();
                JournalEntry::withoutGlobalScopes()->where('is_tutorial', true)->delete();
                Expense::withoutGlobalScopes()->where('is_tutorial', true)->delete();
                CashMovement::withoutGlobalScopes()->where('is_tutorial', true)->delete();

                // Leaf-first: a trial account with trial children would otherwise
                // trip its own parent_id restrictOnDelete constraint. Deliberately
                // NOT whereDoesntHave('children') — that builds a fresh Account
                // subquery for the relationship, which (trial mode being off by
                // now) picks the accounting_trial_scope back up on its own and
                // makes a trial parent look childless while its trial child still
                // exists, letting both get queued for deletion in the same pass
                // and tripping the FK constraint. Plain scope-free queries avoid
                // that entirely. (Splitting select from delete also sidesteps a
                // separate MySQL-only error — "can't specify target table for
                // update in FROM clause" — from combining DELETE with a subquery
                // against the same table in one statement.)
                do {
                    $trialIds = Account::withoutGlobalScopes()->where('is_tutorial', true)->pluck('id');
                    if ($trialIds->isEmpty()) {
                        break;
                    }
                    $referencedAsParent = Account::withoutGlobalScopes()
                        ->whereIn('parent_id', $trialIds)
                        ->pluck('parent_id')
                        ->unique();
                    $leafIds = $trialIds->diff($referencedAsParent);
                    if ($leafIds->isEmpty()) {
                        break; // Shouldn't happen outside a cycle — avoid spinning forever.
                    }
                    Account::withoutGlobalScopes()->whereIn('id', $leafIds)->delete();
                } while (Account::withoutGlobalScopes()->where('is_tutorial', true)->exists());
            });

            return redirect()->route('settings.index')->with('success', 'Trial accounting data cleared.');
        })->middleware('role:super_admin')->name('settings.accounting-trial-data.clear');

        // Fills in invoice_number for every rental that doesn't have one yet
        // (across every store) — see App\Support\InvoiceNumberGenerator::backfillMissing().
        // Needed once after this feature first shipped (existing rentals had
        // none), and again any time a fresh production DB gets imported over
        // local/staging, since a plain SQL import never runs the model's own
        // `creating` hook. Safe to click repeatedly — only touches rentals
        // still missing a number.
        Route::post('/settings/rentals/backfill-invoice-numbers', function () {
            $count = \App\Support\InvoiceNumberGenerator::backfillMissing();

            return redirect()->route('settings.index')
                ->with('success', "Invoice numbers filled in for {$count} rentals.");
        })->middleware('role:super_admin')->name('settings.rentals.backfill-invoice-numbers');

        // Re-posts any rental payment (down payment / full / final payment)
        // that was actually recorded but never made it into the ledger —
        // see App\Support\RentalLedgerSync. Discovered 2026-09-18: everything
        // before 2026-09-11 18:34 never posted at all. Exposed as a button
        // (not a one-off script) specifically so it's safe to click again if
        // this — or a fresh prod DB import over local/staging — ever
        // reintroduces the gap. Safe to click repeatedly; only touches
        // payments still missing a journal entry.
        Route::post('/settings/rentals/sync-missing-journal-entries', function () {
            // Walks every rental (several queries each) — give shared hosting room to finish.
            @set_time_limit(300);
            $result = \App\Support\RentalLedgerSync::run();
            $message = "{$result['fixed']} payments were synced to the journal.";
            if ($result['created'] > 0) {
                $message .= " {$result['created']} of them had their payment record rebuilt from the rental data.";
            }
            if ($result['adjusted'] > 0) {
                $message .= " {$result['adjusted']} previously edited rentals had their journal adjusted.";
            }
            if (count($result['review']) > 0) {
                $message .= ' '.count($result['review']).' rentals need a manual check (see the list below).';
            }
            if ($result['failed'] > 0) {
                $message .= " {$result['failed']} failed — check the server log.";
            }

            return redirect()->route('settings.index')->with('success', $message);
        })->middleware('role:super_admin')->name('settings.rentals.sync-missing-journal-entries');
    });

    // Database explorer (super admin only)
    Route::get('/db', function () {
        $driver = DB::connection()->getDriverName();
        $tables = [];

        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
            $tables = array_map(fn ($r) => $r->name, $rows);
        } else {
            $rows = DB::select('SHOW TABLES');
            $tables = array_map('current', $rows);
        }

        $selectedTable = request('table');
        $columns = [];
        $rows = [];
        $count = 0;

        if ($selectedTable && in_array($selectedTable, $tables)) {
            $columns = DB::connection()->getSchemaBuilder()->getColumnListing($selectedTable);
            $count = DB::table($selectedTable)->count();
            $perPage = min((int) request('per_page', 25), 100);
            $page = max(1, (int) request('page', 1));
            $rows = DB::table($selectedTable)->orderBy('id')->paginate($perPage, ['*'], 'page', $page);
        }

        return view('db-explorer', compact('tables', 'selectedTable', 'columns', 'rows', 'count', 'driver'));
    })->middleware(['auth', 'role:super_admin'])->name('db.explorer');

    // Emergency read-only page
    Route::get('/emergency', function () {
        $rentals = Rental::with('outfits')->latest()->limit(200)->get();
        $items = Outfit::orderBy('code', 'asc')->get();

        return view('emergency', compact('rentals', 'items'));
    })->name('emergency');
});

// Public signed download for external channels (e.g. WhatsApp provider fetcher).
Route::get('/rentals/{rental}/invoice', [InvoiceController::class, 'view'])
    ->middleware('signed:relative')
    ->withTrashed()
    ->name('invoices.view');

Route::get('/rentals/{rental}/invoice/regenerate', [InvoiceController::class, 'regenerate'])
    ->middleware('signed:relative')
    ->withTrashed()
    ->name('invoices.regenerate');

Route::get('/rentals/{rental}/invoice/template-preview', [InvoiceController::class, 'templatePreview'])
    ->middleware('signed:relative')
    ->withTrashed()
    ->name('invoices.template-preview');

Route::get('/rentals/{rental}/invoice/download', [InvoiceController::class, 'download'])
    ->middleware('signed:relative')
    ->withTrashed()
    ->name('invoices.download');

Route::get('/outfits/{outfit}/status', [OutfitController::class, 'status'])
    ->middleware('signed:relative')
    ->name('outfits.status');

/** Stable printed-QR link (token never changes); optional per-item redirect in outfits.qr_redirect_url */
Route::get('/i/{token}', [OutfitController::class, 'scanByToken'])
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('outfits.scan');

// Opname-via-PIN on the public item page — see App\Support\StockOpnamePin.
// Throttled: a 6-digit PIN checked against every eligible user is otherwise
// brute-forceable from this public, unauthenticated route.
Route::post('/outfits/{outfit}/opname-pin', [OutfitController::class, 'submitOpnamePin'])
    ->middleware('throttle:10,1')
    ->name('outfits.opname-pin');
Route::post('/outfits/{outfit}/opname-pin/logout', [OutfitController::class, 'logoutOpnamePin'])
    ->name('outfits.opname-pin.logout');
Route::post('/outfits/{outfit}/opname-mark', [OutfitController::class, 'markViaOpnamePin'])
    ->name('outfits.opname-mark');

Route::get('/shifts/{shift}/report/pdf', [ShiftController::class, 'publicPdf'])
    ->middleware('signed:relative')
    ->name('shifts.report.pdf');

// Backup endpoint (protected by secret token for cron jobs).
// Constant-time comparison and a string-only token. No throttle on purpose: behind a shared proxy IP it could lock the cron out.
Route::get('/backup', function () {
    $token = request('token');
    $token = is_string($token) ? $token : '';
    $expected = (string) env('BACKUP_SECRET');

    if ($token === '' || $expected === '' || ! hash_equals($expected, $token)) {
        abort(401, 'Invalid backup token');
    }

    try {
        Artisan::call('db:backup');

        return response(Artisan::output());
    } catch (Exception $e) {
        return response('Backup failed: '.$e->getMessage(), 500);
    }
});

// Pricing visibility is switched on the Settings page (settings.pricing).

require __DIR__.'/auth.php';
