# Feature catalogue

Rental Suite is two systems in one: a **rental operations app** (booking, payments, shifts, approvals, invoices) and an **ERP-style back office** (inventory and stock count, double-entry accounting, fixed assets, reports, multi-store administration). Both share one database, one set of roles and one money path, so the books always follow what happens at the counter.

Everything below exists in the code (routes, controllers, views) and is covered by the test suite where it moves money.

## 1. Rental operations

| Feature | What it does |
|---|---|
| Rent register | Create a rental with dates, items, deposit or full payment; search; pick-up and return with status rules (cannot return unpaid or un-picked rentals) |
| Pricing | 3-day blocks, extra-day charges, tax-inclusive prices, per-item discounts with required notes, late (overdue) fees |
| Bulk import | Paste a table of rentals and import them in one step |
| Offline cache | The rentals page keeps an offline copy of the list and shows a sync indicator |
| Activity log | Every step of a rental (create, edit, pay, pick up, return, delete) is recorded with who and when; a log page and a side panel show it |
| Deleted rents | Soft-deleted rentals stay listed and can be bulk-purged by an allowed role |
| Invoices | Receipt image and PDF per rental, signed public links, send by WhatsApp (simulated in the demo), template preview, invoice-number backfill |
| Overdue reminders | A console command sends payment reminders for overdue rentals |
| Discount review | Managers review each day's discounted rentals and mark them checked; staff see the status of their own |
| Emergency page | A read-only list of recent rentals and items that works as a fallback view |

## 2. Shifts and cash handling

| Feature | What it does |
|---|---|
| Open / close / reopen shift | Each cashier works inside a shift; payments are attributed to the open shift |
| Shift report | Closing totals, per-date view, report page and PDF |
| Payment re-assignment | A payment recorded outside a shift can be sent to a shift; the receiving cashier accepts or rejects it |
| Corrections | Negative payment rows are shown as corrections in the shift card, the report and the PDF |

## 3. Approvals and oversight

| Feature | What it does |
|---|---|
| Edit requests | Changing a paid rental needs approval from a different, authorised person |
| Item removal | Removing an item from a paid rental is requested and approved separately; any refund is booked on approval |
| Cool-down approval | Adding an item that is still in its cool-down window goes through an approval request |
| Free request | Marking a rental free needs a reason and an approver |
| Late pick-up / return | Staff report a pick-up or return that happened but was never clicked; a supervisor approves the recorded time |
| Wrong-input delete | Deleting a rental typed in by mistake is a request with a reason |
| My requests | Each user sees the status of their own requests and can cancel pending ones |
| Manager report | Period report for managers, with PDF download |
| Investigations | An analysis page over rentals and discounts for managers |
| Anomaly list | "Rentals to review" flags rentals whose numbers look different from the notes, and lets reviewers mark them checked |

## 4. Inventory and stock count

| Feature | What it does |
|---|---|
| Item catalogue | Items with code, price, stock and availability by date range; quick add |
| Bulk tools | Bulk import, bulk edit, bulk price, bulk new codes, bulk discount, bulk delete, apply tax prices |
| Data-quality panels | Duplicate codes, format outliers and skipped number sequences are surfaced for cleanup |
| QR codes | Per-item QR token, QR download and ZIP export, a scan page and a public status page that hides customer details |
| Stock opname (stock count) | Start a counting session per store; mark each item Present, Missing, Damaged or Not Checked, with photos; finish with a PDF report and per-item history |
| Scan with PIN | A cashier can mark items from a phone by scanning a QR and entering a personal PIN, without typing the account password |

## 5. Accounting (ERP back office)

| Feature | What it does |
|---|---|
| Chart of accounts | Create, edit and deactivate accounts with type and normal balance |
| Automatic journals | Payments, refunds, cancellations, forfeited deposits and late fees post balanced entries |
| Manual journals | Hand-entered entries with correction (reversal) support |
| Expenses | Record expenses per store and category; they post to the ledger |
| Cash in / out | Non-rental cash movements with their own entries |
| Fixed assets | Register assets and depreciate them; reports show cost, depreciation and book value |
| Reports | Profit and Loss, Balance Sheet, Trial Balance, General Ledger, Receivables, Fixed Assets, plus a read-only VAT estimate; PDF export |
| Accounting dashboard | Cash collected, recognised revenue, order summary and outstanding receivables for a date range |
| Ledger repair tools | Settings actions that re-post missing journal entries and backfill invoice numbers |
| Trial mode | Accounting can run in a trial mode whose entries are excluded from real reports and can be cleared |

## 6. Administration and platform

| Feature | What it does |
|---|---|
| Multi-store | Switch store; each store has its own rentals, items, shifts, settings and ledger scope |
| Users and roles | `admin`, `supervisor`, `management`, `super_admin`, `accounting`; assign users to stores; reset a user's stock-count PIN |
| Settings | Price visibility, messaging token, trial-data cleanup |
| Help centre | Guided tours for each module, release notes, accounting glossary, and a VAT questionnaire to fill in with an accountant |
| Practice mode | Every tour runs on separate `tutorial_*` tables so staff can practise without touching real data |
| Security | Server-side role checks, signed URLs for public documents, CSP report endpoint, token-protected backup endpoint, super-admin-only database explorer |
| Backups | A console command dumps the database; a token-protected endpoint lets a scheduler call it |
| Debug aids | A bug-trap log sender and a debug clock for local testing only |

## Not included yet

- HR and payroll: there are users and roles, but no employee records, attendance, leave or payroll calculation. Salaries are recorded as an expense by hand.

## Screenshots

All screenshots use the fictional demo data (`DemoPortfolioSeeder`).

### Counter

**Rents page** — Approval queue, open shifts and the new-rent form on one page; the list below filters and searches.

![Rents page](screenshots/features/rentals.png)

**Shift closing report** — Totals, discounts and a per-cashier summary for one shift, with PDF download.

![Shift closing report](screenshots/features/shift-report.png)

### Control

**Approval queue** — Requests wait for a different, authorised person to approve or reject.

![Approval queue](screenshots/features/approvals.png)

**Activity log** — Every change, approval decision and shift event, with who did it and when.

![Activity log](screenshots/features/activity-log.png)

### Inventory

**Item inventory** — Add, search and bulk-edit items; status, price, stock and QR export at a glance.

![Item inventory](screenshots/features/outfits.png)

**Public QR status page** — Scanning an item's QR shows availability and stock, with no customer details.

![Public QR status page](screenshots/features/qr-public-page.png)

### Stock and oversight

**Stock count session** — Mark each item Present, Missing or Damaged; unchecked items stay in view until the end.

![Stock count session](screenshots/features/stock-opname-session.png)

**Manager report** — Transactions, gross and net revenue, discounts and overdue fees for a chosen period.

![Manager report](screenshots/features/manager-report.png)

### Accounting

**Expenses** — Record an expense from an account and a payment source; it posts to the ledger at once.

![Expenses](screenshots/features/expenses.png)

**General ledger** — Every journal line by account and date, each with its journal number.

![General ledger](screenshots/features/general-ledger.png)

**Fixed assets** — Purchase price, total depreciation and current book value per asset account.

![Fixed assets](screenshots/features/fixed-assets.png)

**Chart of accounts** — Accounts with type, normal balance and a cash/bank flag; add or edit in place.

![Chart of accounts](screenshots/features/accounts.png)

### Administration

**Stores and staff** — Create stores, set invoice details and choose which users can work in each store.

![Stores and staff](screenshots/features/stores.png)

**Help and guided tours** — Practice guides, release notes and a readiness test, all on separate practice data.

![Help and guided tours](screenshots/features/help.png)

### Reports

**Profit and Loss** — Revenue and expenses for a date range, from the same journal lines as every other report.

![Profit and Loss](screenshots/05-profit-loss.png)

**Balance Sheet** — Assets, liabilities and equity as of a date.

![Balance Sheet](screenshots/06-balance-sheet.png)

**Trial Balance** — Debit and credit totals per account; the two sides must match.

![Trial Balance](screenshots/07-trial-balance.png)
