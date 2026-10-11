<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Expense;
use App\Models\Store;
use App\Support\JournalEntryValidationException;
use App\Support\ExpenseRecorder;
use App\Support\MoneyInput;
use App\Support\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ExpenseController extends Controller
{
    public function index(Request $request): View
    {
        $dateFrom = \App\Support\DateInput::date($request->input('date_from'), now()->startOfMonth()->toDateString());
        $dateTo = \App\Support\DateInput::date($request->input('date_to'), now()->toDateString());

        // Cross-store visibility mirrors the same roles SetCurrentStore already
        // grants the "all stores" bypass to — Accounting included, since it's a
        // centralized bookkeeping function, not a per-store operational role.
        $user = $request->user();
        $mayViewAllStores = (bool) ($user && ($user->isManagement() || $user->isSuperAdmin() || $user->isAccounting()));
        $allStoresMode = $mayViewAllStores && StoreContext::allStores();
        $storeFilterId = $allStoresMode ? (int) $request->input('expense_store_id', 0) : 0;

        $expenses = Expense::query()
            ->with(['account', 'paymentAccount', 'recordedBy', 'store'])
            ->when($storeFilterId > 0, fn ($q) => $q->where($q->qualifyColumn('store_id'), $storeFilterId))
            ->whereDate('expense_date', '>=', $dateFrom)->whereDate('expense_date', '<=', $dateTo)
            ->orderByDesc('expense_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $categoryAccounts = Account::query()
            ->where('type', 'expense')
            ->where('is_active', true)
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $paymentAccounts = Account::query()
            ->where('is_cash_equivalent', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $filterStores = $allStoresMode
            ? Store::query()->where('is_active', true)->orderBy('id')->get(['id', 'name'])
            : collect();

        return view('expenses.index', compact(
            'expenses', 'categoryAccounts', 'paymentAccounts', 'dateFrom', 'dateTo',
            'mayViewAllStores', 'allStoresMode', 'storeFilterId', 'filterStores'
        ));
    }

    public function store(Request $request): RedirectResponse
    {
        // Amount arrives as an Indonesian-formatted string ("1.500.000") from
        // the live-formatting input — normalize before validation, same
        // pattern RentalController uses for its own money fields.
        $request->merge(['amount' => MoneyInput::normalizeNullable($request->input('amount')) ?? $request->input('amount')]);

        $validated = $request->validate([
            'expense_date' => 'required|date',
            'account_id' => 'required|exists:accounts,id',
            'payment_account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01|max:999999999.99',
            'description' => 'nullable|string|max:500',
        ]);

        $storeId = StoreContext::storeId();
        if (! $storeId) {
            return back()->withInput()->withErrors(['expense_date' => 'Select a store before recording an expense.']);
        }

        try {
            ExpenseRecorder::record(
                storeId: $storeId,
                expenseDate: $validated['expense_date'],
                accountId: (int) $validated['account_id'],
                paymentAccountId: (int) $validated['payment_account_id'],
                amount: (float) $validated['amount'],
                description: $validated['description'] ?? null,
                recordedByUserId: $request->user()?->id,
                isTutorialPractice: $request->boolean('tutorial_practice'),
            );
        } catch (JournalEntryValidationException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('expenses.index')->with('success', 'Expense recorded successfully.');
    }

    public function update(Request $request, Expense $expense): RedirectResponse
    {
        $request->merge(['amount' => MoneyInput::normalizeNullable($request->input('amount')) ?? $request->input('amount')]);

        $validated = $request->validate([
            'expense_date' => 'required|date',
            'account_id' => 'required|exists:accounts,id',
            'payment_account_id' => 'required|exists:accounts,id',
            'amount' => 'required|numeric|min:0.01|max:999999999.99',
            'description' => 'nullable|string|max:500',
        ]);

        try {
            ExpenseRecorder::update(
                expense: $expense,
                expenseDate: $validated['expense_date'],
                accountId: (int) $validated['account_id'],
                paymentAccountId: (int) $validated['payment_account_id'],
                amount: (float) $validated['amount'],
                description: $validated['description'] ?? null,
                updatedByUserId: $request->user()?->id,
            );
        } catch (JournalEntryValidationException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('expenses.index')->with('success', 'Expense updated successfully.');
    }
}
