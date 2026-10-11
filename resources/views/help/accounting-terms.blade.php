<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Accounting Terms
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">

            <a href="{{ route('help.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to Help</a>

            <p class="text-sm text-gray-500 leading-relaxed mt-4 max-w-2xl">
                A reference of the terms used in this system's accounting module, and how each one is applied
                technically. Worth reading before the accounting team/accountant starts reconciling, so it is clear
                what is recorded automatically, what is still manual, and what is not available at all.
            </p>

            <div class="mt-10">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Quick Summary</h3>
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5">
                    <p class="text-sm text-gray-600 leading-relaxed">
                        This module applies double-entry bookkeeping to operating expenses
                        (<strong>Expenses</strong>), cash movements (<strong>Cash In/Out</strong>), and
                        <strong>rental revenue</strong>. Every rental payment automatically posts a journal to the
                        ledger as soon as the money is received. From there, four standard financial reports are
                        available: <strong>Trial Balance</strong>, <strong>Balance Sheet</strong>, <strong>Profit &amp;
                        Loss</strong>, and <strong>General Ledger</strong>. See the terms table and the "What You Can
                        Access" section below. The system also gives read access to the <strong>Shift
                        Report</strong> and rental transaction data.
                    </p>
                </div>
            </div>

            <div class="mt-10">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Basic Terms &amp; Their Equivalents in the System</h3>
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Term</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">In the System</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">How It Works</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Chart of Accounts</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">Chart of Accounts</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">A list of accounts with 4-digit codes, e.g. <code class="bg-gray-100 rounded px-1">1100</code> Store Cash, <code class="bg-gray-100 rounded px-1">4100</code> Rental Revenue. Each account has a "normal balance": Assets &amp; Expenses increase on the debit side; Liabilities, Equity &amp; Revenue increase on the credit side. Full management: Management/Super Admin. Accounting can only view.</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Journal</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">Journal Entry</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">One transaction = one journal with ≥2 lines that always balance (total debit = total credit). Created automatically by the system when an Expense, Cash In/Out, or rental payment is saved; there is no manual line-by-line journal input.</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Ledger</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">(no separate page)</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">The collection of every journal ever created. A strictly held principle: old entries are <strong>never deleted or edited directly</strong>, whatever the reason.</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Entry Correction</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">the "Edit" button in Expenses / Cash In-Out</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">Not a direct edit. The system creates a reversing journal (same amount, debit and credit swapped, so the two cancel out), then posts a new journal with the correct amount. The old history stays intact and traceable. Only Super Admin/Management/Accounting can do this.</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Expense</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">Expenses</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">Operating spending (parking, electricity, etc.). Manual input → automatically creates a journal: debit to the related expense account, credit to the cash/bank account used to pay.</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Cash Movement</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">Cash In/Out</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">Money moving between two cash/bank accounts (deposit to the bank, cash withdrawal, etc.). Manual input → automatically creates a journal between those two accounts.</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Rental Revenue</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">Rental Revenue (Dashboard)</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">Every rental payment (deposit, settlement, late fee) automatically posts a journal as soon as the money is received (cash basis, already net of discounts). Reversed automatically if the rental is cancelled. Calculated from payments actually received, not from the total order value.</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Shift Session</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">Shift Session</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">A cashier work session per period, opened &amp; closed manually. It has its own reconciliation report (number of transactions &amp; revenue per shift) and can be looked up through "Shift Lookup" on the Accounting Dashboard.</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Trial Mode</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">Trial / Practice Mode</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">A switch for practising input without polluting the real data. Entries made while this mode is on are specially flagged and automatically disappear from reports &amp; balances once the mode is turned off (not deleted, only hidden).</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Trial Balance</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">Trial Balance</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">A list of all accounts with their debit/credit balances as of a single date (cumulative from the start, not a range). The Debit column total must always equal the Credit column total: that is the proof the books balance.</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Balance Sheet</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">Balance Sheet</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">Financial position as of a single date: Assets compared with Liabilities + Equity. Because the system has no period-closing mechanism yet, Equity includes a "Current Year Profit (Loss) (calculated automatically)" line = Revenue &minus; Expenses since the start, so the report still balances mathematically.</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Profit &amp; Loss</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">Profit &amp; Loss</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">Revenue minus Expenses for a date <em>range</em> (not cumulative from the start, unlike the Trial Balance/Balance Sheet). Used to measure profit/loss per period, e.g. per month.</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap align-top">Ledger (report)</td>
                                    <td class="px-4 py-3 text-sm text-indigo-700 whitespace-nowrap align-top">General Ledger</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 align-top">A page for going through journals one by one, filterable by account/date; each row can be clicked to see both sides of the journal plus its correction/reversal history.</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <div class="mt-10">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Important: Limitations to Be Aware Of</h3>
                <div class="rounded-lg border border-amber-300 bg-amber-50 p-5">
                    <p class="text-xs font-bold uppercase tracking-wide text-amber-700 mb-2">Still Limited</p>
                    <ul class="text-sm text-amber-900 leading-relaxed list-disc list-inside space-y-2">
                        <li><strong>Rental Revenue on the Dashboard vs. the figures in Reports can differ.</strong> Rental Revenue counts every rental payment received, while Trial Balance/Balance Sheet/P&amp;L/General Ledger only show what has already been recorded in the ledger. If a transaction failed to post (e.g. a momentary account configuration problem), the two figures can look different for the same date range.</li>
                        <li>There is no period-closing mechanism yet. Entries in old periods can still be corrected at any time, and the Balance Sheet covers this with an automatically calculated current profit/loss line (see the "Balance Sheet" row in the table above).</li>
                        <li>There is no Receivables/Payables (AR/AP) or VAT recording yet: the accounts <code class="bg-amber-100 rounded px-1">1300 Rental Receivable</code>, <code class="bg-amber-100 rounded px-1">2100 Accounts Payable</code>, and <code class="bg-amber-100 rounded px-1">2400 Output VAT</code> are set up in the Chart of Accounts but nothing posts to them yet.</li>
                        <li>Rental revenue is recorded as the net money received (net of discount). There is no split of "Gross Revenue" vs "Discounts Given" as two separate journal lines, although the account <code class="bg-amber-100 rounded px-1">4900 Discounts Given</code> is already set up in the Chart of Accounts for the next phase.</li>
                        <li>A refund caused by removing an item from a rental (item removal) is still a display value only. It is not yet recorded as a money movement in the ledger or in the rental payment history.</li>
                    </ul>
                </div>
            </div>

            <div class="mt-10">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">What You Can Access</h3>
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 divide-y divide-gray-100">
                    <div class="p-5">
                        <p class="font-semibold text-gray-900">Dashboard</p>
                        <p class="text-sm text-gray-600 mt-1 leading-relaxed">A summary of Cash Collected from Rentals, Recognised Rental Revenue, Cash In/Out, and Expenses per date range, plus a shift search by date.</p>
                    </div>
                    <div class="p-5">
                        <p class="font-semibold text-gray-900">Chart of Accounts</p>
                        <p class="text-sm text-gray-600 mt-1 leading-relaxed">View the balance of each account. Full management (add/edit accounts): Management/Super Admin only. Need a new account that is not on the list? Ask a Super Admin, Management, or the developer to add it; do not work around it with an existing account, so reports stay accurate.</p>
                    </div>
                    <div class="p-5">
                        <p class="font-semibold text-gray-900">Expenses &amp; Cash In/Out</p>
                        <p class="text-sm text-gray-600 mt-1 leading-relaxed">Full history, including the correction (reversal) feature for authorised roles.</p>
                    </div>
                    <div class="p-5">
                        <p class="font-semibold text-gray-900">Shift Report</p>
                        <p class="text-sm text-gray-600 mt-1 leading-relaxed">Reconciliation of transactions &amp; revenue per shift. Accounting can view it but cannot reopen a shift that has been closed.</p>
                    </div>
                    <div class="p-5">
                        <p class="font-semibold text-gray-900">Rents</p>
                        <p class="text-sm text-gray-600 mt-1 leading-relaxed">List &amp; full details of rental transactions (read-only for Accounting): payment method, deposit vs paid in full, discount, per transaction.</p>
                    </div>
                    <div class="p-5">
                        <p class="font-semibold text-gray-900">Trial Balance, Balance Sheet, Profit &amp; Loss</p>
                        <p class="text-sm text-gray-600 mt-1 leading-relaxed">Three standard financial reports, each downloadable as a PDF, ready to hand straight to an accountant.</p>
                    </div>
                    <div class="p-5">
                        <p class="font-semibold text-gray-900">General Ledger</p>
                        <p class="text-sm text-gray-600 mt-1 leading-relaxed">Go through journals one by one, filtered by account/date, with a detail page per journal (including correction/reversal history).</p>
                    </div>
                </div>
            </div>

            <div class="mt-10 mb-4">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Access Rights per Role</h3>
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200">
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Enter Expenses / Cash</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Entry Correction</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Manage Chart of Accounts</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">View Shift Report</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">View Financial Reports</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap">Super Admin</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes (+ reopen)</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap">Management</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes (+ reopen)</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap">Accounting</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                    <td class="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">View only</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes (view only)</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                </tr>
                                <tr>
                                    <td class="px-4 py-3 text-sm font-semibold text-gray-900 whitespace-nowrap">Admin</td>
                                    <td class="px-4 py-3 text-sm text-emerald-700 font-medium whitespace-nowrap">Yes</td>
                                    <td class="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">No</td>
                                    <td class="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">No</td>
                                    <td class="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">Own shifts only</td>
                                    <td class="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">No</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <p class="text-xs text-gray-400 mt-2">
                    Accounting is deliberately given the same cross-store reach as Management, because the
                    bookkeeping function is centralised, not run per store.
                </p>
            </div>

        </div>
    </div>
</x-app-layout>
