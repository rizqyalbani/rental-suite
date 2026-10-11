<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Support\AccountingTrialMode;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccountController extends Controller
{
    public function index(): View
    {
        $accounts = Account::query()->orderBy('code')->get();
        $topLevel = $accounts->whereNull('parent_id')->values();
        $parents = $accounts->whereNull('parent_id')->pluck('name', 'id');

        // Same "only real (non-trial) lines count as history" rule as
        // update()'s own lock check — computed once here (not per-account)
        // so the edit modal can tell the viewer up front whether Code/Type/
        // Normal Balance are still editable, instead of them finding out
        // only after a rejected submit.
        $accountIdsWithHistory = JournalEntryLine::query()
            ->whereHas('journalEntry', fn ($q) => $q->where('is_tutorial', false))
            ->distinct()
            ->pluck('account_id')
            ->flip();
        // Broader than $accountIdsWithHistory above (that one excludes trial
        // lines on purpose, for the code/type/normal_balance lock). Deletion
        // must never be offered for an account with ANY line at all — trial
        // included — since the DB's restrictOnDelete FK would reject it
        // regardless of trial status.
        $accountIdsWithAnyLines = JournalEntryLine::query()->distinct()->pluck('account_id')->flip();
        $accountIdsWithChildren = $accounts->pluck('parent_id')->filter()->unique()->flip();
        $accounts->each(function (Account $account) use ($accountIdsWithHistory, $accountIdsWithAnyLines, $accountIdsWithChildren) {
            $account->has_history = $accountIdsWithHistory->has($account->id);
            $account->can_delete = ! $accountIdsWithAnyLines->has($account->id) && ! $accountIdsWithChildren->has($account->id);
        });

        return view('accounts.index', compact('accounts', 'topLevel', 'parents'));
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'code' => 'required|string|max:20|unique:accounts,code',
            'name' => 'required|string|max:150',
            'type' => 'required|in:asset,liability,equity,revenue,expense',
            'normal_balance' => 'required|in:debit,credit',
            'parent_id' => 'nullable|exists:accounts,id',
            'is_cash_equivalent' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string|max:2000',
        ]);
        $validated['is_cash_equivalent'] = $request->boolean('is_cash_equivalent');
        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['is_tutorial'] = AccountingTrialMode::enabled();

        Account::create($validated);

        return redirect()->route('accounts.index')->with('success', 'Account created successfully.');
    }

    public function update(Request $request, Account $account): RedirectResponse
    {
        // Only real (non-trial) lines count as "history" for the lock below —
        // an account used only during trial-mode testing should stay freely
        // reclassifiable, same as it would be with zero usage at all.
        $hasHistory = $account->lines()->whereHas('journalEntry', function ($query) {
            $query->where('is_tutorial', false);
        })->exists();

        $rules = [
            'name' => 'required|string|max:150',
            'parent_id' => ['nullable', 'exists:accounts,id', function ($attribute, $value, $fail) use ($account) {
                if ($value !== null && (int) $value === $account->id) {
                    $fail('An account cannot be its own parent.');
                }
            }],
            'is_cash_equivalent' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'description' => 'nullable|string|max:2000',
        ];
        // Once real journal lines exist against this account, its
        // classification can no longer change — a type/normal_balance
        // flip after the fact would silently corrupt every historical
        // balance computed from it.
        if (! $hasHistory) {
            $rules['code'] = 'required|string|max:20|unique:accounts,code,'.$account->id;
            $rules['type'] = 'required|in:asset,liability,equity,revenue,expense';
            $rules['normal_balance'] = 'required|in:debit,credit';
        }

        $validated = $request->validate($rules);
        $validated['is_cash_equivalent'] = $request->boolean('is_cash_equivalent');
        $validated['is_active'] = $request->boolean('is_active');

        $account->update($validated);

        return redirect()->route('accounts.index')->with('success', 'Account updated successfully.');
    }

    public function destroy(Account $account): RedirectResponse
    {
        // No pre-check duplicated here — the DB's own restrictOnDelete FKs
        // (parent_id, and every account_id column across journal_entry_lines/
        // expenses/cash_movements) are the single source of truth for whether
        // this account is actually unreferenced. Catching the constraint
        // violation keeps this in sync with that schema automatically.
        try {
            $account->delete();
        } catch (QueryException $e) {
            return back()->withErrors(['delete' => 'This account can\'t be deleted — it still has child accounts or related transactions. Deactivate it instead.']);
        }

        return redirect()->route('accounts.index')->with('success', 'Account deleted successfully.');
    }
}
