<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\FixedAsset;
use App\Support\FixedAssetRecorder;
use App\Support\JournalEntryValidationException;
use App\Support\MoneyInput;
use App\Support\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * "Fixed Assets" — both the account-level summary (Purchase Price/Total
 * Depreciated/Book Value per account, unchanged from the original report) AND,
 * below it, the per-item registry the account-level view alone can't answer:
 * WHICH physical items (a broom, a hanger batch, a wardrobe display) make up
 * that total. Registering an item here posts its purchase for real — see
 * FixedAssetRecorder — same one-step pattern Expenses already uses.
 */
class FixedAssetController extends Controller
{
    public function index(Request $request): View
    {
        $asOf = \App\Support\DateInput::date($request->input('as_of'), now()->toDateString());
        $storeId = $this->resolveStoreId();

        // Hardcoded, matching how RentalRevenueRecorder resolves accounts by
        // known code rather than a generic "is this a fixed asset?" flag —
        // add a code here when a new fixed-asset account is created.
        $accounts = Account::query()
            ->whereIn('code', ['1600', '1650'])
            ->orderBy('code')
            ->get();

        $rows = [];
        $totalPurchased = 0.0;
        $totalDepreciated = 0.0;
        $totalBookValue = 0.0;
        foreach ($accounts as $account) {
            ['debit' => $purchased, 'credit' => $legacyCredits] = $account->grossActivity($storeId, $asOf);

            // Real depreciation now always credits the Accumulated Depreciation
            // (1610) contra-asset account, tagged to a specific item — never
            // the asset's own account (see FixedAsset::totalDepreciated()).
            // So "Total Depreciated" here is the sum of that, not a credit to
            // THIS account. $legacyCredits (grossActivity's raw credit side
            // of 1600/1650 itself) is old noise from before this design —
            // e.g. a Cash In/Out correction that moved an entry out of this
            // account — kept only as an informational footnote, and no
            // longer subtracted into book value (it was never real
            // depreciation, so it shouldn't have reduced it).
            $itemDepreciated = (float) FixedAsset::query()
                ->where('account_id', $account->id)
                ->when($storeId, fn ($q) => $q->where('store_id', $storeId))
                ->get()
                ->sum(fn (FixedAsset $asset) => $asset->totalDepreciated());
            $bookValue = $purchased - $itemDepreciated;

            $rows[] = [
                'account' => $account,
                'purchased' => $purchased,
                'depreciated' => $itemDepreciated,
                'otherCredits' => round($legacyCredits, 2),
                'bookValue' => $bookValue,
            ];
            $totalPurchased += $purchased;
            $totalDepreciated += $itemDepreciated;
            $totalBookValue += $bookValue;
        }

        $assets = FixedAsset::query()
            ->with(['account'])
            ->orderByDesc('purchase_date')
            ->orderByDesc('id')
            ->get()
            ->map(fn (FixedAsset $asset) => [
                'asset' => $asset,
                'depreciated' => $asset->totalDepreciated(),
                'bookValue' => $asset->bookValue(),
                // Pre-formatted for the "History" modal's @json() payload —
                // keeps date/number formatting out of the JS side.
                'history' => $asset->depreciationHistory()->map(fn (array $h) => [
                    'date' => $h['date']->format('d M Y'),
                    'amount' => 'Rp '.number_format($h['amount'], 0, ',', '.'),
                    'note' => $h['note'],
                ])->values(),
            ]);

        $assetAccounts = $accounts; // same 1600/1650 list, reused as the "Category" choices on the add-item form
        $paymentAccounts = Account::query()
            ->where('is_cash_equivalent', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return view('accounting.reports.fixed-assets', [
            'rows' => $rows,
            'totalPurchased' => $totalPurchased,
            'totalDepreciated' => $totalDepreciated,
            'totalBookValue' => $totalBookValue,
            'assets' => $assets,
            'assetAccounts' => $assetAccounts,
            'paymentAccounts' => $paymentAccounts,
            'asOf' => $asOf,
            'storeLabel' => $this->storeLabel(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $request->merge(['purchase_price' => MoneyInput::normalizeNullable($request->input('purchase_price')) ?? $request->input('purchase_price')]);

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'account_id' => 'required|exists:accounts,id',
            'purchase_date' => 'required|date',
            'purchase_price' => 'required|numeric|min:0.01|max:999999999.99',
            'payment_account_id' => 'required|exists:accounts,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $storeId = StoreContext::storeId();
        if (! $storeId) {
            return back()->withInput()->withErrors(['purchase_date' => 'Select a store before adding an asset.']);
        }

        try {
            FixedAssetRecorder::record(
                storeId: $storeId,
                name: $validated['name'],
                accountId: (int) $validated['account_id'],
                purchaseDate: $validated['purchase_date'],
                purchasePrice: (float) $validated['purchase_price'],
                paymentAccountId: (int) $validated['payment_account_id'],
                notes: $validated['notes'] ?? null,
                createdByUserId: $request->user()?->id,
                isTutorialPractice: $request->boolean('tutorial_practice'),
            );
        } catch (JournalEntryValidationException $e) {
            return back()->withInput()->withErrors(['purchase_price' => $e->getMessage()]);
        }

        return redirect()->route('accounting.reports.fixed-assets')->with('success', 'Asset added successfully.');
    }

    /**
     * Guided depreciation entry, scoped to one already-registered item — no
     * Debit/Credit account picker for the caller to get wrong. Posts via
     * FixedAssetRecorder::depreciate() (Dr Depreciation Expense / Cr Accumulated
     * Depreciation). The general Manual Journal Entry page still exists
     * separately for anything this guided form doesn't cover.
     */
    public function depreciate(Request $request, FixedAsset $fixedAsset): RedirectResponse
    {
        $request->merge(['amount' => MoneyInput::normalizeNullable($request->input('amount')) ?? $request->input('amount')]);

        $validated = $request->validate([
            'date' => 'required|date',
            'amount' => 'required|numeric|min:0.01|max:999999999.99',
            'note' => 'nullable|string|max:500',
        ]);

        try {
            FixedAssetRecorder::depreciate(
                asset: $fixedAsset,
                date: $validated['date'],
                amount: (float) $validated['amount'],
                note: $validated['note'] ?? null,
                createdByUserId: $request->user()?->id,
                isTutorialPractice: $request->boolean('tutorial_practice'),
            );
        } catch (JournalEntryValidationException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('accounting.reports.fixed-assets')->with('success', "Depreciation for {$fixedAsset->name} recorded.");
    }

    private function resolveStoreId(): ?int
    {
        return StoreContext::allStores() ? null : StoreContext::storeId();
    }

    private function storeLabel(): string
    {
        return StoreContext::allStores() ? 'All Stores' : 'Current Store';
    }
}
