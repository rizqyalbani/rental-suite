<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\CashMovement;
use App\Support\CashMovementRecorder;
use App\Support\JournalEntryValidationException;
use App\Support\MoneyInput;
use App\Support\StoreContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CashMovementController extends Controller
{
    public function index(Request $request): View
    {
        $dateFrom = \App\Support\DateInput::date($request->input('date_from'), now()->startOfMonth()->toDateString());
        $dateTo = \App\Support\DateInput::date($request->input('date_to'), now()->toDateString());

        $movements = CashMovement::query()
            ->with(['cashAccount', 'counterAccount', 'recordedBy'])
            ->whereDate('movement_date', '>=', $dateFrom)->whereDate('movement_date', '<=', $dateTo)
            ->orderByDesc('movement_date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        $cashAccounts = Account::query()
            ->where('is_cash_equivalent', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        $counterAccounts = Account::query()
            ->where('is_active', true)
            ->whereDoesntHave('children')
            // "Opening Balance Adjustment" is a one-time, sensitive bookkeeping
            // correction (resetting Store Cash to match a physical count) — not
            // a routine Admin task, scoped to the roles that already handle
            // accounting corrections generally.
            ->when(! $request->user()?->mayEditAccountingEntries(), fn ($q) => $q->where('code', '!=', '3250'))
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return view('cash-movements.index', compact('movements', 'cashAccounts', 'counterAccounts', 'dateFrom', 'dateTo'));
    }

    public function store(Request $request): RedirectResponse
    {
        // Amount arrives as an Indonesian-formatted string ("500.000") from
        // the live-formatting input — normalize before validation, same
        // pattern RentalController uses for its own money fields.
        $request->merge(['amount' => MoneyInput::normalizeNullable($request->input('amount')) ?? $request->input('amount')]);

        $validated = $request->validate([
            'movement_date' => 'required|date',
            'direction' => 'required|in:in,out',
            'cash_account_id' => 'required|exists:accounts,id',
            'counter_account_id' => 'required|exists:accounts,id|different:cash_account_id',
            'amount' => 'required|numeric|min:0.01|max:999999999.99',
            'description' => 'nullable|string|max:500',
        ]);

        // Real enforcement, not just a hidden dropdown option — same
        // reasoning as the index() query above.
        if (! $request->user()?->mayEditAccountingEntries()) {
            $counterAccount = Account::find($validated['counter_account_id']);
            if ($counterAccount?->code === '3250') {
                return back()->withInput()->withErrors(['counter_account_id' => 'Only Management, Super Admin, or Accounting can post an opening-balance adjustment.']);
            }
        }

        $storeId = StoreContext::storeId();
        if (! $storeId) {
            return back()->withInput()->withErrors(['movement_date' => 'Select a store before recording a cash movement.']);
        }

        try {
            CashMovementRecorder::record(
                storeId: $storeId,
                movementDate: $validated['movement_date'],
                direction: $validated['direction'],
                cashAccountId: (int) $validated['cash_account_id'],
                counterAccountId: (int) $validated['counter_account_id'],
                amount: (float) $validated['amount'],
                description: $validated['description'] ?? null,
                recordedByUserId: $request->user()?->id,
            );
        } catch (JournalEntryValidationException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('cash-movements.index')->with('success', 'Cash movement recorded successfully.');
    }

    public function update(Request $request, CashMovement $cashMovement): RedirectResponse
    {
        $request->merge(['amount' => MoneyInput::normalizeNullable($request->input('amount')) ?? $request->input('amount')]);

        $validated = $request->validate([
            'movement_date' => 'required|date',
            'direction' => 'required|in:in,out',
            'cash_account_id' => 'required|exists:accounts,id',
            'counter_account_id' => 'required|exists:accounts,id|different:cash_account_id',
            'amount' => 'required|numeric|min:0.01|max:999999999.99',
            'description' => 'nullable|string|max:500',
        ]);

        try {
            CashMovementRecorder::update(
                movement: $cashMovement,
                movementDate: $validated['movement_date'],
                direction: $validated['direction'],
                cashAccountId: (int) $validated['cash_account_id'],
                counterAccountId: (int) $validated['counter_account_id'],
                amount: (float) $validated['amount'],
                description: $validated['description'] ?? null,
                updatedByUserId: $request->user()?->id,
            );
        } catch (JournalEntryValidationException $e) {
            return back()->withInput()->withErrors(['amount' => $e->getMessage()]);
        }

        return redirect()->route('cash-movements.index')->with('success', 'Cash movement updated successfully.');
    }
}
