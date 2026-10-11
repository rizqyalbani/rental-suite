<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Support\AccountingTrialMode;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Dompdf\Dompdf;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class AccountingReportController extends Controller
{
    public function trialBalance(Request $request): View
    {
        return view('accounting.reports.trial-balance', $this->buildTrialBalanceData($request));
    }

    public function trialBalancePdf(Request $request): Response
    {
        return $this->renderPdf('accounting.reports.trial-balance-pdf', $this->buildTrialBalanceData($request), 'trial-balance.pdf');
    }

    public function balanceSheet(Request $request): View
    {
        return view('accounting.reports.balance-sheet', $this->buildBalanceSheetData($request));
    }

    public function balanceSheetPdf(Request $request): Response
    {
        return $this->renderPdf('accounting.reports.balance-sheet-pdf', $this->buildBalanceSheetData($request), 'balance-sheet.pdf');
    }

    public function profitLoss(Request $request): View
    {
        return view('accounting.reports.profit-loss', $this->buildProfitLossData($request));
    }

    public function profitLossPdf(Request $request): Response
    {
        return $this->renderPdf('accounting.reports.profit-loss-pdf', $this->buildProfitLossData($request), 'profit-loss.pdf');
    }

    /**
     * VAT preview — a read-only estimate of what would need to be remitted
     * for a period, computed from Rental Revenue (4100)'s existing activity
     * and the live pricing_tax_percent/pricing_prices_include_tax Settings.
     * Deliberately does NOT post anything (no 2400 Output VAT entry, no
     * journal at all) — the business hasn't committed to real VAT posting
     * yet, this is only meant to hand them a number for their own tax
     * filing/preview purposes. See the accounting-module-phase1 memory note
     * on why real VAT posting stayed deferred.
     */
    public function ppn(Request $request): View
    {
        $dateFrom = \App\Support\DateInput::date($request->input('date_from'), now()->startOfMonth()->toDateString());
        $dateTo = \App\Support\DateInput::date($request->input('date_to'), now()->toDateString());
        $storeId = $this->resolveStoreId();

        $revenueAccount = Account::query()->where('code', '4100')->first();
        $grossRevenue = $revenueAccount ? $revenueAccount->balance($storeId, $dateTo, $dateFrom) : 0.0;

        $taxPercent = max(0.0, (float) StoreCache::get('pricing_tax_percent', 0));
        $pricesIncludeTax = (bool) StoreCache::get('pricing_prices_include_tax', false);

        if ($taxPercent <= 0) {
            $dpp = $grossRevenue;
            $ppn = 0.0;
        } elseif ($pricesIncludeTax) {
            // Same unwrap formula as RentalFinancialSummary's tax-inclusive
            // fix this session: base = gross / (1 + rate), not gross * rate.
            $dpp = round($grossRevenue / (1 + $taxPercent / 100), 2);
            $ppn = round($grossRevenue - $dpp, 2);
        } else {
            $dpp = $grossRevenue;
            $ppn = round($grossRevenue * $taxPercent / 100, 2);
        }

        return view('accounting.reports.ppn', [
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'grossRevenue' => $grossRevenue,
            'dpp' => $dpp,
            'ppn' => $ppn,
            'taxPercent' => $taxPercent,
            'pricesIncludeTax' => $pricesIncludeTax,
            'revenueAccount' => $revenueAccount,
            'storeLabel' => $this->storeLabel(),
        ]);
    }

    /**
     * Receivables (Accounts Receivable) — who still owes how much, and since
     * when. The posting mechanism already exists (RentalRevenueRecorder
     * books the unpaid remainder to 1300 Rental Receivable on a rental's first
     * down payment, well before that cash is collected — see that class's
     * docblock) — this report is the missing visibility layer on top of
     * data that was already being recorded with nowhere to look at it.
     * Aggregated per rental (not just the account-wide total another report
     * already shows) by tracing 1300's journal lines back through
     * rental_payment_records.rental_id, since every posting site tags
     * reference_type='rental_payment' / reference_id=<payment record id>,
     * never the rental directly.
     */
    public function piutang(Request $request): View
    {
        $storeId = $this->resolveStoreId();

        $piutangAccount = Account::query()->where('code', '1300')->first();
        if (! $piutangAccount) {
            return view('accounting.reports.piutang', [
                'rows' => collect(),
                'total' => 0.0,
                'storeLabel' => $this->storeLabel(),
                'missingAccount' => true,
            ]);
        }

        $query = JournalEntryLine::query()
            ->select('rpr.rental_id')
            ->selectRaw('SUM(journal_entry_lines.debit - journal_entry_lines.credit) as net_piutang')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('rental_payment_records as rpr', 'rpr.id', '=', 'journal_entries.reference_id')
            ->where('journal_entries.reference_type', 'rental_payment')
            ->where('journal_entry_lines.account_id', $piutangAccount->id);
        $this->applyLedgerScopes($query, $storeId);

        $balances = $query->groupBy('rpr.rental_id')
            ->havingRaw('SUM(journal_entry_lines.debit - journal_entry_lines.credit) > 0.004')
            ->get();

        $rentalIds = $balances->pluck('rental_id');
        $rentals = \App\Models\Rental::query()
            ->withoutGlobalScope('store_scope')
            ->whereIn('id', $rentalIds)
            ->get()
            ->keyBy('id');

        $rows = $balances->map(function ($row) use ($rentals) {
            $rental = $rentals->get($row->rental_id);

            return [
                'rental' => $rental,
                'rental_id' => $row->rental_id,
                'net_piutang' => round((float) $row->net_piutang, 2),
            ];
        })
            ->filter(fn ($row) => $row['rental'] !== null)
            ->sortBy(fn ($row) => $row['rental']->start_date)
            ->values();

        return view('accounting.reports.piutang', [
            'rows' => $rows,
            'total' => round($rows->sum('net_piutang'), 2),
            'storeLabel' => $this->storeLabel(),
            'missingAccount' => false,
        ]);
    }

    public function generalLedger(Request $request): View
    {
        $search = \App\Support\DateInput::text($request->input('search'), 200);
        $dateFrom = \App\Support\DateInput::date($request->input('date_from'), now()->startOfMonth()->toDateString());
        $dateTo = \App\Support\DateInput::date($request->input('date_to'), now()->toDateString());
        $accountId = $request->integer('account_id') ?: null;
        $storeId = $this->resolveStoreId();

        $query = JournalEntryLine::query()
            ->select('journal_entry_lines.*')
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            // JournalEntry's own BelongsToStore global scope would otherwise
            // silently filter this eager load down to the CURRENT store
            // context, regardless of what the query above already matched —
            // a cross-store search result's journalEntry then loads as null,
            // not "no rows found". The lines above are already scoped
            // correctly (by applyLedgerScopes below, store-wide only when
            // actually searching), so this eager load doesn't need its own.
            ->with([
                'journalEntry' => fn ($q) => $q->withoutGlobalScope('store_scope')->with(['fixedAsset', 'store']),
                'account',
            ]);
        // A search means "find this, wherever it is" — the date range only
        // applies when there's no search term at all, so looking up one
        // customer/rental never depends on the viewer also remembering to
        // widen the date pickers to wherever that entry happens to fall.
        if ($search === '') {
            // whereDate(), not where() — see the comment on Account::balance()
            // for why a plain string comparison against entry_date breaks.
            $query->whereDate('journal_entries.entry_date', '>=', $dateFrom)
                ->whereDate('journal_entries.entry_date', '<=', $dateTo);
        }
        // Same "find this, wherever it is" reasoning extends to the store
        // scope — a customer can turn up under either store (seen for real:
        // a customer landed on store 2 while the paper tally that named them
        // grouped them under store 1), so a search also ignores whichever
        // store happens to be active rather than silently missing them.
        $this->applyLedgerScopes($query, $search === '' ? $storeId : null);

        if ($accountId) {
            $query->where('journal_entry_lines.account_id', $accountId);
        }

        // Every rental-related entry's memo already reads "<Kind> — Rental
        // #<id> (<customer name>)" (see RentalRevenueRecorder::memoFor) —
        // searching the memo text directly covers "search by rental" and
        // "search by customer name" at once, with no join back through
        // rental_payment_records needed.
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('journal_entries.memo', 'like', '%'.$search.'%')
                    ->orWhere('journal_entry_lines.line_memo', 'like', '%'.$search.'%');
            });
        }

        $lines = $query
            ->orderByDesc('journal_entries.entry_date')
            ->orderByDesc('journal_entry_lines.id')
            ->paginate(25)
            ->withQueryString();

        // Live search re-fetches just the rows via AJAX (see the script in
        // general-ledger.blade.php) — a full page navigation for every
        // keystroke was the "annoying fullscreen loading" the business asked
        // to drop, so this returns only the rows/pagination partial when
        // that JS is the one asking.
        if ($request->ajax()) {
            return view('accounting.reports.partials.general-ledger-rows', [
                'lines' => $lines,
                'search' => $search,
                'fixedAssetAccountCodes' => ['1600', '1650'],
            ]);
        }

        $accounts = Account::query()
            ->where('is_active', true)
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return view('accounting.reports.general-ledger', [
            'lines' => $lines,
            'accounts' => $accounts,
            'accountId' => $accountId,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'search' => $search,
            'storeLabel' => $this->storeLabel(),
            // Same account codes FixedAssetController treats as fixed-asset
            // accounts — used so a credit line on one of them that isn't
            // tagged to any registered item can be flagged as "not attached"
            // instead of silently looking the same as real depreciation.
            'fixedAssetAccountCodes' => ['1600', '1650'],
        ]);
    }

    public function generalLedgerEntry(int $entry): View
    {
        // Not implicit route-model-binding on purpose — JournalEntry's own
        // BelongsToStore global scope would 404 a cross-store entry (e.g.
        // one just found via generalLedger()'s store-agnostic search) purely
        // because a DIFFERENT store happens to be active in the sidebar,
        // even though the viewer is allowed to see every store's ledger.
        $entry = JournalEntry::query()->withoutGlobalScope('store_scope')->findOrFail($entry);
        $entry->load(['lines.account', 'createdBy']);

        $reverses = $entry->reverses_journal_entry_id
            ? JournalEntry::withoutGlobalScopes()->with('lines.account')->find($entry->reverses_journal_entry_id)
            : null;
        $reversedBy = JournalEntry::withoutGlobalScopes()->with('lines.account')
            ->where('reverses_journal_entry_id', $entry->id)
            ->first();

        return view('accounting.reports.general-ledger-entry', compact('entry', 'reverses', 'reversedBy'));
    }

    /**
     * @return array{rows: array, totalDebit: float, totalCredit: float, asOf: string, storeLabel: string}
     */
    private function buildTrialBalanceData(Request $request): array
    {
        $asOf = \App\Support\DateInput::date($request->input('as_of'), now()->toDateString());
        $storeId = $this->resolveStoreId();

        $accounts = Account::query()
            ->where('is_active', true)
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get();

        $rows = [];
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($accounts as $account) {
            $balance = $account->balance($storeId, $asOf);
            if (abs($balance) <= 0.004) {
                continue;
            }

            // balance() is signed relative to the account's own normal side —
            // positive means it sits on that side, negative means it has
            // flipped (e.g. an overdrawn cash account). Bucket accordingly so
            // the two columns are the actual trial: they must sum equal.
            $onNormalSide = $balance >= 0;
            $debit = 0.0;
            $credit = 0.0;
            if ($account->isDebitNormal()) {
                $onNormalSide ? $debit = $balance : $credit = abs($balance);
            } else {
                $onNormalSide ? $credit = $balance : $debit = abs($balance);
            }

            $rows[] = ['account' => $account, 'debit' => $debit, 'credit' => $credit];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        return [
            'rows' => $rows,
            'totalDebit' => $totalDebit,
            'totalCredit' => $totalCredit,
            'asOf' => $asOf,
            'storeLabel' => $this->storeLabel(),
        ];
    }

    /**
     * @return array{assetRows: array, liabilityRows: array, equityRows: array, assetTotal: float, liabilityTotal: float, equityTotal: float, currentEarnings: float, asOf: string, storeLabel: string}
     */
    private function buildBalanceSheetData(Request $request): array
    {
        $asOf = \App\Support\DateInput::date($request->input('as_of'), now()->toDateString());
        $storeId = $this->resolveStoreId();

        $leafByType = fn (string $type) => Account::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get();

        $buildRows = function ($accounts) use ($storeId, $asOf) {
            $rows = [];
            $total = 0.0;
            foreach ($accounts as $account) {
                $balance = $account->balance($storeId, $asOf);
                if (abs($balance) <= 0.004) {
                    continue;
                }
                $rows[] = ['account' => $account, 'balance' => $balance];
                $total += $balance;
            }

            return [$rows, $total];
        };

        [$assetRows, $assetTotal] = $buildRows($leafByType('asset'));
        [$liabilityRows, $liabilityTotal] = $buildRows($leafByType('liability'));
        [$equityRows, $equityTotal] = $buildRows($leafByType('equity'));

        // There's no period-closing/retained-earnings-rollover mechanism yet,
        // so without this the sheet would never balance — Assets minus
        // Liabilities minus real Equity is, by construction of double-entry
        // bookkeeping, exactly cumulative Net Income to date. Shown as its
        // own clearly-labeled computed line, not a posted account.
        $revenueTotal = 0.0;
        foreach ($leafByType('revenue') as $account) {
            $revenueTotal += $this->revenueEffect($account, $account->balance($storeId, $asOf));
        }
        $expenseTotal = 0.0;
        foreach ($leafByType('expense') as $account) {
            $expenseTotal += $account->balance($storeId, $asOf);
        }
        $currentEarnings = $revenueTotal - $expenseTotal;

        return [
            'assetRows' => $assetRows,
            'liabilityRows' => $liabilityRows,
            'equityRows' => $equityRows,
            'assetTotal' => $assetTotal,
            'liabilityTotal' => $liabilityTotal,
            'equityTotal' => $equityTotal + $currentEarnings,
            'currentEarnings' => $currentEarnings,
            'asOf' => $asOf,
            'storeLabel' => $this->storeLabel(),
        ];
    }

    /**
     * @return array{revenueRows: array, expenseRows: array, revenueTotal: float, expenseTotal: float, netIncome: float, dateFrom: string, dateTo: string, storeLabel: string}
     */
    private function buildProfitLossData(Request $request): array
    {
        $dateFrom = \App\Support\DateInput::date($request->input('date_from'), now()->startOfMonth()->toDateString());
        $dateTo = \App\Support\DateInput::date($request->input('date_to'), now()->toDateString());
        $storeId = $this->resolveStoreId();

        $leafByType = fn (string $type) => Account::query()
            ->where('type', $type)
            ->where('is_active', true)
            ->whereDoesntHave('children')
            ->orderBy('code')
            ->get();

        $buildRows = function ($accounts, bool $isRevenue = false) use ($storeId, $dateFrom, $dateTo) {
            $rows = [];
            $total = 0.0;
            foreach ($accounts as $account) {
                $amount = $account->balance($storeId, $dateTo, $dateFrom);
                if ($isRevenue) {
                    $amount = $this->revenueEffect($account, $amount);
                }
                if (abs($amount) <= 0.004) {
                    continue;
                }
                $rows[] = ['account' => $account, 'amount' => $amount];
                $total += $amount;
            }

            return [$rows, $total];
        };

        [$revenueRows, $revenueTotal] = $buildRows($leafByType('revenue'), true);
        [$expenseRows, $expenseTotal] = $buildRows($leafByType('expense'));

        return [
            'revenueRows' => $revenueRows,
            'expenseRows' => $expenseRows,
            'revenueTotal' => $revenueTotal,
            'expenseTotal' => $expenseTotal,
            'netIncome' => $revenueTotal - $expenseTotal,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'storeLabel' => $this->storeLabel(),
        ];
    }

    /**
     * What a revenue account adds to income: credit minus debit. balance() is
     * debit minus credit for a debit-normal revenue account (4910 Rental Refunds,
     * 4900 Discount Given), which would add a refund to revenue instead of
     * taking it off.
     */
    private function revenueEffect(Account $account, float $balance): float
    {
        return $account->isDebitNormal() ? -$balance : $balance;
    }

    private function resolveStoreId(): ?int
    {
        return StoreContext::allStores() ? null : StoreContext::storeId();
    }

    private function storeLabel(): string
    {
        return StoreContext::allStores() ? 'All Stores' : 'Current Store';
    }

    /** Same manual is_tutorial/is_tutorial_practice/store filtering Account::grossActivity() uses — this is a raw join too. */
    private function applyLedgerScopes(\Illuminate\Database\Eloquent\Builder $query, ?int $storeId): void
    {
        $query->where('journal_entries.is_tutorial_practice', false);
        if (! AccountingTrialMode::enabled()) {
            $query->where('journal_entries.is_tutorial', false);
        }
        if ($storeId !== null) {
            $query->where('journal_entries.store_id', $storeId);
        }
    }

    private function renderPdf(string $view, array $data, string $filename): Response
    {
        if (class_exists(Pdf::class)) {
            $pdf = Pdf::loadView($view, $data);

            return response($pdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }
        if (class_exists(Dompdf::class)) {
            $html = view($view, $data)->render();
            $dompdf = new Dompdf;
            $dompdf->loadHtml($html);
            $dompdf->render();

            return response($dompdf->output(), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            ]);
        }

        abort(500, 'Dompdf is not installed.');
    }
}
