# Approval workflow

Risky changes to a rental are never applied by the person who asks for them. They are stored as a `RentalApprovalRequest` (JSON `payload` with a `kind`, status `pending`, `approved`, `rejected` or `cancelled`) and applied only when someone else decides. Practice requests use `TutorialRentalApprovalRequest` and the same code.

Sources: `RentalApprovalController`, `RentalApprovalRequest`, `RentalController`, `User`, `RentalFreeRequest`, `RentalLateDone`, `PaymentShiftAssignment`, and the `RentalApproval*`, `PaidRentAddItemApprovalTest`, `RentalFreeRequestTest`, `RentalLateDoneTest`, `RentalWrongInputDeleteTest` tests.

## Roles (`User::normalizedRole()`)

| Role | Rentals | Approvals |
|---|---|---|
| `admin` | Operates rentals; needs an open shift. Edits and deletes become requests. | Files requests and withdraws its own. Cannot approve or reject (route returns 403). |
| `supervisor` | Operates rentals directly, no shift needed. | Decides edit, item removal, free and late-done requests. Files cool-down and wrong-input delete requests. Cannot reopen or refund-cancel. |
| `management` | Everything a supervisor does, plus reopen returned or cancelled rentals and cancel with refund. | Decides all kinds, and is the only decider for a wrong-input delete (with `super_admin`). |
| `super_admin` | Same checks as management (`isManagement() \|\| isSuperAdmin()`), plus a few admin-only tools (for example bulk marking in a stock count). | Same as management. |
| `accounting` | Cannot operate rentals. | Not in the approval routes. May ask for a payment to be moved to a shift. |

Approve and reject routes are limited by `role:super_admin,management,supervisor`. Any requester can cancel their own pending request (`approvals.rentals.cancel`).

## What needs approval

| Kind | Filed when | Filed by | Decided by |
|---|---|---|---|
| `edit` | Any admin save that changes more than a payment or overdue action. Also the cool-down shortcut on an existing rental. | admin; admin or supervisor for the shortcut | supervisor, management, super_admin |
| `edit` (add item) | Adding an item to a rental whose payments already cover its bill (`add_item_settled`), for every role. Nothing is saved or collected until approved. | any rental operator | supervisor, management, super_admin |
| swap | Replacing an item on a settled rental is the same flagged `edit`, even when the new item is cheaper or the same price. | any rental operator | supervisor, management, super_admin |
| `item_removal` | A save by a non-admin that removes an item from a rental with a recorded payment. The item stays attached until the decision. (An admin removal is part of an `edit` request.) | supervisor, management, super_admin | supervisor, management, super_admin |
| `free` | Bill becomes zero. A reason of 3 to 500 characters is required; not for cancelled or deleted rentals. | any rental operator | supervisor, management, super_admin |
| `delete` | Admin cannot delete: the click files a request. A rental with money received can only be deleted as "wrong input" with a reason; management and super_admin then delete directly. | admin (plain), supervisor (wrong input) | plain: supervisor or above; wrong input: management or super_admin |
| `late_done` | A pickup or return that happened but was never clicked. Times must not be in the future; return not before pickup. | any rental operator | supervisor, management, super_admin |
| `create` | A new booking whose only clash is the return cool-down (`rental_return_buffer_days`). Managers book directly. | admin, supervisor | supervisor, management, super_admin |
| `payment_assign` | Move a shift-less payment into a shift. | management, super_admin, accounting | only the owner of that shift (own accept and reject routes) |

Staff can keep working while a request waits, but not on top of an uncertain total: while an `edit` is pending, pickup, return, cancel, delete and any new payment on that rental are refused, and a second edit request is refused.

## What happens on approval

- **edit**: applies items, dates, discount and customer data, keeps already booked item prices, and applies only the payment change relative to `base_amount_paid` so money recorded meanwhile is not wiped. Then `RentalPaymentRecorder` and `RentalLedgerSync::reconcileRental(refundOverpayment: true)` run. If the customer now overpaid, a refund entry is posted and the approver sees a reminder to hand the money back. Swapping to a cheaper item refunds the difference, a pricier one leaves an open balance for Pay, the same price changes nothing.
- **item_removal**: detaches the items and lowers the ledger contract. When that leaves the customer overpaid, the refund is booked as Dr 4910 / Cr cash or bank; the approver may pick an active cash or bank account, empty means the account the customer paid through. Blocked when the rental is cancelled.
- **free**: sets a 100% order discount with the note `FREE: <reason>`, refunds rent money held (not overdue fees) as a journal whose memo starts with `FREE`, adjusts the contract, recomputes closed shifts.
- **delete**: soft-deletes the rental and reverses every linked journal entry (`reverseForCancelledRental`).
- **late_done**: sets `picked_up_at`, and for a return sets status `returned`. A return on or before the end date removes an unpaid overdue fee. Then the ledger reconciles.
- **create**: creates the rental, attaches items at current prices, records the payment with the request's shift.

## The self-approval rule

A requester must never decide their own request, even if their role could. The message is "You submitted this request — it needs a decision from someone else." Where the code enforces it:

| Kind | Check |
|---|---|
| `edit` | `RentalApprovalController::approve` |
| `item_removal` | `approveItemRemoval` and `reject` |
| `free` | `RentalFreeRequest::approve` |
| `late_done` | `RentalLateDone::approve` |
| wrong-input `delete` | `approve` (also requires management or super_admin) |
| `payment_assign` | generic approve and reject refuse; only the shift owner decides |

Practice requests are exempt, because one person plays every role in a guided tour.

Not enforced: `approve` has no requester check for a `create` request, so a supervisor who filed a cool-down booking could approve it. `tests/Repro/ApprovalsC2ReproTest::test_BUG_generic_approve_has_no_self_approval_gate` documents this. `reject` only blocks self-rejection for `item_removal`; a requester can withdraw anything through cancel anyway.

## Other safeguards

- **Stale snapshot**: an `edit` is a full snapshot. If another edit or removal was decided after it was filed (payment-only saves excepted), approval is refused as out of date and the request must be rejected and refiled.
- **Re-checks at decision time**: item availability, discount notes, full-payment floor, rental still active. A plain delete request is refused if money arrived meanwhile.
- **Concurrency**: the edit path locks the request row and aborts with 409 if it was already decided; a non-pending request is refused.
- **Store check**: deciding a request of an inaccessible store returns 403.
- **Audit trail**: each step writes a `RentalActivityLog` entry (`edit_requested`, `edit_approved`, `edit_rejected`, `edit_cancelled`, `item_removal_*`, `free_*`, `late_done_*`, `delete_*`, `payment_assign_*`).
