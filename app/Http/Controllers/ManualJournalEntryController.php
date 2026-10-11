<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Support\JournalEntryValidationException;
use App\Support\JournalPostingService;
use App\Support\MoneyInput;
use App\Support\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The one place a non-cash reclassification (e.g. depreciation: Debit Depreciation
 * Expense, Credit an asset — no money moves) can be posted. Expenses and
 * Cash In/Out both hard-require a cash/bank leg, so neither fits this case.
 * Deliberately narrow (always 2 lines, always Management/Super Admin) rather
 * than a general ledger-entry composer.
 */
class ManualJournalEntryController extends Controller
{
    public function index(Request $request): View
    {
        $dateFrom = \App\Support\DateInput::date($request->input('date_from'), now()->startOfMonth()->toDateString());
        $dateTo = \App\Support\DateInput::date($request->input('date_to'), now()->toDateString());

        $entries = JournalEntry::query()
            ->with(['lines.account', 'fixedAsset'])
            ->where('reference_type', 'manual_journal_entry')
            ->whereDate('entry_date', '>=', $dateFrom)->whereDate('entry_date', '<=', $dateTo)
            ->orderByDesc('entry_date')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        $accounts = Account::query()
            ->where('is_active', true)
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        // Optional "Asset being depreciated" picker — lets a depreciation entry
        // tag which registered item (Fixed Assets) it's for, so that page can
        // compute Total Depreciated per item instead of only per account.
        $fixedAssets = FixedAsset::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('accounting.journal-entries.index', compact('entries', 'accounts', 'fixedAssets', 'dateFrom', 'dateTo'));
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['amount' => MoneyInput::normalizeNullable($request->input('amount')) ?? $request->input('amount')]);

        $validated = $request->validate([
            'entry_date' => 'required|date',
            'debit_account_id' => 'required|exists:accounts,id|different:credit_account_id',
            'credit_account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01|max:999999999.99',
            'memo' => 'required|string|max:500',
            'fixed_asset_id' => 'nullable|exists:fixed_assets,id',
        ]);

        $storeId = StoreContext::storeId();
        if (! $storeId) {
            return back()->withInput()->withErrors(['entry_date' => 'Select a store before recording a journal entry.']);
        }

        $isTutorialPractice = $request->boolean('tutorial_practice');

        try {
            JournalPostingService::postTwoLine(
                storeId: $storeId,
                entryDate: $validated['entry_date'],
                memo: $validated['memo'],
                debitAccountId: (int) $validated['debit_account_id'],
                creditAccountId: (int) $validated['credit_account_id'],
                amount: (float) $validated['amount'],
                createdByUserId: $request->user()?->id,
                referenceType: 'manual_journal_entry',
                isTutorialPractice: $isTutorialPractice,
                fixedAssetId: isset($validated['fixed_asset_id']) ? (int) $validated['fixed_asset_id'] : null,
            );
        } catch (JournalEntryValidationException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        $redirect = redirect()->route('accounting.journal-entries.index')->with('success', 'Journal entry recorded successfully.');

        // A practice entry is is_tutorial_practice=true, which
        // ExcludesAccountingTrialData excludes from every real query
        // unconditionally — including this page's OWN "Manual Entry
        // History" table below, right where the tutorial's next step
        // points, making a successful submission look like it silently
        // failed. Flashing the just-submitted values into the session (not
        // re-querying the DB) lets the view render a one-time "preview" row
        // for this single redirect only, without loosening the real query's
        // exclusion for anyone else or on any later page load.
        if ($isTutorialPractice) {
            $debitAccount = Account::find($validated['debit_account_id']);
            $creditAccount = Account::find($validated['credit_account_id']);
            $redirect->with('tutorialPracticePreview', [
                'entry_date' => $validated['entry_date'],
                'memo' => $validated['memo'],
                'debit_label' => $debitAccount ? "{$debitAccount->code} — {$debitAccount->name}" : '—',
                'credit_label' => $creditAccount ? "{$creditAccount->code} — {$creditAccount->name}" : '—',
                'amount' => (float) $validated['amount'],
            ]);
        }

        return $redirect;
    }
}
