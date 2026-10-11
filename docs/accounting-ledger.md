# Accounting ledger

Every money movement of a rental becomes a balanced journal entry. This page describes the mechanism: the chart of accounts, the journals posted for each event, and the reports built on them. Sources: `database/seeders/ChartOfAccountsSeeder.php`, `JournalPostingService`, `RentalRevenueRecorder`, `RentalLedgerSync`, `AccountingReportController`.

## Posting rules (`JournalPostingService::post`)

The single write path for all entries. An entry is rejected unless it has at least two lines, each line has exactly one of debit or credit above zero, debits equal credits (tolerance 0.004), and every account exists, is active and is a **leaf** (the five top-level rows are headers and cannot be posted to). Header and lines are saved in one transaction. Posted entries are never edited: a correction is a new entry with debits and credits swapped (`reverse`, `is_reversal = true`, `reverses_journal_entry_id`). Each entry has a `store_id`, a `reference_type` and `reference_id` (for example `rental_payment`, `rental_adjustment`, `rental_refund`, `rental_cancellation`, `item_removal_refund`, `expense`, `cash_movement`, `fixed_asset_purchase`, `manual_journal_entry`) and the practice flags described in `architecture.md`.

## Chart of accounts

The seeder is idempotent (keyed by `code`); accounts can be added or renamed afterwards. Rental payments use fixed codes (1100/1200, 1300, 2300, 4100, 4200, 4400, 4910), so those must exist and stay active.

| Code | Name | Normal side |
|---|---|---|
| 1000 | ASSETS (header) | debit |
| 1100 | Store Cash (cash equivalent) | debit |
| 1200 | Bank (cash equivalent) | debit |
| 1300 | Rental Receivable | debit |
| 1600 | Equipment (Capital Asset) | debit |
| 1610 | Accumulated Depreciation | credit |
| 1650 | Supplies (Capital Asset) | debit |
| 2000 | LIABILITIES (header) | credit |
| 2100 | Accounts Payable | credit |
| 2300 | Customer Deposits | credit |
| 2400 | Output VAT Payable | credit |
| 3000 | EQUITY (header) | credit |
| 3100 / 3200 | Owner's Capital / Owner's Drawings | credit / debit |
| 3250 | Opening Balance Adjustment | debit |
| 3300 | Retained Earnings | credit |
| 4000 | REVENUE (header) | credit |
| 4100 | Rental Revenue | credit |
| 4200 | Late Fee Revenue | credit |
| 4300 | Other Income | credit |
| 4400 | Cancellation Revenue (Forfeited Deposits) | credit |
| 4900 | Discount Given (contra-revenue) | debit |
| 4910 | Rental Refunds (Removed Item Refund) (contra-revenue) | debit |
| 5000 | EXPENSES (header) | debit |
| 5100-5900 | 5100 Outfit Maintenance & Repair, 5150 Depreciation Expense, 5200 Salaries & Wages, 5300 Store Rent Expense, 5400 Utilities, 5500 Marketing & Advertising, 5600 Office Supplies, 5700 Transport & Delivery, 5800 Bank & Admin Fees, 5900 Miscellaneous Expense | debit |

Rental code only posts to 1100/1200, 1300, 2300, 4100, 4200, 4400 and 4910. Nothing in the code posts to 2400: revenue is booked at the tax-inclusive amount, and the VAT report is a read-only estimate. Other modules use 5150 and 1610 (depreciation), 1600/1650 (fixed-asset purchases, chosen in the asset form) and 3250 (opening balances in cash in/out).

## Journals for rental events

`T` is the bill without the overdue fee (tax-inclusive), `d` the amount received, `C` the cash or bank account of the payment method. `C` is 1100 for method `cash` and 1200 otherwise, unless the store setting `rental_revenue_cash_account_id` or `rental_revenue_noncash_account_id` points elsewhere. Entry dates follow the payment date; cancellation entries use today.

| Event | Journal |
|---|---|
| **Deposit** (first `down_payment`) | Dr C `d`; Dr 1300 `T - d` (if above zero); Cr 2300 `T`. The deposit account carries the whole contract, the unpaid rest is a receivable from day one. |
| Further deposit | Dr C `d`; Cr 1300 `d`. |
| **Full payment**, no earlier deposit | Dr C `d`; Cr 4100 `d`. |
| **Final or full payment** after a deposit (revenue recognised) | Dr C `d`; Dr 2300 `T`; Cr 1300 `d`; Cr 4100 `T`. Clears deposit and receivable, books the contract as revenue. Any later payment is Dr C / Cr 1300. |
| **Late fee**, when collected (`overdue` payment) | Dr C; Cr 4200. Charging the fee posts nothing; the ledger is cash-based for fees. |
| **Refund**: overpayment after an edit, a free rental, a removed item | Dr 4910; Cr cash or bank. An overpayment or free refund is split over the accounts the customer paid through; a removed-item refund uses one account (picked by the approver, default follows the payment method). |
| **Cancel with refund** (management only), deposit still held | Dr 2300 (deposit); Cr 1300 (receivable written off, up to the deposit); Cr cash or bank (the rest). |
| Cancel with refund, revenue already recognised | Dr 4910; Cr cash or bank. |
| **Cancel, deposit forfeited** (default) | Dr 2300 `N`; Cr 1300 `w`; Cr 4400 `N - w`, where `N` is the net deposit of the rental and `w = min(receivable, N)`. No entry when nothing is deferred. |
| **Edit that changes the bill** | `RentalLedgerSync` posts a `rental_adjustment` moving 1300, 2300 or 4100 to the new contract. |
| **Delete** a rental | Reversal of every linked entry (payments, adjustments, refunds, cancellation); already reversed entries are skipped. |
| **Reopen** a cancelled rental | Reversal of its cancellation entry only; the ledger is then reconciled to the current bill. |
| Negative payment (a correction) | Plain two-line reversal against 2300, 4100 or 4200, depending on the kind. |

Expenses are Dr expense account / Cr cash or bank. Cash in/out is Dr cash / Cr the counter account (in) and the reverse (out). Depreciation is Dr 5150 / Cr 1610.

Posting is deliberately not a hard dependency of the rental flow: if an account is missing or inactive, the payment still succeeds, the problem is logged and `journal_entry_id` stays empty. `RentalLedgerSync` finds such gaps and drift (`evaluate` compares what the ledger holds for a rental with its current bill) and repairs the unambiguous cases; the others are listed for a person.

## Reports

Routes `accounting.reports.*`, roles `super_admin`, `management` and `accounting`. All scope to the current store or all stores, leave out practice entries, and leave out trial-mode entries once Accounting Trial Mode is off.

| Report | What it shows |
|---|---|
| Trial balance (PDF too) | As-of date. Each active leaf account with a balance, placed in the debit or credit column by its normal side; the two totals must match. |
| Balance sheet (PDF too) | As-of date. Assets, liabilities and equity leaves, plus a computed line "Current Year Profit (Loss)" (cumulative revenue minus expenses), because there is no period closing yet. |
| Profit and loss (PDF too) | Date range. Revenue minus expenses; 4900 and 4910 reduce revenue instead of adding to it. |
| General ledger | Journal lines, newest first, 25 per page. Filter by date range and account; free-text search over memos (rental number, customer) ignores the date range and the store filter. Each line opens its entry. |
| VAT preview (`ppn`) | Estimate from 4100 activity and the store tax settings. Posts nothing. |
| Receivables (`piutang`) | Open 1300 balance per rental. |

Balances come from `Account::grossActivity` (sum of debits and credits of non-practice lines up to the date); sign follows the account's normal side.
