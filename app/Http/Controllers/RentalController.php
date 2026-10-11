<?php

namespace App\Http\Controllers;

use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\ShiftSession;
use App\Models\TutorialShiftSession;
use App\Models\Outfit;
use App\Models\TutorialRental;
use App\Models\TutorialRentalActivityLog;
use App\Models\TutorialRentalApprovalRequest;
use App\Models\User;
use App\Support\RentDeleteRestriction;
use App\Models\RentalPaymentRecord;
use App\Support\RentalPaymentRecorder;
use App\Support\MoneyInput;
use App\Support\OverdueDuration;
use App\Support\RentalFinancialSummary;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class RentalController extends Controller
{
    /**
     * A raw `exists:outfits,id` ignores the BelongsToStore scope, so an outfit id of another store passed
     * validation (overnight review P6). Mirror the scope: only outfits of the current store, unless the
     * user is deliberately in all-stores mode.
     */
    private function outfitExistsRule(): \Illuminate\Validation\Rules\Exists
    {
        $rule = Rule::exists('outfits', 'id');
        $storeId = StoreContext::storeId();
        if ($storeId !== null && ! StoreContext::allStores()) {
            $rule->where('store_id', $storeId);
        }

        return $rule;
    }

    /** See RentalApprovalController::activityLogClassFor() — same reasoning. */
    private function activityLogClassFor(string $rentalClass): string
    {
        return $rentalClass === TutorialRental::class ? TutorialRentalActivityLog::class : RentalActivityLog::class;
    }

    /**
     * The approval-request class matching a rent: a practice rent shares its id number with
     * a real rent, so its requests must live in the practice table or approving one would
     * act on the real rent with the same number.
     */
    private function approvalClassFor(Rental $rental): string
    {
        return $rental instanceof TutorialRental ? TutorialRentalApprovalRequest::class : RentalApprovalRequest::class;
    }

    private function pricingTaxPercent(): float
    {
        return max(0, (float) StoreCache::get('pricing_tax_percent', 0));
    }

    private function debugNow(Request $request): Carbon
    {
        if (! (bool) config('app.debug')) {
            return now();
        }

        $raw = (string) $request->query('debug_today', '');
        if ($raw === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return now();
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $raw)->startOfDay();
        } catch (\Throwable) {
            return now();
        }
    }

    /**
     * True when a price lookup must also see the dedicated practice (tour) outfits: the rent being changed is a
     * practice rent, or nothing is bound yet and the create form sent the practice flag (which only ever
     * routes the new rent into the separate practice table).
     */
    private function isPracticeContext(Request $request, ?Rental $existingRental): bool
    {
        return $existingRental instanceof TutorialRental
            || ($existingRental === null && $request->boolean('tutorial_practice'));
    }

    /**
     * When payment is "full", amount paid must cover net total (after line + order discounts and tax).
     *
     * @param  array<string, mixed>  $validated  must include outfit_ids, payment_kind, amount_paid, discount, discount_type
     */
    private function rejectIfFullPaymentBelowTotalDue(
        Request $request,
        array $validated,
        bool $wantsJson,
        bool $pricingEnabled,
        ?Rental $existingRental = null,
    ): RedirectResponse|JsonResponse|null {
        if (! $pricingEnabled || ! in_array($validated['payment_kind'] ?? null, ['full', 'final_payment'], true)) {
            return null;
        }

        $outfitIds = array_values(array_unique(array_filter(
            array_map('intval', $validated['outfit_ids'] ?? []),
            fn (int $id) => $id > 0
        )));
        if ($outfitIds === []) {
            return null;
        }

        $itemDiscounts = $request->input('item_discounts', []);
        $itemDiscountTypes = $request->input('item_discount_types', []);
        if (! is_array($itemDiscounts)) {
            $itemDiscounts = [];
        }
        if (! is_array($itemDiscountTypes)) {
            $itemDiscountTypes = [];
        }

        $preview = RentalFinancialSummary::previewRentalForTotals(
            $outfitIds,
            $itemDiscounts,
            $itemDiscountTypes,
            $validated['discount'] ?? null,
            $validated['discount_type'] ?? 'fixed',
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            null,
            $existingRental ? RentalFinancialSummary::lockedUnitPrices($existingRental) : null,
            $this->isPracticeContext($request, $existingRental),
        );
        $preview->extra_charge = max(0.0, (float) ($validated['extra_charge'] ?? 0));
        $due = (float) RentalFinancialSummary::summarize($preview, $this->pricingTaxPercent())['net_incl_tax'];
        $paid = (float) ($validated['amount_paid'] ?? 0);
        if (round($paid, 2) < round($due, 2)) {
            $msg = 'When payment is Full, the amount paid must be at least the total due (Rp '
                .number_format($due, 0, ',', '.')
                .', including tax and after discounts).';
            if ($existingRental && (float) ($existingRental->amount_paid ?? 0) > 0.004) {
                // A rent that already has payments and now owes more (e.g. a longer date range): the extra is collected with Pay.
                $msg .= ' To collect the extra amount on this rent, use the Pay button.';
            }

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['amount_paid' => [$msg]]], 422)
                : back()->withInput()->withErrors(['amount_paid' => $msg]);
        }

        return null;
    }

    /**
     * The business's rule: staff type in exactly what was received, never more — any
     * excess is change handed back by hand with a calculator, it never becomes a
     * "customer overpaid" entry that has to be refunded from the books later. Applies
     * to any kind (down payment, final payment, full), never just the "full" floor
     * check above.
     */
    private function rejectIfPaymentExceedsTotalDue(
        Request $request,
        array $validated,
        bool $wantsJson,
        bool $pricingEnabled,
        float $currentlyPaid = 0.0,
        ?Rental $existingRental = null,
    ): RedirectResponse|JsonResponse|null {
        if (! $pricingEnabled || ! in_array($validated['payment_kind'] ?? null, ['full', 'down_payment', 'final_payment'], true)) {
            return null;
        }
        $paid = (float) ($validated['amount_paid'] ?? 0);
        if ($paid <= 0.004) {
            return null;
        }
        // Only a genuine NEW payment (e.g. an exact payment at the counter) is checked against the
        // ceiling. An edit that just carries the rental's already-recorded amount_paid
        // forward unchanged (e.g. a discount request, still pending) can legitimately
        // end up above the NEW total — that becomes a refund once approved, not a typo
        // to block here.
        if (round($paid, 2) <= round($currentlyPaid, 2) + 0.004) {
            return null;
        }

        $outfitIds = array_values(array_unique(array_filter(
            array_map('intval', $validated['outfit_ids'] ?? []),
            fn (int $id) => $id > 0
        )));
        if ($outfitIds === []) {
            return null;
        }

        $itemDiscounts = $request->input('item_discounts', []);
        $itemDiscountTypes = $request->input('item_discount_types', []);
        if (! is_array($itemDiscounts)) {
            $itemDiscounts = [];
        }
        if (! is_array($itemDiscountTypes)) {
            $itemDiscountTypes = [];
        }

        $preview = RentalFinancialSummary::previewRentalForTotals(
            $outfitIds,
            $itemDiscounts,
            $itemDiscountTypes,
            $validated['discount'] ?? null,
            $validated['discount_type'] ?? 'fixed',
            $validated['start_date'] ?? null,
            $validated['end_date'] ?? null,
            null,
            $existingRental ? RentalFinancialSummary::lockedUnitPrices($existingRental) : null,
            $this->isPracticeContext($request, $existingRental),
        );
        $preview->extra_charge = max(0.0, (float) ($validated['extra_charge'] ?? 0));
        $due = (float) RentalFinancialSummary::summarize($preview, $this->pricingTaxPercent())['net_incl_tax'];
        if (round($paid, 2) > round($due, 2) + 0.004) {
            $msg = 'The amount paid (Rp '.number_format($paid, 0, ',', '.').') is more than the total due (Rp '
                .number_format($due, 0, ',', '.')
                .'). Hand back the difference as change and type in the exact amount received.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['amount_paid' => [$msg]]], 422)
                : back()->withInput()->withErrors(['amount_paid' => $msg]);
        }

        return null;
    }

    /**
     * Whether a real ('edit' kind, still pending) approval request sits on this
     * rental. Items, discount, dates — anything about it can still change, so
     * pickup, return, cancel and delete are all blocked while one is waiting:
     * none of them should act against a total that might not hold.
     */
    public static function paidRentalDeleteBlockMessage(Rental $rental): ?string
    {
        $received = $rental->moneyReceived();
        if ($received <= 0.004) {
            return null;
        }

        return 'This rent already has Rp '.number_format($received, 0, ',', '.').' paid, so it cannot be deleted — that money would disappear from the books. Cancel it instead (keep the deposit or refund it).';
    }

    /** Whether this save changes what the rent costs (items, dates, discounts, extra charge). */
    private function editChangesBill(Request $request, Rental $rental): bool
    {
        $rental->loadMissing('outfits');
        $ids = fn ($list) => collect($list)->map(fn ($v) => (int) $v)->filter()->unique()->sort()->values()->all();
        if ($request->has('outfit_ids') && $ids($request->input('outfit_ids', [])) !== $ids($rental->outfits->pluck('id'))) {
            return true;
        }
        foreach (['start_date', 'end_date'] as $f) {
            if ($request->filled($f) && substr((string) $request->input($f), 0, 10) !== $rental->{$f}?->toDateString()) {
                return true;
            }
        }
        $money = fn ($v) => round((float) ($v ?? 0), 2);
        if ($request->has('discount') && $money($request->input('discount')) !== $money($rental->discount)) {
            return true;
        }
        if ($request->has('extra_charge') && $money($request->input('extra_charge')) !== $money($rental->extra_charge)) {
            return true;
        }
        // 10% and Rp 10 are different bills: a type change counts when a discount is involved on either side.
        $type = fn ($v) => $v === 'percent' ? 'percent' : 'fixed';
        if ($request->has('discount_type') && $type($request->input('discount_type')) !== $type($rental->discount_type)
            && ($money($request->input('discount')) > 0 || $money($rental->discount) > 0)) {
            return true;
        }
        $itemDiscounts = $request->input('item_discounts');
        $itemTypes = $request->input('item_discount_types');
        foreach ($rental->outfits as $outfit) {
            $oldDiscount = $money($outfit->pivot->discount);
            $newDiscount = is_array($itemDiscounts) && array_key_exists($outfit->id, $itemDiscounts) ? $money($itemDiscounts[$outfit->id]) : $oldDiscount;
            if ($newDiscount !== $oldDiscount) {
                return true;
            }
            if (is_array($itemTypes) && array_key_exists($outfit->id, $itemTypes) && $oldDiscount > 0
                && $type($itemTypes[$outfit->id]) !== $type($outfit->pivot->discount_type)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Decision D11 (all roles): adding an item to a rent whose payments already cover its bill is
     * never saved directly and never takes money up front. It becomes an edit request; once approved
     * the extra amount is an open bill that is collected with the Pay button.
     *
     * @param  array<int|string>  $submittedOutfitIds
     */
    private function addsItemToSettledRent(Rental $rental, array $submittedOutfitIds): bool
    {
        if ((float) ($rental->amount_paid ?? 0) <= 0.004) {
            return false;
        }

        $current = $rental->outfits()->pluck('outfits.id')->map(fn ($id) => (int) $id)->all();
        if (array_diff(array_map('intval', $submittedOutfitIds), $current) === []) {
            return false;
        }

        // The bill as booked today (own pivot prices). `net_incl_tax` already contains the overdue fine, so the
        // fine must not be added again here (D11-R3).
        $bill = (float) RentalFinancialSummary::summarize($rental->fresh('outfits'), $this->pricingTaxPercent())['net_incl_tax'];

        return $this->rentalEditPaymentFieldsFrozen($rental, $bill);
    }

    private function addItemToSettledRentMessage(): string
    {
        return 'Adding an item to a paid rent needs approval first. Submit the change, then collect the remaining amount with Pay once it is approved.';
    }

    /**
     * Popup text after such a change was sent for approval. $balanceAfter = new bill minus what was paid:
     * a swap to a cheaper item ends below the paid amount, so there is a refund and nothing to collect with Pay.
     */
    private function addItemToSettledRentSentMessage(?float $balanceAfter): string
    {
        $sent = 'The change was sent for approval. Nothing has been saved yet; ';

        return match (true) {
            $balanceAfter === null || $balanceAfter > 0.004 => 'The new item was sent for approval. Nothing has been saved yet; after it is approved, collect the remaining amount with Pay.',
            $balanceAfter < -0.004 => $sent.'after it is approved, the difference is refunded to the customer.',
            default => $sent.'after it is approved, nothing more is due.',
        };
    }

    private function hasPendingEditRequest(Rental $rental): bool
    {
        return $this->approvalClassFor($rental)::query()
            ->where('status', 'pending')
            ->where('payload->kind', 'edit')
            ->where('payload->rental_id', $rental->id)
            ->exists();
    }

    /**
     * Quick edit: payment cannot be changed when the rent was recorded as full payment,
     * or (for down payments) the saved amount already covers the submitted line total.
     */
    private function rentalEditPaymentFieldsFrozen(Rental $rental, float $totalDueNetInclTax): bool
    {
        if (StoreCache::get('admin_transaction_edit_mode', false)) {
            return false;
        }

        // Lock only when the recorded payments actually cover the total.
        // A full/final payment recorded below the (possibly recomputed) total
        // must stay collectible, mirroring editModalPaymentFieldsLocked() in JS.
        // overdue_change_amount is cash handed BACK to the customer, so it never counts as paid.
        $paid = (float) ($rental->amount_paid ?? 0)
            + (float) ($rental->overdue_amount_paid ?? 0);
        $due = round($totalDueNetInclTax, 2);

        return round($paid, 2) + 0.004 >= $due;
    }

    private function normalizeRentalMoneyRequest(Request $request): void
    {
        if (! StoreCache::get('pricing_enabled', true)) {
            return;
        }

        $discType = (string) $request->input('discount_type', 'fixed');
        if ($discType === 'fixed') {
            $raw = $request->input('discount');
            if ($raw === null || $raw === '') {
                $request->merge(['discount' => null]);
            } else {
                $norm = MoneyInput::normalizeNullable($raw);
                $request->merge(['discount' => $norm ?? $raw]);
            }
        }

        $apRaw = $request->input('amount_paid');
        if ($apRaw === null || $apRaw === '') {
            $request->merge(['amount_paid' => null]);
        } else {
            $apNorm = MoneyInput::normalizeNullable($apRaw);
            $request->merge(['amount_paid' => $apNorm ?? $apRaw]);
        }

        $excRaw = $request->input('extra_charge');
        if ($excRaw === null || $excRaw === '') {
            $request->merge(['extra_charge' => null]);
        } else {
            $excNorm = MoneyInput::normalizeNullable($excRaw);
            $request->merge(['extra_charge' => $excNorm ?? $excRaw]);
        }

        $addPay = $request->input('payment_amount_add');
        if ($addPay !== null && $addPay !== '') {
            $addNorm = MoneyInput::normalizeNullable($addPay);
            $request->merge(['payment_amount_add' => $addNorm ?? $addPay]);
        }

        $ovPaid = $request->input('overdue_amount_paid');
        if ($ovPaid !== null && $ovPaid !== '') {
            $ovPaidNorm = MoneyInput::normalizeNullable($ovPaid);
            $request->merge(['overdue_amount_paid' => $ovPaidNorm ?? $ovPaid]);
        }

        $ovAdd = $request->input('overdue_payment_amount_add');
        if ($ovAdd !== null && $ovAdd !== '') {
            $ovAddNorm = MoneyInput::normalizeNullable($ovAdd);
            $request->merge(['overdue_payment_amount_add' => $ovAddNorm ?? $ovAdd]);
        }

        $discounts = $request->input('item_discounts', []);
        $types = $request->input('item_discount_types', []);
        if (! is_array($discounts)) {
            return;
        }
        foreach ($discounts as $id => $val) {
            $t = is_array($types) ? ($types[$id] ?? 'fixed') : 'fixed';
            if ($t !== 'fixed' || $val === null || $val === '') {
                continue;
            }
            $norm = MoneyInput::normalizeNullable($val);
            $discounts[$id] = $norm ?? $val;
        }
        $request->merge(['item_discounts' => $discounts]);
    }

    private function rentalReturnBufferDays(): int
    {
        $default = (int) config('app.rental_return_buffer_days', 1);
        $bufferDays = (int) StoreCache::get('rental_return_buffer_days', $default);

        return max(0, $bufferDays);
    }

    private function currentUserOpenShiftId(): ?int
    {
        $id = ShiftSession::where('opened_by_user_id', auth()->id())
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /**
     * Field-level diff for the activity log, limited to fields that matter for
     * fraud/accountability review (financials, dates, contact info, notes).
     * $beforeAttributes must be captured via $rental->getAttributes() before
     * ->update() is called — Eloquent syncs originals during save(), so
     * getOriginal() no longer reflects pre-update values afterward.
     *
     * @param  array<string, mixed>  $beforeAttributes
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    private function loggableRentalChanges(Rental $rental, array $beforeAttributes): array
    {
        $trackedFields = [
            'customer_name', 'phone', 'instagram', 'notes',
            'payment_method', 'payment_kind', 'amount_paid',
            'discount', 'discount_type', 'discount_note', 'extra_charge',
            'start_date', 'end_date', 'status',
            'overdue_amount_paid', 'overdue_payment_method', 'overdue_fee_amount',
        ];

        $changes = [];
        foreach ($rental->getChanges() as $field => $newValue) {
            if (! in_array($field, $trackedFields, true)) {
                continue;
            }
            $changes[$field] = [$beforeAttributes[$field] ?? null, $newValue];
        }

        return $changes;
    }

    /**
     * @param  array<int>  $outfitIds
     */
    private function hasDuplicatePendingApproval(int $userId, array $outfitIds, string $startDate, string $endDate, ?int $editRentalId = null): bool
    {
        $sorted = array_values(array_map('intval', $outfitIds));
        sort($sorted);

        return RentalApprovalRequest::query()
            ->where('requested_by_user_id', $userId)
            ->where('status', 'pending')
            ->get()
            ->contains(function (RentalApprovalRequest $req) use ($sorted, $startDate, $endDate, $editRentalId) {
                $payload = $req->payload ?? [];
                $kind = (string) ($payload['kind'] ?? 'create');
                $rid = isset($payload['rental_id']) ? (int) $payload['rental_id'] : null;
                if ($editRentalId !== null) {
                    if ($kind !== 'edit' || $rid !== $editRentalId) {
                        return false;
                    }
                } elseif ($kind === 'edit') {
                    return false;
                }
                $r = $payload['rental'] ?? [];
                if (($r['start_date'] ?? '') !== $startDate || ($r['end_date'] ?? '') !== $endDate) {
                    return false;
                }
                $ids = array_values(array_map('intval', $payload['outfit_ids'] ?? []));
                sort($ids);

                return $ids === $sorted;
            });
    }

    private function rejectUnlessRentOperator(Request $request): RedirectResponse|JsonResponse|null
    {
        $user = $request->user();
        if ($user && $user->mayOperateRentTransactions()) {
            return null;
        }

        // Name the signed-in account: pages can outlive a login switch in another
        // tab, so the viewer often *thinks* they are still an admin.
        $message = $user
            ? 'You are signed in as '.$user->name.' ('.$user->normalizedRole().'). This role cannot create or change rents. If you switched accounts in another tab, reload this page and use an admin account.'
            : 'Your session has ended. Reload the page and log in again.';

        $accept = strtolower((string) $request->header('Accept', ''));
        $wantsJson = $request->expectsJson()
            || str_contains($accept, 'application/json')
            || str_contains($accept, '+json');

        if ($wantsJson) {
            return response()->json(['message' => $message, 'ok' => false], 403);
        }

        return redirect()->route('rentals.index')->with('error', $message);
    }

    /**
     * Rental create/update/status/pickup/delete/import require an open shift for the current user.
     */
    private function rejectUnlessOpenShift(Request $request): RedirectResponse|JsonResponse|null
    {
        // The open-shift requirement is a cash-register/shift-reconciliation concern —
        // it applies to admin (the front-line cashier role) but not to management,
        // supervisor, or super_admin, who aren't running a till.
        $actor = $request->user();
        if ($actor && ($actor->isManagement() || $actor->isSupervisor() || $actor->isSuperAdmin())) {
            return null;
        }

        if ($this->currentUserOpenShiftId() !== null) {
            return null;
        }

        // Tutorial practice actions (is_tutorial-tagged) are excluded from shift
        // stats/reports entirely — requiring a real open shift for them would make
        // the onboarding tour fail for anyone who hasn't opened one yet, for a
        // requirement that doesn't apply to their (excluded) data anyway.
        // The flag alone is client-supplied, so it only counts when the rent being acted on
        // really is a practice rent (or none is bound yet: create / bulk import make practice rows).
        $boundRental = $request->route('rental');
        if ($request->boolean('tutorial_practice') && (! $boundRental instanceof Rental || $boundRental instanceof TutorialRental)) {
            return null;
        }

        $message = 'Open a shift before creating or changing rentals.';
        $wantsJson = $request->expectsJson() || $request->ajax() || $request->wantsJson();
        if ($wantsJson) {
            return response()->json(['message' => $message, 'errors' => ['shift' => [$message]]], 422);
        }

        return back()->withInput()->withErrors(['shift' => $message]);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildShiftControlContext(?User $viewer): array
    {
        $viewerId = $viewer?->id;
        $currentShift = null;
        if ($viewerId !== null) {
            $currentShift = ShiftSession::where('opened_by_user_id', $viewerId)
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->first();
        }

        $openShifts = null;
        if ($viewer && ($viewer->isManagement() || $viewer->isSuperAdmin())) {
            // Always across every store regardless of the viewer's currently
            // selected store — this is a "who's working right now" monitoring
            // widget, not a per-store report, so silently filtering it by
            // whatever store happens to be selected would hide a forgotten
            // open shift in another store with no indication it's incomplete.
            $openShifts = ShiftSession::withoutGlobalScope('store_scope')
                ->with(['openedBy', 'store'])
                ->whereNull('closed_at')
                ->orderByDesc('opened_at')
                ->get();
        }

        $shiftStats = $currentShift ? $this->computeShiftStats($currentShift) : null;

        $tutorialShiftActive = (bool) session('shift_tutorial_active', false);
        $tutorialView = (string) session('shift_tutorial_view', 'latihan');
        $tutorialShift = null;
        $tutorialShiftStats = null;
        $tutorialLastClosedShift = null;
        if ($tutorialShiftActive && $viewerId !== null) {
            $tutorialShift = TutorialShiftSession::where('opened_by_user_id', $viewerId)
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->first();
            $tutorialShiftStats = $tutorialShift ? $this->computeShiftStats($tutorialShift) : null;
            // Order by id, not closed_at — same clock-skew reasoning as $lastClosedShift below.
            $tutorialLastClosedShift = TutorialShiftSession::where('opened_by_user_id', $viewerId)
                ->whereNotNull('closed_at')
                ->orderByDesc('id')
                ->first();
        }

        $lastClosedShift = null;
        if ($viewerId !== null) {
            // Order by id, not closed_at: the debug clock (and any clock skew)
            // can stamp closed_at in the future, while ids follow true order.
            $lastClosedShift = ShiftSession::query()
                ->with(['openedBy', 'closedBy'])
                ->whereNotNull('closed_at')
                ->where('opened_by_user_id', $viewerId)
                ->orderByDesc('id')
                ->first();
        }

        $mayOperateRentTransactions = $viewer?->mayOperateRentTransactions() ?? false;
        // Mirrors rejectUnlessOpenShift(): management/supervisor/super_admin aren't
        // running a till, so they don't need an open shift to act on a rental.
        $shiftExemptFromMutationLock = $viewer && ($viewer->isManagement() || $viewer->isSupervisor() || $viewer->isSuperAdmin());
        $rentalMutationsLocked = (! $shiftExemptFromMutationLock && $currentShift === null) || ! $mayOperateRentTransactions;

        return compact(
            'currentShift',
            'openShifts',
            'shiftStats',
            'lastClosedShift',
            'rentalMutationsLocked',
            'mayOperateRentTransactions',
            'tutorialShiftActive',
            'tutorialView',
            'tutorialShift',
            'tutorialShiftStats',
            'tutorialLastClosedShift',
        );
    }

    /**
     * Same running-totals computation used for both the real Shift Control
     * card and the practice one — driven entirely through $shift->rentals(),
     * so it works unmodified for a TutorialShiftSession (see
     * TutorialShiftSession::rentals(), which points at TutorialRental
     * instead of Rental).
     */
    private function computeShiftStats(ShiftSession $currentShift): array
    {
        {
            $tax = $this->pricingTaxPercent();
            $shiftRentals = $currentShift->rentals()
                ->with(['outfits', 'handledBy', 'createdBy'])
                ->orderByDesc('id')
                ->get();
            $financialTotals = [
                'gross' => 0.0,
                'item_discounts' => 0.0,
                'order_discounts' => 0.0,
                'pre_tax' => 0.0,
                'tax' => 0.0,
                'net_incl_tax' => 0.0,
            ];
            $totalPaid = 0.0;
            $totalReceived = 0.0;
            $totalOutstanding = 0.0;
            $itemCount = 0;
            $pickedUp = 0;
            $details = [];
            $shiftPaymentRecords = RentalPaymentRecord::query()
                ->where('shift_session_id', $currentShift->id)
                ->get();
            $shiftRecordsByRental = $shiftPaymentRecords->groupBy(fn ($rec) => (int) $rec->rental_id);
            $rentalIdsWithAnyRecord = RentalPaymentRecord::query()
                ->whereIn('rental_id', $shiftRentals->pluck('id'))
                ->distinct()
                ->pluck('rental_id')
                ->map(fn ($v) => (int) $v)
                ->flip();
            foreach ($shiftRentals as $r) {
                $fin = RentalFinancialSummary::summarize($r, $tax);
                $net = (float) ($fin['net_incl_tax'] ?? 0);
                $paidOverall = max(0.0, (float) ($r->amount_paid ?? 0)) + max(0.0, (float) ($r->overdue_amount_paid ?? 0));
                if (isset($rentalIdsWithAnyRecord[(int) $r->id])) {
                    // Payment records exist: count only money received during this shift.
                    $paid = (float) ($shiftRecordsByRental->get((int) $r->id)?->sum('amount') ?? 0);
                    $received = $paid;
                } else {
                    // Legacy rentals without payment records: attribute all payments to the creation shift.
                    $paid = $paidOverall;
                    $received = $net > 0 ? min($paid, $net) : $paid;
                }
                $outstanding = max(0.0, $net - $paidOverall);
                $financialTotals['gross'] += $fin['gross'];
                $financialTotals['item_discounts'] += $fin['item_discount_total'];
                $financialTotals['order_discounts'] += $fin['order_discount_amount'];
                $financialTotals['pre_tax'] += $fin['pre_tax_net'];
                $financialTotals['tax'] += $fin['tax_amount'];
                $financialTotals['net_incl_tax'] += $fin['net_incl_tax'];
                $totalPaid += $paid;
                $totalReceived += $received;
                $totalOutstanding += $outstanding;
                $itemCount += $r->outfits->count();
                if ($r->picked_up_at !== null) {
                    $pickedUp++;
                }
                $details[] = [
                    'rental' => $r,
                    'financial' => $fin,
                    'paid' => $paid,
                    'received' => $received,
                    'outstanding' => $outstanding,
                ];
            }

            // Payments taken this shift for rentals created in other shifts (e.g. final payments).
            $externalRentalIds = $shiftPaymentRecords->pluck('rental_id')
                ->map(fn ($v) => (int) $v)
                ->unique()
                ->diff($shiftRentals->pluck('id')->map(fn ($v) => (int) $v));
            if ($externalRentalIds->isNotEmpty()) {
                $externalRentals = Rental::query()
                    ->with(['outfits', 'handledBy', 'createdBy'])
                    ->whereIn('id', $externalRentalIds)
                    ->orderByDesc('id')
                    ->get();
                foreach ($externalRentals as $r) {
                    $fin = RentalFinancialSummary::summarize($r, $tax);
                    $net = (float) ($fin['net_incl_tax'] ?? 0);
                    $paidOverall = max(0.0, (float) ($r->amount_paid ?? 0)) + max(0.0, (float) ($r->overdue_amount_paid ?? 0));
                    $paid = (float) ($shiftRecordsByRental->get((int) $r->id)?->sum('amount') ?? 0);
                    if (abs($paid) <= 0.004) {
                        continue;
                    }
                    $outstanding = max(0.0, $net - $paidOverall);
                    $totalPaid += $paid;
                    $totalReceived += $paid;
                    $totalOutstanding += $outstanding;
                    $details[] = [
                        'rental' => $r,
                        'financial' => $fin,
                        'paid' => $paid,
                        'received' => $paid,
                        'outstanding' => $outstanding,
                        'from_other_shift' => true,
                    ];
                }
            }
            $shiftStats = [
                'transactions' => $shiftRentals->count(),
                'revenue' => $totalReceived,
                'total_paid' => $totalPaid,
                'total_received' => $totalReceived,
                'total_outstanding' => $totalOutstanding,
                'item_count' => $itemCount,
                'picked_up_count' => $pickedUp,
                'total_item_discount' => $financialTotals['item_discounts'],
                'total_order_discount' => $financialTotals['order_discounts'],
                'financial_totals' => $financialTotals,
                'tax_percent' => $tax,
                'details' => $details,
            ];
        }

        return $shiftStats;
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $allStoresMode = (bool) $request->boolean('all_stores');
        if ($user && $user->isManagement() && ! $request->has('all_stores')) {
            $allStoresMode = true;
            StoreContext::set(StoreContext::storeId(), true);
        }

        $deletedMode = $request->boolean('deleted');
        if ($deletedMode && ! ($user && ($user->isManagement() || $user->isSuperAdmin()))) {
            abort(403);
        }

        $query = Rental::with(['outfits', 'handledBy', 'createdBy']);
        if ($deletedMode) {
            $query->onlyTrashed();
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->boolean('overdue')) {
            $query->where('status', 'active')
                ->whereDate('end_date', '<', now()->toDateString());
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('outfits', fn ($sq) => $sq->where('code', 'like', "%{$search}%"));
            });
        }

        $allSelected = ! $request->filled('status') && ! $request->boolean('overdue');
        if ($allSelected) {
            $query->orderByDesc('id');
        } else {
            $query->latest();
        }

        $perPage = in_array($request->per_page, [10, 25, 50]) ? (int) $request->per_page : 10;
        $rentals = $query->paginate($perPage)->withQueryString();
        $outfits = Outfit::orderBy('code')->get();
        $shiftContext = $this->buildShiftControlContext(auth()->user());

        $adminTransactionEditMode = (bool) StoreCache::get('admin_transaction_edit_mode', false);
        $mayViewActivityLog = (bool) ($user?->mayViewActivityLog() ?? false);

        return view('rentals.index', array_merge(compact('rentals', 'outfits', 'allStoresMode', 'adminTransactionEditMode', 'deletedMode', 'mayViewActivityLog'), $shiftContext));
    }

    public function shiftControlFragment(Request $request): JsonResponse
    {
        $shiftContext = $this->buildShiftControlContext($request->user());
        $html = view('rentals.partials.shift-control', $shiftContext)->render();

        return response()->json([
            'ok' => true,
            'html' => $html,
            'rental_mutations_locked' => (bool) ($shiftContext['rentalMutationsLocked'] ?? true),
            'may_operate_rent_transactions' => (bool) ($shiftContext['mayOperateRentTransactions'] ?? false),
            'has_open_shift' => (bool) ($shiftContext['currentShift'] ?? false),
        ]);
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $wantsJson = $request->expectsJson() || $request->ajax() || $request->wantsJson();

        if ($redirect = $this->rejectUnlessRentOperator($request)) {
            return $redirect;
        }

        if ($redirect = $this->rejectUnlessOpenShift($request)) {
            return $redirect;
        }

        $pricingEnabled = (bool) StoreCache::get('pricing_enabled', true);
        if (! $pricingEnabled) {
            $request->merge(['discount' => null, 'discount_type' => 'fixed', 'discount_note' => null, 'item_discounts' => [], 'item_discount_types' => [], 'amount_paid' => null, 'payment_kind' => null]);
        }

        $this->normalizeRentalMoneyRequest($request);

        $validated = $request->validate([
            'outfit_ids' => 'required|array|min:1',
            'outfit_ids.*' => $this->outfitExistsRule(),
            'customer_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'instagram' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'payment_method' => $pricingEnabled ? 'required|string|max:100' : 'nullable|string|max:100',
            'payment_kind' => $pricingEnabled ? 'required|in:full,down_payment,final_payment' : 'nullable|in:full,down_payment,final_payment',
            'amount_paid' => $pricingEnabled ? 'required|numeric|min:0.01|max:999999999.99' : 'nullable|numeric|min:0|max:999999999.99',
            'discount' => 'nullable|numeric|min:0|max:999999999.99',
            'discount_type' => 'nullable|in:fixed,percent',
            'extra_charge' => 'nullable|numeric|min:0|max:999999999.99',
            'discount_note' => 'nullable|string|max:500',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
        ]);

        if (! $pricingEnabled) {
            $validated['extra_charge'] = null;
        }

        if ($pricingEnabled) {
            $extraDaysForCreate = RentalFinancialSummary::extraDaysFromDates($validated['start_date'], $validated['end_date']);
            if ($extraDaysForCreate > 0 && ! (($validated['extra_charge'] ?? null) > 0)) {
                $msg = 'Additional charge is required for dates that are not a multiple of 3 days ('.$extraDaysForCreate.' extra '.($extraDaysForCreate === 1 ? 'day' : 'days').').';
                return $wantsJson
                    ? response()->json(['message' => $msg, 'errors' => ['extra_charge' => [$msg]]], 422)
                    : back()->withInput()->withErrors(['extra_charge' => $msg]);
            }
        }

        $itemDiscounts = $request->input('item_discounts', []);
        if (! is_array($itemDiscounts)) {
            $itemDiscounts = [];
        }

        if ($pricingEnabled
            && (float) ($validated['discount'] ?? 0) > 0.0
            && empty(trim((string) ($validated['discount_note'] ?? '')))) {
            $message = 'Discount note is required when applying an order discount.';

            return $wantsJson
                ? response()->json(['message' => $message, 'errors' => ['discount_note' => [$message]]], 422)
                : back()->withInput()->withErrors(['discount_note' => $message]);
        }

        $itemDiscountNotes = $request->input('item_discount_notes', []);
        if ($pricingEnabled
            && $this->missingItemDiscountNote($itemDiscounts, is_array($itemDiscountNotes) ? $itemDiscountNotes : [])) {
            $message = 'Discount reason is required for each item discount.';

            return $wantsJson
                ? response()->json(['message' => $message, 'errors' => ['item_discount_notes' => [$message]]], 422)
                : back()->withInput()->withErrors(['item_discount_notes' => $message]);
        }

        if ($response = $this->rejectIfFullPaymentBelowTotalDue($request, $validated, $wantsJson, $pricingEnabled)) {
            return $response;
        }
        if ($response = $this->rejectIfPaymentExceedsTotalDue($request, $validated, $wantsJson, $pricingEnabled)) {
            return $response;
        }

        $validated['outfit_ids'] = array_values(array_unique(array_map('intval', $validated['outfit_ids'])));
        $validated['handled_by_user_id'] = auth()->id();
        $validated['created_by_user_id'] = auth()->id();
        $validated['shift_session_id'] = ShiftSession::where('opened_by_user_id', auth()->id())
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->value('id');

        $conflicts = $this->hasDateConflicts($validated['outfit_ids'], $validated['start_date'], $validated['end_date']);
        if (! empty($conflicts)) {
            $boundaryOnly = $this->hasOnlyBoundaryCooldownConflicts($validated['outfit_ids'], $validated['start_date'], $validated['end_date']);
            $actor = auth()->user();
            $isManagerActor = $actor && ($actor->isManagement() || $actor->isSuperAdmin());

            if ($boundaryOnly && ! $isManagerActor && $actor) {
                if ($this->hasDuplicatePendingApproval($actor->id, $validated['outfit_ids'], $validated['start_date'], $validated['end_date'])) {
                    return $wantsJson
                        ? response()->json(['ok' => true, 'mode' => 'approval_duplicate'])
                        : redirect()
                            ->route('rentals.index')
                            ->with('approval_duplicate', true);
                }

                $createApprovalFocus = $this->outfitIdsNeedingCooldownApprovalFocusForCreate(
                    $validated['outfit_ids'],
                    $validated['start_date'],
                    $validated['end_date'],
                );
                $approvalPayload = [
                    'kind' => 'create',
                    'rental' => [
                        'customer_name' => $validated['customer_name'],
                        'phone' => $validated['phone'],
                        'instagram' => $validated['instagram'] ?? null,
                        'notes' => $validated['notes'] ?? '',
                        'payment_method' => $validated['payment_method'] ?? null,
                        'amount_paid' => $validated['amount_paid'] ?? null,
                        'payment_kind' => $validated['payment_kind'] ?? null,
                        'discount' => $validated['discount'] ?? null,
                        'discount_type' => $validated['discount_type'] ?? 'fixed',
                        'extra_charge' => $validated['extra_charge'] ?? null,
                        'discount_note' => $validated['discount_note'] ?? null,
                        'start_date' => $validated['start_date'],
                        'end_date' => $validated['end_date'],
                        'status' => 'active',
                        'handled_by_user_id' => $validated['handled_by_user_id'] ?? null,
                        'customer_pending' => false,
                    ],
                    'outfit_ids' => $validated['outfit_ids'],
                    'item_discounts' => $request->input('item_discounts', []),
                    'item_discount_types' => $request->input('item_discount_types', []),
                    'item_discount_notes' => $request->input('item_discount_notes', []),
                ];
                if ($createApprovalFocus !== []) {
                    $approvalPayload['approval_focus_outfit_ids'] = $createApprovalFocus;
                }

                $approval = RentalApprovalRequest::create([
                    'requested_by_user_id' => $actor->id,
                    'source_shift_session_id' => $validated['shift_session_id'] ?? null,
                    'payload' => $approvalPayload,
                    'is_tutorial' => $request->boolean('tutorial_practice'),
                ]);

                return $wantsJson
                    ? response()->json([
                        'ok' => true,
                        'mode' => 'approval_request_sent',
                        'pending_summary' => $approval->summaryForJson(),
                    ])
                    : redirect()
                        ->route('rentals.index')
                        ->with('approval_request_sent', true);
            }

            if ($boundaryOnly && $isManagerActor) {
                // Manager approval is implicit when manager creates directly.
            } else {
                $msg = 'Some outfits are already rented: '.implode(', ', $conflicts);

                return $wantsJson
                    ? response()->json(['message' => $msg, 'errors' => ['outfit_ids' => [$msg]]], 422)
                    : back()->withInput()->withErrors([
                        'outfit_ids' => $msg,
                    ]);
            }
        }

        $outfitIds = $validated['outfit_ids'];
        unset($validated['outfit_ids']);

        $discounts = $request->input('item_discounts', []);
        $discountTypes = $request->input('item_discount_types', []);
        $discountNotes = $request->input('item_discount_notes', []);
        $taxPercent = $this->pricingTaxPercent();
        $taxRate = max(0.0, $taxPercent / 100);
        $pricesIncludeTax = (bool) StoreCache::get('pricing_prices_include_tax', false);
        $outfitsById = ($request->boolean('tutorial_practice') ? Outfit::withoutGlobalScope('tutorial_scope') : Outfit::query())
            ->whereIn('id', $outfitIds)
            ->get(['id', 'price'])
            ->keyBy('id');
        $attachData = [];
        foreach ($outfitIds as $outfitId) {
            $raw = (float) (($outfitsById->get($outfitId)?->price) ?? 0);
            if ($pricesIncludeTax) {
                $unitIncl = $raw;
                // Same fix as update()'s already-correct version below: $raw
                // is tax-inclusive, so unwrap it by dividing by (1+rate) —
                // multiplying by rate directly taxes the tax itself.
                $unitExcl = $taxRate > 0 ? round($unitIncl / (1 + $taxRate), 2) : $unitIncl;
                $unitTax = round($unitIncl - $unitExcl, 2);
            } else {
                $unitExcl = $raw;
                $unitTax = round($unitExcl * $taxRate, 2);
                $unitIncl = round($unitExcl + $unitTax, 2);
            }
            $attachData[$outfitId] = [
                'discount' => $discounts[$outfitId] ?? null,
                'discount_type' => $discountTypes[$outfitId] ?? 'fixed',
                'discount_note' => isset($discountNotes[$outfitId]) ? (string) $discountNotes[$outfitId] : null,
                'price_incl_tax' => $unitIncl,
                'price_excl_tax' => $unitExcl,
                'tax_amount' => $unitTax,
            ];
        }

        if (($validated['payment_kind'] ?? null) === 'down_payment') {
            $validated['down_payment_amount'] = round((float) ($validated['amount_paid'] ?? 0), 2);
        }

        // Practice rentals go into their own table (TutorialRental — see that
        // class) so repeated tour runs never advance the real rentals id
        // sequence, instead of being a tagged row in the shared table.
        $isTutorialPractice = $request->boolean('tutorial_practice');
        $storeRentalClass = $isTutorialPractice ? TutorialRental::class : Rental::class;
        // Explicit even though the column defaults false in the DB — a
        // freshly ::create()'d model doesn't reload DB-computed defaults, so
        // leaving this unset makes $rental->is_tutorial null in memory, and
        // RentalActivityLog::record() below reads that value straight into a
        // NOT NULL column on its own insert, which fails outright.
        $validated['is_tutorial'] = $isTutorialPractice;
        if ($isTutorialPractice) {
            // tutorial_rentals.shift_session_id has an FK to tutorial_shift_sessions: it may only hold a practice
            // shift (or null), never the real open shift's id (that made the insert fail with a 500).
            $validated['shift_session_id'] = TutorialShiftSession::where('opened_by_user_id', auth()->id())
                ->whereNull('closed_at')
                ->latest('opened_at')
                ->value('id');
        }
        $rental = $storeRentalClass::create($validated);
        $rental->outfits()->attach($attachData);
        $this->activityLogClassFor($storeRentalClass)::record($rental, 'created');

        if ($pricingEnabled) {
            // Cap at the rent-only bill (overdue fines are recorded by the separate
            // overdue path): overpay is returned as change and never enters the shift.
            $rental->load('outfits');
            $storeFin = RentalFinancialSummary::summarize($rental, $taxPercent);
            $due = max(0.0, (float) $storeFin['net_incl_tax'] - max(0.0, (float) ($storeFin['overdue_fee_amount'] ?? 0)));
            RentalPaymentRecorder::recordIfNonZero(
                $rental,
                min(max(0.0, (float) ($validated['amount_paid'] ?? 0)), $due),
                $validated['shift_session_id'] ?? null,
                auth()->id(),
                $validated['payment_method'] ?? null,
                $validated['payment_kind'] ?? null,
                totalDue: $due,
            );
        }

        return $wantsJson
            ? response()->json([
                'ok' => true,
                'mode' => 'rental_created',
                'rental_id' => $rental->id,
            ])
            : redirect()->route('rentals.index')->with('success', 'Rental created successfully.');
    }

    /**
     * Staff-only: queue a cool-down manager approval using rental dates + cart items only (customer fields optional).
     * Optional rental_id: Quick edit — full customer/payment payload; on approve the existing rent is updated (not duplicated).
     */
    public function storeCooldownApprovalRequest(Request $request): JsonResponse
    {
        $wantsJson = $request->expectsJson() || $request->ajax() || $request->wantsJson();
        if (! $wantsJson) {
            return response()->json(['message' => 'Use the Rentals page button.'], 406);
        }

        if ($redirect = $this->rejectUnlessRentOperator($request)) {
            return $redirect;
        }

        if ($redirect = $this->rejectUnlessOpenShift($request)) {
            return $redirect;
        }

        $pricingEnabled = (bool) StoreCache::get('pricing_enabled', true);
        if (! $pricingEnabled) {
            $request->merge(['item_discounts' => [], 'item_discount_types' => []]);
        }

        $validated = $request->validate([
            'outfit_ids' => 'required|array|min:1',
            'outfit_ids.*' => $this->outfitExistsRule(),
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'rental_id' => 'nullable|integer|exists:rentals,id',
            'takeover_from' => 'nullable|array',
            'takeover_from.*' => 'nullable|integer|exists:rentals,id',
            'approval_focus_outfit_ids' => 'nullable|array',
            'approval_focus_outfit_ids.*' => ['integer', $this->outfitExistsRule()],
        ]);

        $outfitIds = array_values(array_unique(array_map('intval', $validated['outfit_ids'])));
        $focusRaw = array_values(array_unique(array_map('intval', $validated['approval_focus_outfit_ids'] ?? [])));
        $approvalFocusOutfitIds = array_values(array_filter(
            $focusRaw,
            fn (int $id) => $id > 0 && in_array($id, $outfitIds, true)
        ));
        $editRentalId = isset($validated['rental_id']) ? (int) $validated['rental_id'] : null;

        $actor = auth()->user();
        if (! $actor || $actor->isSuperAdmin()) {
            return response()->json([
                'message' => 'This shortcut is for staff. Managers can use Create Rental for cool-down bookings.',
            ], 422);
        }
        if ($actor->isManagement()) {
            return response()->json([
                'ok' => false,
                'message' => 'This shortcut is for staff. Managers can use Create Rental for cool-down bookings.',
            ], 403);
        }

        $shiftSessionId = ShiftSession::where('opened_by_user_id', auth()->id())
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->value('id');

        $editPayload = [];
        $allowedTakeovers = [];
        $existingRental = null;

        if ($editRentalId !== null) {
            $existingRental = Rental::query()->whereKey($editRentalId)->first();
            $editableStatuses = StoreCache::get('admin_transaction_edit_mode', false) ? ['active', 'returned'] : ['active'];
            if (! $existingRental || ! in_array($existingRental->status, $editableStatuses, true)) {
                return response()->json([
                    'message' => 'Rent not found or not active.',
                    'errors' => ['rental_id' => ['Invalid rent.']],
                ], 422);
            }

            if ($pricingEnabled) {
                $this->normalizeRentalMoneyRequest($request);
            }
            $paymentEntry = $request->input('payment_entry', 'replace');
            $editRules = [
                'customer_name' => 'required|string|max:255',
                'phone' => 'required|string|max:20',
                'instagram' => 'nullable|string|max:100',
                'notes' => 'nullable|string|max:500',
            ];
            if ($pricingEnabled) {
                $editRules['payment_method'] = 'required|string|max:100';
                $editRules['payment_kind'] = 'required|in:full,down_payment,final_payment';
                $editRules['payment_entry'] = 'nullable|in:replace,increment';
                if ($paymentEntry === 'increment') {
                    $editRules['payment_amount_add'] = 'nullable|numeric|min:0|max:999999999.99';
                    $editRules['amount_paid'] = 'prohibited';
                } else {
                    $editRules['amount_paid'] = 'required|numeric|min:0.01|max:999999999.99';
                }
                $editRules['discount'] = 'nullable|numeric|min:0|max:999999999.99';
                $editRules['discount_type'] = 'nullable|in:fixed,percent';
                $editRules['extra_charge'] = 'nullable|numeric|min:0|max:999999999.99';
                $editRules['discount_note'] = 'nullable|string|max:500';
            }
            $addsItemToSettledRent = false;
            $editPayload = $request->validate($editRules);
            if ($pricingEnabled && $paymentEntry === 'increment') {
                $addRaw = $request->input('payment_amount_add');
                $add = ($addRaw === null || $addRaw === '') ? 0.0 : (float) $addRaw;
                if ($add > 0.004 && ! $request->boolean('pay_action') && $this->editChangesBill($request, $existingRental)) {
                    $add = 0.0;
                    $editPayload['payment_kind'] = $existingRental->payment_kind;
                }
                $prev = (float) ($existingRental->amount_paid ?? 0);
                $editPayload['amount_paid'] = round($prev + max(0.0, $add), 2);
            }
            if ($pricingEnabled) {
                $itemDiscounts = $request->input('item_discounts', []);
                $itemDiscountTypes = $request->input('item_discount_types', []);
                if (! is_array($itemDiscounts)) {
                    $itemDiscounts = [];
                }
                if (! is_array($itemDiscountTypes)) {
                    $itemDiscountTypes = [];
                }
                $preview = RentalFinancialSummary::previewRentalForTotals(
                    $outfitIds,
                    $itemDiscounts,
                    $itemDiscountTypes,
                    $editPayload['discount'] ?? null,
                    $editPayload['discount_type'] ?? 'fixed',
                    $editPayload['start_date'] ?? $existingRental->start_date,
                    $editPayload['end_date'] ?? $existingRental->end_date,
                    $existingRental->created_at,
                    RentalFinancialSummary::lockedUnitPrices($existingRental),
                    $existingRental instanceof TutorialRental,
                );
                $preview->extra_charge = max(0.0, (float) ($editPayload['extra_charge'] ?? 0));
                $due = (float) RentalFinancialSummary::summarize($preview, $this->pricingTaxPercent())['net_incl_tax'];
                // D11: same rule, and same order, as a plain Save (see update()). It is decided BEFORE the payment
                // lock: a swap to a cheaper item on a paid rent is locked too, and must still be flagged so that
                // somebody else decides it.
                $addsItemToSettledRent = $this->addsItemToSettledRent($existingRental, $outfitIds);
                if ($addsItemToSettledRent && $request->boolean('pay_action')) {
                    $message = $this->addItemToSettledRentMessage();

                    return response()->json(['message' => $message, 'errors' => ['approval' => [$message]]], 422);
                }
                if ($this->rentalEditPaymentFieldsFrozen($existingRental, $due)) {
                    $editPayload['payment_method'] = $existingRental->payment_method;
                    $editPayload['payment_kind'] = $existingRental->payment_kind;
                    $editPayload['amount_paid'] = round((float) ($existingRental->amount_paid ?? 0), 2);
                    $editPayload['extra_charge'] = $existingRental->extra_charge;
                } else {
                    if ($existingRental->payment_kind !== 'down_payment') {
                        $editPayload['payment_kind'] = 'full';
                    }
                    if (round((float) $editPayload['amount_paid'], 2) >= round($due, 2)) {
                        $editPayload['payment_kind'] = $existingRental->payment_kind === 'down_payment' ? 'final_payment' : 'full';
                    }
                    if ($addsItemToSettledRent) {
                        // A new item on a paid rent takes no money up front: the balance is paid after approval.
                        $editPayload['payment_kind'] = 'down_payment';
                        $editPayload['amount_paid'] = round((float) ($existingRental->amount_paid ?? 0), 2);
                    }
                }
            }
            $itemDiscounts = $request->input('item_discounts', []);
            if (! is_array($itemDiscounts)) {
                $itemDiscounts = [];
            }

            if ($pricingEnabled
                && (float) ($editPayload['discount'] ?? 0) > 0.0
                && empty(trim((string) ($editPayload['discount_note'] ?? '')))) {
                return response()->json(['errors' => ['discount_note' => ['Discount note is required when applying an order discount.']]], 422);
            }

            $itemDiscountNotes = $request->input('item_discount_notes', []);
            if ($pricingEnabled
                && $this->missingItemDiscountNote($itemDiscounts, is_array($itemDiscountNotes) ? $itemDiscountNotes : [])) {
                return response()->json(['errors' => ['item_discount_notes' => ['Discount reason is required for each item discount.']]], 422);
            }

            if ($pricingEnabled) {
                $fullPaymentCheck = array_merge($editPayload, ['outfit_ids' => $outfitIds]);
                if ($response = $this->rejectIfFullPaymentBelowTotalDue($request, $fullPaymentCheck, true, $pricingEnabled, $existingRental)) {
                    return $response;
                }
                if ($response = $this->rejectIfPaymentExceedsTotalDue($request, $fullPaymentCheck, true, $pricingEnabled, (float) ($existingRental->amount_paid ?? 0), $existingRental)) {
                    return $response;
                }
            }

            $takeoverFrom = $request->input('takeover_from', []);
            foreach (is_array($takeoverFrom) ? $takeoverFrom : [] as $outfitId => $fromRentalId) {
                $sid = (int) $outfitId;
                $rid = (int) $fromRentalId;
                if ($sid > 0 && $rid > 0 && in_array($sid, $outfitIds, true) && $rid !== $editRentalId) {
                    $allowedTakeovers[$sid] = $rid;
                }
            }
            $takeoverRentalIds = array_values(array_unique(array_values($allowedTakeovers)));
            $takeoverRentals = Rental::withCount('outfits')->whereIn('id', $takeoverRentalIds)->get()->keyBy('id');
            foreach ($allowedTakeovers as $sid => $fromRid) {
                $from = $takeoverRentals->get($fromRid);
                if (! $from) {
                    return response()->json(['errors' => ['outfit_ids' => ['Invalid takeover rental.']]], 422);
                }
                if ($from->status !== 'active') {
                    return response()->json(['errors' => ['outfit_ids' => ['Only active rentals can be taken over.']]], 422);
                }
                if (! $from->outfits()->whereKey($sid)->exists()) {
                    return response()->json(['errors' => ['outfit_ids' => ['Takeover item not found in the source rental.']]], 422);
                }
            }

            if (! $this->hasOnlyBoundaryCooldownConflictsForEdit($outfitIds, $validated['start_date'], $validated['end_date'], $editRentalId)) {
                return response()->json([
                    'message' => 'No cool-down conflict for this change. Try Save, or adjust items and dates.',
                    'errors' => ['outfit_ids' => ['No manager approval needed for this change.']],
                ], 422);
            }

            $hard = $this->hardCooldownConflictsExcludingRental($outfitIds, $validated['start_date'], $validated['end_date'], $editRentalId);
            if ($hard !== []) {
                $msg = 'These items are not available for this period (not only a cool-down edge case). Change dates or items, or use takeover where available.';

                return response()->json([
                    'message' => $msg,
                    'errors' => ['outfit_ids' => [$msg]],
                ], 422);
            }
        } else {
            $conflicts = $this->hasDateConflicts($outfitIds, $validated['start_date'], $validated['end_date']);
            if ($conflicts === []) {
                return response()->json([
                    'message' => 'No cool-down conflict for these items and dates. Use Create Rental to book normally.',
                    'errors' => ['outfit_ids' => ['No manager approval needed for this selection.']],
                ], 422);
            }

            if (! $this->hasOnlyBoundaryCooldownConflicts($outfitIds, $validated['start_date'], $validated['end_date'])) {
                $msg = 'These items are not available for this period (not only a cool-down edge case). Change dates or items, or use takeover where available.';

                return response()->json([
                    'message' => $msg,
                    'errors' => ['outfit_ids' => [$msg]],
                ], 422);
            }
        }

        if ($this->hasDuplicatePendingApproval($actor->id, $outfitIds, $validated['start_date'], $validated['end_date'], $editRentalId)) {
            return response()->json(['ok' => true, 'mode' => 'approval_duplicate']);
        }

        if ($editRentalId === null && $approvalFocusOutfitIds === []) {
            $approvalFocusOutfitIds = $this->outfitIdsNeedingCooldownApprovalFocusForCreate(
                $outfitIds,
                $validated['start_date'],
                $validated['end_date'],
            );
        }

        if ($editRentalId !== null) {
            $rentalPayload = [
                'customer_name' => $editPayload['customer_name'],
                'phone' => $editPayload['phone'],
                'instagram' => $editPayload['instagram'] ?? null,
                'notes' => $editPayload['notes'] ?? '',
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'status' => 'active',
                'handled_by_user_id' => $actor->id,
                'customer_pending' => false,
            ];
            if ($pricingEnabled) {
                $rentalPayload['payment_method'] = $editPayload['payment_method'];
                $rentalPayload['payment_kind'] = $editPayload['payment_kind'];
                $rentalPayload['amount_paid'] = $editPayload['amount_paid'];
                $rentalPayload['discount'] = $editPayload['discount'] ?? null;
                $rentalPayload['discount_type'] = $editPayload['discount_type'] ?? 'fixed';
                $rentalPayload['extra_charge'] = $editPayload['extra_charge'] ?? null;
                $rentalPayload['discount_note'] = $editPayload['discount_note'] ?? null;
            } else {
                $rentalPayload['payment_method'] = null;
                $rentalPayload['payment_kind'] = null;
                $rentalPayload['amount_paid'] = null;
                $rentalPayload['discount'] = null;
                $rentalPayload['discount_type'] = 'fixed';
                $rentalPayload['extra_charge'] = null;
                $rentalPayload['discount_note'] = null;
            }

            $editPayloadData = [
                'kind' => 'edit',
                'rental_id' => $editRentalId,
                // What had been paid when this request was made. If money is recorded
                // in the meantime (a direct payment), approving must add only what THIS
                // request changes on top of the current amount, never restore this one.
                'base_amount_paid' => round((float) ($existingRental->amount_paid ?? 0), 2),
                'add_item_settled' => $addsItemToSettledRent,
                'rental' => $rentalPayload,
                'outfit_ids' => $outfitIds,
                'takeover_from' => $allowedTakeovers,
                'item_discounts' => $request->input('item_discounts', []),
                'item_discount_types' => $request->input('item_discount_types', []),
                'item_discount_notes' => $request->input('item_discount_notes', []),
            ];
            if ($approvalFocusOutfitIds !== []) {
                $editPayloadData['approval_focus_outfit_ids'] = $approvalFocusOutfitIds;
            }

            $approval = RentalApprovalRequest::create([
                'requested_by_user_id' => $actor->id,
                'source_shift_session_id' => $shiftSessionId,
                'payload' => $editPayloadData,
            ]);
            if ($existingRental) {
                RentalActivityLog::record($existingRental, 'edit_requested', [], note: 'Approval #'.$approval->id, userId: $actor->id);
            }
        } else {
            // Optional customer details typed before requesting approval: keep
            // them so the manager sees who the booking is for and approval does
            // not fall back to a placeholder unnecessarily.
            $createCustomer = $request->validate([
                'customer_name' => 'nullable|string|max:255',
                'phone' => 'nullable|string|max:20',
                'instagram' => 'nullable|string|max:100',
                'notes' => 'nullable|string|max:500',
            ]);
            $createCustomerName = trim((string) ($createCustomer['customer_name'] ?? ''));

            $createPayment = [
                'payment_method' => null,
                'payment_kind' => null,
                'amount_paid' => null,
                'discount' => null,
                'discount_type' => 'fixed',
                'extra_charge' => null,
                'discount_note' => null,
            ];
            if ($pricingEnabled) {
                $this->normalizeRentalMoneyRequest($request);

                $createItemDiscounts = $request->input('item_discounts', []);
                $createItemDiscountNotes = $request->input('item_discount_notes', []);
                if ($this->missingItemDiscountNote(
                    is_array($createItemDiscounts) ? $createItemDiscounts : [],
                    is_array($createItemDiscountNotes) ? $createItemDiscountNotes : []
                )) {
                    return response()->json(['errors' => ['item_discount_notes' => ['Discount reason is required for each item discount.']]], 422);
                }
                if ((float) $request->input('discount', 0) > 0.0
                    && trim((string) $request->input('discount_note', '')) === '') {
                    return response()->json(['errors' => ['discount_note' => ['Discount note is required when applying an order discount.']]], 422);
                }

                // If staff filled Payment & Settlement before requesting approval,
                // hold it to the same rules as a normal create so a "full" payment
                // below the total due cannot slip through via the approval path.
                $hasPaymentInput = trim((string) $request->input('payment_method', '')) !== ''
                    || trim((string) $request->input('amount_paid', '')) !== '';
                if ($hasPaymentInput) {
                    $pay = $request->validate([
                        'payment_method' => 'required|string|max:100',
                        'payment_kind' => 'required|in:full,down_payment,final_payment',
                        'amount_paid' => 'required|numeric|min:0.01|max:999999999.99',
                        'discount' => 'nullable|numeric|min:0|max:999999999.99',
                        'discount_type' => 'nullable|in:fixed,percent',
                        'extra_charge' => 'nullable|numeric|min:0|max:999999999.99',
                        'discount_note' => 'nullable|string|max:500',
                    ]);
                    $fullPaymentCheck = array_merge($pay, ['outfit_ids' => $outfitIds]);
                    if ($response = $this->rejectIfFullPaymentBelowTotalDue($request, $fullPaymentCheck, true, $pricingEnabled)) {
                        return $response;
                    }
                    if ($response = $this->rejectIfPaymentExceedsTotalDue($request, $fullPaymentCheck, true, $pricingEnabled)) {
                        return $response;
                    }
                    $createPayment = [
                        'payment_method' => $pay['payment_method'],
                        'payment_kind' => $pay['payment_kind'],
                        'amount_paid' => $pay['amount_paid'],
                        'discount' => $pay['discount'] ?? null,
                        'discount_type' => $pay['discount_type'] ?? 'fixed',
                        'extra_charge' => $pay['extra_charge'] ?? null,
                        'discount_note' => $pay['discount_note'] ?? null,
                    ];
                }
            }

            $createPayloadData = [
                'kind' => 'create',
                'rental' => [
                    'customer_name' => $createCustomerName,
                    'phone' => trim((string) ($createCustomer['phone'] ?? '')),
                    'instagram' => ($createCustomer['instagram'] ?? null) ?: null,
                    'notes' => trim((string) ($createCustomer['notes'] ?? '')),
                    'payment_method' => $createPayment['payment_method'],
                    'payment_kind' => $createPayment['payment_kind'],
                    'amount_paid' => $createPayment['amount_paid'],
                    'discount' => $createPayment['discount'],
                    'discount_type' => $createPayment['discount_type'],
                    'extra_charge' => $createPayment['extra_charge'],
                    'discount_note' => $createPayment['discount_note'],
                    'start_date' => $validated['start_date'],
                    'end_date' => $validated['end_date'],
                    'status' => 'active',
                    'handled_by_user_id' => $actor->id,
                    'customer_pending' => $createCustomerName === '',
                ],
                'outfit_ids' => $outfitIds,
                'item_discounts' => $request->input('item_discounts', []),
                'item_discount_types' => $request->input('item_discount_types', []),
                'item_discount_notes' => $request->input('item_discount_notes', []),
            ];
            if ($approvalFocusOutfitIds !== []) {
                $createPayloadData['approval_focus_outfit_ids'] = $approvalFocusOutfitIds;
            }

            $approval = RentalApprovalRequest::create([
                'requested_by_user_id' => $actor->id,
                'source_shift_session_id' => $shiftSessionId,
                'payload' => $createPayloadData,
            ]);
        }

        return response()->json([
            'ok' => true,
            'mode' => 'approval_request_sent',
            'pending_summary' => $approval->summaryForJson(),
        ]);
    }

    /**
     * Outfit codes that still have a non-buffer-only overlap with other active rentals (excluding the rental being edited).
     */
    private function hardCooldownConflictsExcludingRental(array $outfitIds, string $startDate, string $endDate, int $excludeRentalId): array
    {
        $bufferDays = $this->rentalReturnBufferDays();
        $requestedStart = Carbon::parse($startDate)->startOfDay();
        $requestedEnd = Carbon::parse($endDate)->startOfDay();
        $hardConflicts = [];

        foreach ($outfitIds as $outfitId) {
            $query = Rental::whereHas('outfits', fn ($q) => $q->where('outfits.id', $outfitId))
                ->where('status', 'active')
                ->where('start_date', '<=', $requestedEnd->copy()->addDays($bufferDays))
                ->where('end_date', '>=', $requestedStart->copy()->subDays($bufferDays));

            $rentals = $query->get();

            if (Schema::hasColumn('rentals', 'outfit_id')) {
                $fallback = Rental::where('outfit_id', $outfitId)
                    ->where('status', 'active')
                    ->where('start_date', '<=', $requestedEnd->copy()->addDays($bufferDays))
                    ->where('end_date', '>=', $requestedStart->copy()->subDays($bufferDays))
                    ->get();
                $rentals = $rentals->merge($fallback)->unique('id')->values();
            }

            $rentals = $rentals->reject(fn ($r) => (int) $r->id === $excludeRentalId)->values();

            foreach ($rentals as $rental) {
                $boundaryForward = $rental->end_date->copy()->addDays($bufferDays)->isSameDay($requestedStart);
                $boundaryBackward = $rental->start_date->copy()->subDays($bufferDays)->isSameDay($requestedEnd);
                if (! ($boundaryForward || $boundaryBackward)) {
                    $outfit = Outfit::find($outfitId);
                    $hardConflicts[] = $outfit ? $outfit->code : "#{$outfitId}";
                    break;
                }
            }
        }

        return array_values(array_unique($hardConflicts));
    }

    public function update(Request $request, Rental $rental): RedirectResponse|JsonResponse
    {
        $wantsJson = $request->expectsJson() || $request->ajax() || $request->wantsJson();

        if ($reject = $this->rejectUnlessRentOperator($request)) {
            return $reject;
        }

        if ($reject = $this->rejectUnlessOpenShift($request)) {
            return $reject;
        }

        if ($rental->status === 'returned' && ! StoreCache::get('admin_transaction_edit_mode', false)) {
            $message = 'This rent is returned and locked. Changes are not allowed.';

            return $wantsJson
                ? response()->json(['message' => $message, 'errors' => ['status' => [$message]]], 422)
                : back()->withInput()->withErrors(['status' => $message]);
        }

        // A cancelled rent is closed like a returned one: its deposit was forfeited or refunded, so no new payment
        // or edit may land on it until management reactivates it (review finding P3).
        if ($rental->status === 'cancelled' && ! StoreCache::get('admin_transaction_edit_mode', false)) {
            $message = 'This rent is cancelled and locked. Ask management to reactivate it before changing it or taking a payment.';

            return $wantsJson
                ? response()->json(['message' => $message, 'errors' => ['status' => [$message]]], 422)
                : back()->withInput()->withErrors(['status' => $message]);
        }

        $pricingEnabled = (bool) StoreCache::get('pricing_enabled', true);
        if (! $pricingEnabled) {
            $request->merge(['discount' => null, 'discount_type' => 'fixed', 'discount_note' => null, 'item_discounts' => [], 'item_discount_types' => [], 'amount_paid' => null, 'payment_kind' => null]);
        }

        $this->normalizeRentalMoneyRequest($request);

        $paymentEntry = $request->input('payment_entry', 'replace');
        $overduePaymentEntry = $request->input('overdue_payment_entry', 'replace');
        $rules = [
            'outfit_ids' => 'required|array|min:1',
            'outfit_ids.*' => $this->outfitExistsRule(),
            'customer_name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'instagram' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:500',
            'payment_method' => $pricingEnabled ? 'required|string|max:100' : 'nullable|string|max:100',
            'payment_kind' => $pricingEnabled ? 'required|in:full,down_payment,final_payment' : 'nullable|in:full,down_payment,final_payment',
            'discount' => 'nullable|numeric|min:0|max:999999999.99',
            'discount_type' => 'nullable|in:fixed,percent',
            'extra_charge' => 'nullable|numeric|min:0|max:999999999.99',
            'discount_note' => 'nullable|string|max:500',
            'start_date' => 'required|date',
            'end_date' => 'required|date|after_or_equal:start_date',
            'overdue_base_end_date' => 'nullable|date',
            'overdue_charge_through_date' => 'nullable|date',
            'overdue_fee_rate' => 'nullable|numeric|min:0|max:999999999.99',
            'overdue_fee_days' => 'nullable|integer|min:0|max:36500',
            'overdue_fee_amount' => 'nullable|numeric|min:0|max:999999999.99',
            'takeover_from' => 'nullable|array',
            'takeover_from.*' => 'nullable|integer|exists:rentals,id',
        ];
        if ($pricingEnabled) {
            $rules['payment_entry'] = 'nullable|in:replace,increment';
            if ($paymentEntry === 'increment') {
                $rules['payment_amount_add'] = 'nullable|numeric|min:0|max:999999999.99';
                $rules['amount_paid'] = 'prohibited';
            } else {
                $rules['amount_paid'] = 'required|numeric|min:0.01|max:999999999.99';
            }

            $rules['overdue_payment_method'] = 'nullable|string|max:100';
            $rules['overdue_payment_entry'] = 'nullable|in:replace,increment';
            if ($overduePaymentEntry === 'increment') {
                $rules['overdue_payment_amount_add'] = 'nullable|numeric|min:0|max:999999999.99';
                $rules['overdue_amount_paid'] = 'prohibited';
            } else {
                $rules['overdue_amount_paid'] = 'nullable|numeric|min:0|max:999999999.99';
                $rules['overdue_payment_amount_add'] = 'prohibited';
            }
        } else {
            $rules['amount_paid'] = 'nullable|numeric|min:0|max:999999999.99';
        }

        // An overdue-only payment on a rent with no rent payment yet (e.g. a
        // placeholder booking created via approval) must not demand the rent
        // payment fields — the overdue payment has its own method/amount checks below.
        $rentHasPayment = (float) ($rental->amount_paid ?? 0) > 0.004
            || trim((string) ($rental->payment_method ?? '')) !== '';
        $overdueOnlyPayment = $pricingEnabled
            && ! $rentHasPayment
            && $overduePaymentEntry === 'increment'
            && (float) $request->input('overdue_payment_amount_add', 0) > 0.009
            && trim((string) $request->input('payment_method', '')) === '';
        if ($overdueOnlyPayment) {
            $rules['payment_method'] = 'nullable|string|max:100';
            $rules['payment_kind'] = 'nullable|in:full,down_payment,final_payment';
            if ($paymentEntry !== 'increment') {
                $rules['amount_paid'] = 'nullable|numeric|min:0|max:999999999.99';
            }
        }

        $prevEndDate = $rental->end_date ? $rental->end_date->toDateString() : null;

        $validated = $request->validate($rules);

        // Order discount fields are locked while a down payment is still
        // outstanding, for Management/Supervisor/Super Admin — their edits
        // apply directly with no review step, so without this there's no
        // safeguard against an accidental in-flight discount change while
        // just collecting a payment. Admin is exempted: admin's edit always
        // goes through the edit-approval queue for a human to decide
        // regardless (see the admin gate further below) — except a "pure
        // overdue" action, which applies directly even for admin, but that
        // path requires the discount to already be unchanged (see
        // $isPureOverdueAction below), so a real discount change from admin
        // can never slip through unreviewed. "admin transaction edit mode"
        // (Settings) lifts this lock entirely for deliberate
        // historical-transaction fixes. Mirrors the client-side lock in
        // rentals-index.js (discountLocked / _discLocked).
        $actorIsAdmin = (bool) optional(auth()->user())->isAdmin();
        $discountEditLocked = $pricingEnabled
            && ($rental->payment_kind ?? null) === 'down_payment'
            && ! StoreCache::get('admin_transaction_edit_mode', false)
            && ! $actorIsAdmin;
        if ($discountEditLocked) {
            $validated['discount'] = $rental->discount;
            $validated['discount_type'] = $rental->discount_type ?? 'fixed';
            $validated['discount_note'] = $rental->discount_note;
        }

        $overdueBaseEndDate = $validated['overdue_base_end_date'] ?? null;
        $overdueChargeThroughDate = $validated['overdue_charge_through_date'] ?? null;
        $overdueFeeRate = $validated['overdue_fee_rate'] ?? null;
        $overdueFeeDays = $validated['overdue_fee_days'] ?? null;
        $overdueFeeAmount = $validated['overdue_fee_amount'] ?? null;
        unset(
            $validated['overdue_base_end_date'],
            $validated['overdue_charge_through_date'],
            $validated['overdue_fee_rate'],
            $validated['overdue_fee_days'],
            $validated['overdue_fee_amount'],
        );

        $hasOverdueChargePayload = is_string($overdueChargeThroughDate) && $overdueChargeThroughDate !== '';
        $hasOverdueFeePayload = $overdueFeeAmount !== null && $overdueFeeAmount !== '' && (float) $overdueFeeAmount > 0.009;

        if ($hasOverdueFeePayload && $prevEndDate && isset($validated['end_date']) && $validated['end_date'] !== $prevEndDate) {
            $validated['end_date'] = $prevEndDate;
        }

        if ($hasOverdueChargePayload && $prevEndDate && isset($validated['end_date']) && $validated['end_date'] !== $prevEndDate) {
            $validated['end_date'] = $prevEndDate;
        }

        if (! $hasOverdueChargePayload && ! $hasOverdueFeePayload && $prevEndDate && isset($validated['end_date']) && $validated['end_date'] !== $prevEndDate) {
            $validated['overdue_base_end_date'] = null;
            $validated['overdue_merged_at'] = null;
            $validated['overdue_charge_through_date'] = null;
            $validated['overdue_fee_rate'] = null;
            $validated['overdue_fee_days'] = null;
            $validated['overdue_fee_amount'] = null;
            $validated['overdue_fee_applied_at'] = null;
        }

        if ($hasOverdueChargePayload && $prevEndDate) {
            $base = is_string($overdueBaseEndDate) && $overdueBaseEndDate !== '' ? $overdueBaseEndDate : $prevEndDate;
            if ($base === $prevEndDate && $overdueChargeThroughDate > $base) {
                if (! $rental->overdue_base_end_date) {
                    $validated['overdue_base_end_date'] = $base;
                }
                $validated['overdue_charge_through_date'] = $overdueChargeThroughDate;
                if (! $rental->overdue_merged_at) {
                    $validated['overdue_merged_at'] = now();
                }
            }
        }

        if ($hasOverdueFeePayload && $prevEndDate) {
            $base = is_string($overdueBaseEndDate) && $overdueBaseEndDate !== '' ? $overdueBaseEndDate : $prevEndDate;
            if ($base === $prevEndDate) {
                $incomingFeeRate = round((float) ($overdueFeeRate ?? 0), 2);
                $incomingFeeDays = max(0, (int) ($overdueFeeDays ?? 0));
                $incomingFeeAmount = round((float) ($overdueFeeAmount ?? 0), 2);
                $storedFeeAmount = round((float) ($rental->overdue_fee_amount ?? 0), 2);
                $storedFeeDays = max(0, (int) ($rental->overdue_fee_days ?? 0));
                $storedChargeThrough = $rental->overdue_charge_through_date?->toDateString()
                    ?? $rental->overdue_base_end_date?->toDateString()
                    ?? $prevEndDate;
                $incomingChargeThrough = is_string($overdueChargeThroughDate) && $overdueChargeThroughDate !== ''
                    ? $overdueChargeThroughDate
                    : null;
                $hasStoredFee = $rental->overdue_fee_applied_at !== null || $storedFeeAmount > 0.009;
                $allowInitialCharge = ! $hasStoredFee;
                $allowAdditionalCharge = $hasStoredFee
                    && $incomingChargeThrough !== null
                    && $incomingChargeThrough > $storedChargeThrough
                    && $incomingFeeAmount > $storedFeeAmount + 0.009
                    && $incomingFeeDays >= $storedFeeDays;

                if ($allowInitialCharge || $allowAdditionalCharge) {
                    if (! $rental->overdue_base_end_date) {
                        $validated['overdue_base_end_date'] = $base;
                    }
                    $validated['overdue_fee_rate'] = $incomingFeeRate;
                    $validated['overdue_fee_days'] = $incomingFeeDays;
                    $validated['overdue_fee_amount'] = $incomingFeeAmount;
                    $validated['overdue_fee_applied_at'] = now();
                    if (! $rental->overdue_merged_at) {
                        $validated['overdue_merged_at'] = now();
                    }
                } else {
                    $hasOverdueFeePayload = false;
                }
            }
        }

        if ($pricingEnabled && $paymentEntry === 'increment') {
            $addRaw = $request->input('payment_amount_add');
            $add = ($addRaw === null || $addRaw === '') ? 0.0 : (float) $addRaw;
            // A payment only ever comes from the Pay button, which leaves the bill as
            // it is. A save that changes items/dates/discount AND carries "payment" is
            // the read-only balance riding along (e.g. a browser tab still running the
            // old page) — never take it as money received.
            if ($add > 0.004 && ! $request->boolean('pay_action') && $this->editChangesBill($request, $rental)) {
                $add = 0.0;
                $validated['payment_kind'] = $rental->payment_kind;
            }
            $prev = (float) ($rental->amount_paid ?? 0);
            $validated['amount_paid'] = round($prev + max(0.0, $add), 2);
        }

        $overduePayEntry = (string) ($validated['overdue_payment_entry'] ?? $overduePaymentEntry ?? 'replace');
        $overduePayMethod = $validated['overdue_payment_method'] ?? null;
        $overduePayAdd = $validated['overdue_payment_amount_add'] ?? null;
        $overduePayReplace = $validated['overdue_amount_paid'] ?? null;
        unset(
            $validated['overdue_payment_entry'],
            $validated['overdue_payment_method'],
            $validated['overdue_payment_amount_add'],
            $validated['overdue_amount_paid'],
        );

        $hasOverduePaymentAttempt = $pricingEnabled
            && (($overduePayEntry === 'increment' && $overduePayAdd !== null && $overduePayAdd !== '' && (float) $overduePayAdd > 0.009)
                || ($overduePayEntry !== 'increment' && $overduePayReplace !== null && $overduePayReplace !== ''));

        $addsItemToSettledRent = false;
        $overdueFeeForPayment = 0.0;
        if ($pricingEnabled) {
            if ($hasOverdueFeePayload) {
                $overdueFeeForPayment = (float) $overdueFeeAmount;
            } else {
                $overdueFeeForPayment = (float) ($rental->overdue_fee_amount ?? 0);
            }
        }

        if ($hasOverduePaymentAttempt) {
            if ($overdueFeeForPayment <= 0.009) {
                $message = 'No overdue charge exists for this rent.';

                return $wantsJson
                    ? response()->json(['errors' => ['overdue_payment' => [$message]]], 422)
                    : back()->withInput()->withErrors(['overdue_payment' => $message]);
            }

            if (! is_string($overduePayMethod) || trim($overduePayMethod) === '') {
                $message = 'Payment method is required for overdue charge payment.';

                return $wantsJson
                    ? response()->json(['errors' => ['overdue_payment_method' => [$message]]], 422)
                    : back()->withInput()->withErrors(['overdue_payment_method' => $message]);
            }
        }

        if ($pricingEnabled) {
            $sidList = array_values(array_filter(
                array_map('intval', $validated['outfit_ids'] ?? []),
                fn (int $id) => $id > 0
            ));
            $itemDiscounts = $request->input('item_discounts', []);
            $itemDiscountTypes = $request->input('item_discount_types', []);
            if (! is_array($itemDiscounts)) {
                $itemDiscounts = [];
            }
            if (! is_array($itemDiscountTypes)) {
                $itemDiscountTypes = [];
            }
            $preview = RentalFinancialSummary::previewRentalForTotals(
                $sidList,
                $itemDiscounts,
                $itemDiscountTypes,
                $validated['discount'] ?? null,
                $validated['discount_type'] ?? 'fixed',
                $validated['start_date'] ?? $rental->start_date,
                $validated['end_date'] ?? $rental->end_date,
                $rental->created_at,
                RentalFinancialSummary::lockedUnitPrices($rental),
                $rental instanceof TutorialRental,
            );
            $preview->extra_charge = max(0.0, (float) ($validated['extra_charge'] ?? 0));
            $due = (float) RentalFinancialSummary::summarize($preview, $this->pricingTaxPercent())['net_incl_tax'];
            $feeForDue = 0.0;
            if ($hasOverdueFeePayload) {
                $feeForDue = (float) $overdueFeeAmount;
            } else {
                $feeForDue = (float) ($rental->overdue_fee_amount ?? 0);
            }
            $due = $due + max(0.0, $feeForDue);
            $addsItemToSettledRent = $this->addsItemToSettledRent($rental, $validated['outfit_ids'] ?? []);
            if ($addsItemToSettledRent && $request->boolean('pay_action')) {
                // No money up front for a new item on a paid rent: the request comes first, the balance is paid after.
                $message = $this->addItemToSettledRentMessage();

                return $wantsJson
                    ? response()->json(['message' => $message, 'errors' => ['approval' => [$message]]], 422)
                    : back()->withInput()->withErrors(['approval' => $message]);
            }
            if ($this->rentalEditPaymentFieldsFrozen($rental, $due)) {
                $validated['payment_method'] = $rental->payment_method;
                $validated['payment_kind'] = $rental->payment_kind;
                $validated['amount_paid'] = round((float) ($rental->amount_paid ?? 0), 2);
                // Additional charge follows the payment lock — cannot be changed once payment is settled.
                $validated['extra_charge'] = $rental->extra_charge;
            } else {
                $extraDaysForUpdate = RentalFinancialSummary::extraDaysFromDates($validated['start_date'], $validated['end_date']);
                if ($extraDaysForUpdate > 0 && ! (($validated['extra_charge'] ?? null) > 0)) {
                    $msg = 'Additional charge is required for dates that are not a multiple of 3 days ('.$extraDaysForUpdate.' extra '.($extraDaysForUpdate === 1 ? 'day' : 'days').').';
                    return $wantsJson
                        ? response()->json(['message' => $msg, 'errors' => ['extra_charge' => [$msg]]], 422)
                        : back()->withInput()->withErrors(['extra_charge' => $msg]);
                }
                if ($overdueOnlyPayment) {
                    // Collecting only the overdue fine on a rent with no rent payment: the rent payment fields
                    // stay as saved, so this still counts as a pure overdue action (no approval, money recorded).
                    $validated['payment_kind'] = $rental->payment_kind;
                } else {
                    if ($rental->payment_kind !== 'down_payment') {
                        $validated['payment_kind'] = 'full';
                    }
                    if (round((float) $validated['amount_paid'], 2) + 0.004 >= round($due, 2)) {
                        $validated['payment_kind'] = $rental->payment_kind === 'down_payment' ? 'final_payment' : 'full';
                    }
                    if ($addsItemToSettledRent) {
                        // The rent is paid for what it was; the new item is an open bill, so it is no longer
                        // a "full" payment (that kind would block every later save until it is paid).
                        $validated['payment_kind'] = 'down_payment';
                        $validated['amount_paid'] = round((float) ($rental->amount_paid ?? 0), 2);
                    }
                }
            }
        }

        // Track the initial down payment separately so it remains visible after final payment.
        if ($pricingEnabled && $rental->payment_kind === 'down_payment') {
            if ($rental->down_payment_amount === null) {
                $validated['down_payment_amount'] = round((float) ($rental->amount_paid ?? 0), 2);
            } else {
                $validated['down_payment_amount'] = (float) $rental->down_payment_amount;
            }
        }

        $itemDiscounts = $request->input('item_discounts', []);
        if (! is_array($itemDiscounts)) {
            $itemDiscounts = [];
        }

        if ($pricingEnabled
            && (float) ($validated['discount'] ?? 0) > 0.0
            && empty(trim((string) ($validated['discount_note'] ?? '')))) {
            $message = 'Discount note is required when applying an order discount.';

            return $wantsJson
                ? response()->json(['errors' => ['discount_note' => [$message]]], 422)
                : back()->withInput()->withErrors(['discount_note' => $message]);
        }

        $itemDiscountNotes = $request->input('item_discount_notes', []);
        $itemDiscountNotes = is_array($itemDiscountNotes) ? $itemDiscountNotes : [];
        $itemDiscountTypesInput = $request->input('item_discount_types', []);
        $itemDiscountTypesInput = is_array($itemDiscountTypesInput) ? $itemDiscountTypesInput : [];
        // Same down-payment lock as the order discount above, extended to
        // item-level discounts on items already on this rental — revert any
        // submitted change back to what's saved before it ever reaches the
        // note-requirement check below, so a locked edit is silently ignored
        // rather than surfacing a confusing validation error. Items newly
        // added in this same edit aren't in $rental->outfits() yet, so their
        // discount still passes through untouched.
        if ($discountEditLocked) {
            foreach ($rental->outfits()->get(['outfits.id']) as $existingOutfit) {
                $sid = $existingOutfit->id;
                $itemDiscounts[$sid] = $existingOutfit->pivot->discount;
                $itemDiscountTypesInput[$sid] = $existingOutfit->pivot->discount_type ?? 'fixed';
                $itemDiscountNotes[$sid] = $existingOutfit->pivot->discount_note;
            }
        }
        // Payment-only submits resend the saved item discounts without their
        // notes; carry the saved pivot note over when the discount is unchanged
        // so those submits neither fail validation nor wipe the stored reason.
        foreach ($rental->outfits()->get() as $existingOutfit) {
            $sid = $existingOutfit->id;
            if (! empty(trim((string) ($itemDiscountNotes[$sid] ?? '')))) {
                continue;
            }
            $submitted = $itemDiscounts[$sid] ?? null;
            if ($submitted === null || $submitted === '' || (float) $submitted <= 0.0) {
                continue;
            }
            $savedDiscount = $existingOutfit->pivot->discount;
            $savedNote = trim((string) ($existingOutfit->pivot->discount_note ?? ''));
            if ($savedDiscount !== null
                && (float) $savedDiscount === (float) $submitted
                && ($existingOutfit->pivot->discount_type ?? 'fixed') === ($itemDiscountTypesInput[$sid] ?? 'fixed')
                && $savedNote !== '') {
                $itemDiscountNotes[$sid] = $savedNote;
            }
        }
        $request->merge([
            'item_discounts' => $itemDiscounts,
            'item_discount_types' => $itemDiscountTypesInput,
            'item_discount_notes' => $itemDiscountNotes,
        ]);

        if ($pricingEnabled
            && $this->missingItemDiscountNote($itemDiscounts, $itemDiscountNotes)) {
            $message = 'Discount reason is required for each item discount.';

            return $wantsJson
                ? response()->json(['errors' => ['item_discount_notes' => [$message]]], 422)
                : back()->withInput()->withErrors(['item_discount_notes' => $message]);
        }

        // An overdue payment resubmits the saved rent payment untouched. If the
        // computed total has drifted since that payment was accepted (fee/tax/rate
        // changes), re-validating it would block the unrelated overdue payment —
        // only enforce the full-payment floor when this request changes the rent payment.
        $rentPaymentUnchanged = $pricingEnabled
            && $hasOverduePaymentAttempt
            && (string) ($validated['payment_method'] ?? '') === (string) ($rental->payment_method ?? '')
            && round((float) ($validated['amount_paid'] ?? 0), 2) === round((float) ($rental->amount_paid ?? 0), 2);
        if (! $rentPaymentUnchanged) {
            if ($response = $this->rejectIfFullPaymentBelowTotalDue($request, $validated, $wantsJson, $pricingEnabled, $rental)) {
                return $response;
            }
            // The business's own rule: what staff type in is the exact amount received,
            // never more — change is worked out by hand with a calculator and never
            // enters the system as "customer paid extra". A number above what is owed
            // is treated as a mistake to fix immediately, not a payment to record and
            // refund later. Only checked against genuinely NEW money — see the
            // function's own docblock for why an edit carrying an unchanged amount
            // forward is exempt.
            if ($response = $this->rejectIfPaymentExceedsTotalDue($request, $validated, $wantsJson, $pricingEnabled, (float) ($rental->amount_paid ?? 0), $rental)) {
                return $response;
            }
        }

        $validated['outfit_ids'] = array_values(array_unique(array_map('intval', $validated['outfit_ids'])));

        // Admin cannot edit an existing rental directly — the whole change set is
        // packaged as an approval request instead. Management/supervisor/super_admin
        // are unaffected (they already outrank admin, so gating them too would be
        // circular). Uses the same payload shape and approve()/reject() machinery as
        // the existing cool-down edit-approval flow, just without requiring an actual
        // date conflict to trigger it.
        //
        // Exception: overdue charge/payment actions ("Apply charge", "Pay overdue")
        // never need approval — collecting an overdue fine is operational, not a
        // sensitive edit, and needs to happen immediately at the counter. Detected by
        // requiring every other (non-overdue) field to be unchanged from what's
        // already saved; if the submission also changes a regular field, it still
        // goes through approval like any other edit — this isn't a bypass route.
        $editActor = auth()->user();
        $isPureOverdueAction = ($hasOverdueFeePayload || $hasOverduePaymentAttempt)
            && (string) $validated['customer_name'] === (string) $rental->customer_name
            && (string) $validated['phone'] === (string) $rental->phone
            && (string) ($validated['instagram'] ?? '') === (string) ($rental->instagram ?? '')
            && (string) ($validated['notes'] ?? '') === (string) ($rental->notes ?? '')
            && (string) ($validated['payment_method'] ?? '') === (string) ($rental->payment_method ?? '')
            && (string) ($validated['payment_kind'] ?? '') === (string) ($rental->payment_kind ?? '')
            && round((float) ($validated['amount_paid'] ?? 0), 2) === round((float) ($rental->amount_paid ?? 0), 2)
            && round((float) ($validated['discount'] ?? 0), 2) === round((float) ($rental->discount ?? 0), 2)
            && (string) ($validated['discount_type'] ?? 'fixed') === (string) ($rental->discount_type ?? 'fixed')
            && round((float) ($validated['extra_charge'] ?? 0), 2) === round((float) ($rental->extra_charge ?? 0), 2)
            && (string) $validated['start_date'] === $rental->start_date?->toDateString()
            && (string) $validated['end_date'] === $rental->end_date?->toDateString()
            && (function () use ($validated, $rental) {
                $submitted = $validated['outfit_ids'];
                sort($submitted);
                $current = $rental->outfits()->pluck('outfits.id')->map(fn ($id) => (int) $id)->all();
                sort($current);

                return $submitted === $current;
            })()
            // Item-level discounts aren't covered by any of the checks above
            // — without this, an admin's item-discount change (exempted from
            // the down-payment lock above since admin edits normally go
            // through approval) could ride along on an otherwise-untouched
            // overdue payment and apply directly, unreviewed.
            && (function () use ($itemDiscounts, $itemDiscountTypesInput, $rental) {
                foreach ($rental->outfits()->get(['outfits.id']) as $existingOutfit) {
                    $sid = $existingOutfit->id;
                    $submittedDiscount = $itemDiscounts[$sid] ?? null;
                    $submittedType = $itemDiscountTypesInput[$sid] ?? 'fixed';
                    $savedDiscount = $existingOutfit->pivot->discount;
                    $savedType = $existingOutfit->pivot->discount_type ?? 'fixed';
                    $submittedNum = ($submittedDiscount === null || $submittedDiscount === '') ? 0.0 : (float) $submittedDiscount;
                    $savedNum = $savedDiscount === null ? 0.0 : (float) $savedDiscount;
                    if (round($submittedNum, 2) !== round($savedNum, 2) || (string) $submittedType !== (string) $savedType) {
                        return false;
                    }
                }

                return true;
            })();

        // Collecting the remaining balance (DP -> final payment, or completing a
        // "full" rental) is operational too, same reasoning as the overdue
        // exemption above: it must not wait on a supervisor while the customer is
        // standing at the counter with the item in hand. Exempt when the ONLY
        // thing changing is the payment itself, and only upward (a payment, never
        // a correction) — everything else (customer info, discount, dates, items,
        // item discounts) must already match what is saved, or this falls back to
        // approval like any other edit.
        $isPurePaymentAction = (string) $validated['customer_name'] === (string) $rental->customer_name
            && (string) $validated['phone'] === (string) $rental->phone
            && (string) ($validated['instagram'] ?? '') === (string) ($rental->instagram ?? '')
            && (string) ($validated['notes'] ?? '') === (string) ($rental->notes ?? '')
            && round((float) ($validated['discount'] ?? 0), 2) === round((float) ($rental->discount ?? 0), 2)
            && (string) ($validated['discount_type'] ?? 'fixed') === (string) ($rental->discount_type ?? 'fixed')
            && round((float) ($validated['extra_charge'] ?? 0), 2) === round((float) ($rental->extra_charge ?? 0), 2)
            && (string) $validated['start_date'] === $rental->start_date?->toDateString()
            && (string) $validated['end_date'] === $rental->end_date?->toDateString()
            // Actually paying something, and only upward — never a downward correction.
            && round((float) ($validated['amount_paid'] ?? 0), 2) > round((float) ($rental->amount_paid ?? 0), 2) + 0.004
            && (function () use ($validated, $rental) {
                $submitted = $validated['outfit_ids'];
                sort($submitted);
                $current = $rental->outfits()->pluck('outfits.id')->map(fn ($id) => (int) $id)->all();
                sort($current);

                return $submitted === $current;
            })()
            && (function () use ($itemDiscounts, $itemDiscountTypesInput, $rental) {
                foreach ($rental->outfits()->get(['outfits.id']) as $existingOutfit) {
                    $sid = $existingOutfit->id;
                    $submittedDiscount = $itemDiscounts[$sid] ?? null;
                    $submittedType = $itemDiscountTypesInput[$sid] ?? 'fixed';
                    $savedDiscount = $existingOutfit->pivot->discount;
                    $savedType = $existingOutfit->pivot->discount_type ?? 'fixed';
                    $submittedNum = ($submittedDiscount === null || $submittedDiscount === '') ? 0.0 : (float) $submittedDiscount;
                    $savedNum = $savedDiscount === null ? 0.0 : (float) $savedDiscount;
                    if (round($submittedNum, 2) !== round($savedNum, 2) || (string) $submittedType !== (string) $savedType) {
                        return false;
                    }
                }

                return true;
            })();

        if ($editActor && ($editActor->isAdmin() || $addsItemToSettledRent) && ! $isPureOverdueAction && ! $isPurePaymentAction) {
            // A second request while one is still waiting would only become another
            // "out of date" rejection the moment the first is decided — better to stop
            // it here, before staff spend time filling out a form for nothing.
            if ($rental instanceof Rental && $this->hasPendingEditRequest($rental)) {
                $message = 'This rent already has an edit request waiting for approval. Wait for management to decide on it before submitting another.';

                return $wantsJson
                    ? response()->json(['message' => $message, 'errors' => ['approval' => [$message]]], 422)
                    : back()->withInput()->withErrors(['approval' => $message]);
            }

            $editRentalPayload = [
                'customer_name' => $validated['customer_name'],
                'phone' => $validated['phone'],
                'instagram' => $validated['instagram'] ?? null,
                'notes' => $validated['notes'] ?? '',
                'start_date' => $validated['start_date'],
                'end_date' => $validated['end_date'],
                'status' => 'active',
                'handled_by_user_id' => $editActor->id,
                'customer_pending' => false,
                'payment_method' => $validated['payment_method'] ?? null,
                'payment_kind' => $validated['payment_kind'] ?? null,
                'amount_paid' => $validated['amount_paid'] ?? null,
                'discount' => $validated['discount'] ?? null,
                'discount_type' => $validated['discount_type'] ?? 'fixed',
                'extra_charge' => $validated['extra_charge'] ?? null,
                'discount_note' => $validated['discount_note'] ?? null,
            ];

            // $rental resolved to either a real Rental or a TutorialRental
            // (see AppServiceProvider's {rental} binding) — the approval
            // request and its activity log entry need to land in the
            // matching table, same reasoning as everywhere else in this
            // tutorial-isolation work (see TutorialRental).
            $editApprovalClass = $rental instanceof TutorialRental ? TutorialRentalApprovalRequest::class : RentalApprovalRequest::class;
            $editApproval = $editApprovalClass::create([
                'requested_by_user_id' => $editActor->id,
                'source_shift_session_id' => $this->currentUserOpenShiftId(),
                'payload' => [
                    'kind' => 'edit',
                    'rental_id' => $rental->id,
                    // D11: an item added to a paid rent. Nobody decides their own request of this kind.
                    'add_item_settled' => $addsItemToSettledRent,
                    // What had been paid when this request was made — see the other edit
                    // request builder: approving adds only what this request changes.
                    'base_amount_paid' => round((float) ($rental->amount_paid ?? 0), 2),
                    'rental' => $editRentalPayload,
                    'outfit_ids' => $validated['outfit_ids'],
                    'item_discounts' => $request->input('item_discounts', []),
                    'item_discount_types' => $request->input('item_discount_types', []),
                    'item_discount_notes' => $request->input('item_discount_notes', []),
                ],
            ]);
            $this->activityLogClassFor(get_class($rental))::record($rental, 'edit_requested', [], note: 'Approval #'.$editApproval->id, userId: $editActor->id);

            $editMsg = $addsItemToSettledRent
                ? $this->addItemToSettledRentSentMessage($pricingEnabled && isset($due) ? round((float) $due - (float) ($rental->amount_paid ?? 0), 2) : null)
                : 'Changes submitted for supervisor/management approval. Nothing has been saved yet.';

            return $wantsJson
                ? response()->json([
                    'ok' => true,
                    'mode' => 'edit_approval_request_sent',
                    'message' => $editMsg,
                    'pending_edit_approval_id' => $editApproval->id,
                ])
                : redirect()->route('rentals.index')->with('success', $editMsg);
        }

        // Item removal on a rental that already has payment recorded requires
        // supervisor/management approval — never removed directly.
        // Detect removed items, park them behind a RentalApprovalRequest, and keep
        // them attached for this save (everything else on the form still saves now).
        $pendingItemRemovalApproval = null;
        $hasPaymentRecorded = (float) ($rental->amount_paid ?? 0) > 0.004;
        if ($hasPaymentRecorded) {
            $existingOutfitIds = $rental->outfits()->pluck('outfits.id')->map(fn ($id) => (int) $id)->all();
            $removedOutfitIds = array_values(array_diff($existingOutfitIds, $validated['outfit_ids']));
            if ($removedOutfitIds !== []) {
                $pendingItemRemovalApproval = $this->approvalClassFor($rental)::create([
                    'requested_by_user_id' => auth()->id(),
                    'source_shift_session_id' => $this->currentUserOpenShiftId(),
                    'payload' => [
                        'kind' => 'item_removal',
                        'rental_id' => $rental->id,
                        'removed_outfit_ids' => $removedOutfitIds,
                    ],
                    'is_tutorial' => $rental instanceof TutorialRental || $request->boolean('tutorial_practice'),
                ]);
                $this->activityLogClassFor(get_class($rental))::record($rental, 'item_removal_requested', [
                    'removed_outfit_ids' => [null, Outfit::codesForIds($removedOutfitIds)],
                ], note: 'Approval #'.$pendingItemRemovalApproval->id);
                // Keep the removed items attached for this save — the actual
                // removal only happens once an approver acts on the request.
                $validated['outfit_ids'] = array_values(array_unique(array_merge($validated['outfit_ids'], $removedOutfitIds)));
            }
        }

        $validated['handled_by_user_id'] = auth()->id();

        $takeoverFrom = $request->input('takeover_from', []);
        $allowedTakeovers = [];
        foreach (is_array($takeoverFrom) ? $takeoverFrom : [] as $outfitId => $fromRentalId) {
            $sid = (int) $outfitId;
            $rid = (int) $fromRentalId;
            if ($sid > 0 && $rid > 0 && in_array($sid, $validated['outfit_ids'], true) && $rid !== $rental->id) {
                $allowedTakeovers[$sid] = $rid;
            }
        }

        $takeoverRentalIds = array_values(array_unique(array_values($allowedTakeovers)));
        $takeoverRentals = Rental::withCount('outfits')->whereIn('id', $takeoverRentalIds)->get()->keyBy('id');
        foreach ($allowedTakeovers as $sid => $fromRid) {
            $from = $takeoverRentals->get($fromRid);
            if (! $from) {
                $errors = ['outfit_ids' => ['Invalid takeover rental.']];

                return $wantsJson
                    ? response()->json(['errors' => $errors], 422)
                    : back()->withInput()->withErrors($errors);
            }

            if ($from->status !== 'active') {
                $errors = ['outfit_ids' => ['Only active rentals can be taken over.']];

                return $wantsJson
                    ? response()->json(['errors' => $errors], 422)
                    : back()->withInput()->withErrors($errors);
            }

            if (! $from->outfits()->whereKey($sid)->exists()) {
                $errors = ['outfit_ids' => ['Takeover item not found in the source rental.']];

                return $wantsJson
                    ? response()->json(['errors' => $errors], 422)
                    : back()->withInput()->withErrors($errors);
            }
        }

        $conflicts = $this->hasDateConflicts($validated['outfit_ids'], $validated['start_date'], $validated['end_date'], $rental->id, $allowedTakeovers);
        if (! empty($conflicts)) {
            $errors = ['outfit_ids' => ['Some outfits are already rented: '.implode(', ', $conflicts)]];

            return $wantsJson
                ? response()->json(['errors' => $errors], 422)
                : back()->withInput()->withErrors($errors);
        }

        // Collecting a new payment while an edit request is still waiting on this
        // rental would be against an uncertain total — the discount/item change
        // being decided could move it either way. Block it outright rather than
        // accept the money and sort out an over/under-payment afterward: resolve
        // the pending request first, then collect. Applies whichever path is about
        // to save directly (management's normal edit, or an admin's exempt payment).
        $isNewPaymentBeingCollected = $pricingEnabled
            && round((float) ($validated['amount_paid'] ?? 0), 2) > round((float) ($rental->amount_paid ?? 0), 2) + 0.004;
        if ($isNewPaymentBeingCollected && $rental instanceof Rental) {
            $pendingEditExists = RentalApprovalRequest::query()
                ->where('status', 'pending')
                ->where('payload->kind', 'edit')
                ->where('payload->rental_id', $rental->id)
                ->exists();
            if ($pendingEditExists) {
                $msg = 'This rent has an edit request waiting for approval. Wait for management to decide before collecting payment — the total may still change.';

                return $wantsJson
                    ? response()->json(['message' => $msg, 'errors' => ['amount_paid' => [$msg]]], 422)
                    : back()->withInput()->withErrors(['amount_paid' => $msg]);
            }
        }

        $outfitIds = $validated['outfit_ids'];
        unset($validated['outfit_ids']);

        $discounts = $request->input('item_discounts', []);
        $discountTypes = $request->input('item_discount_types', []);
        $discountNotes = $request->input('item_discount_notes', []);
        $taxPercent = $this->pricingTaxPercent();
        $taxRate = max(0.0, $taxPercent / 100);
        $pricesIncludeTax = (bool) StoreCache::get('pricing_prices_include_tax', false);
        $outfitsById = ($rental instanceof TutorialRental ? Outfit::withoutGlobalScope('tutorial_scope') : Outfit::query())
            ->whereIn('id', $outfitIds)
            ->get(['id', 'price'])
            ->keyBy('id');
        $lockedPrices = RentalFinancialSummary::lockedUnitPrices($rental);
        $syncData = [];
        foreach ($outfitIds as $outfitId) {
            $raw = (float) (($outfitsById->get($outfitId)?->price) ?? 0);
            if (isset($lockedPrices[$outfitId])) {
                // Already on this rent: keep the price it was booked at, not today's catalog price.
                ['price_incl_tax' => $unitIncl, 'price_excl_tax' => $unitExcl, 'tax_amount' => $unitTax] = $lockedPrices[$outfitId];
            } elseif ($pricesIncludeTax) {
                $unitIncl = $raw;
                $unitExcl = $taxRate > 0 ? round($unitIncl / (1 + $taxRate), 2) : $unitIncl;
                $unitTax = round($unitIncl - $unitExcl, 2);
            } else {
                $unitExcl = $raw;
                $unitTax = round($unitExcl * $taxRate, 2);
                $unitIncl = round($unitExcl + $unitTax, 2);
            }
            $syncData[$outfitId] = [
                'discount' => $discounts[$outfitId] ?? null,
                'discount_type' => $discountTypes[$outfitId] ?? 'fixed',
                'discount_note' => isset($discountNotes[$outfitId]) ? (string) $discountNotes[$outfitId] : null,
                'price_incl_tax' => $unitIncl,
                'price_excl_tax' => $unitExcl,
                'tax_amount' => $unitTax,
            ];
        }

        $overduePaymentChange = 0.0;
        $overduePaymentSettled = null;
        $paymentShiftId = $this->currentUserOpenShiftId();
        $overdueShiftId = $hasOverduePaymentAttempt ? $paymentShiftId : null;
        $beforeOutfitIds = $rental->outfits()->pluck('outfits.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $beforeItemDiscounts = $rental->outfits()->get(['outfits.id', 'outfits.code'])->mapWithKeys(fn ($s) => [
            (int) $s->id => [
                'code' => $s->code,
                'discount' => $s->pivot->discount,
                'discount_type' => $s->pivot->discount_type ?? 'fixed',
            ],
        ])->all();

        $refundInfo = null;
        try {
            DB::transaction(function () use (
                $rental,
                $validated,
                $syncData,
                $outfitIds,
                $beforeOutfitIds,
                $beforeItemDiscounts,
                $allowedTakeovers,
                $takeoverRentals,
                $hasOverduePaymentAttempt,
                $overdueFeeForPayment,
                $overduePayEntry,
                $overduePayAdd,
                $overduePayReplace,
                $overduePayMethod,
                $overdueShiftId,
                $paymentShiftId,
                &$overduePaymentChange,
                &$overduePaymentSettled,
                &$refundInfo
            ) {
                $lockedRental = $rental::query()->whereKey($rental->id)->lockForUpdate()->first();
                if (! $lockedRental) {
                    throw new \RuntimeException('Rent not found.');
                }

                $prevAmountPaid = max(0.0, (float) ($lockedRental->amount_paid ?? 0));
                $beforeAttributes = $lockedRental->getAttributes();
                $lockedRental->update($validated);
                $fieldChanges = $this->loggableRentalChanges($lockedRental, $beforeAttributes);
                $newAmountPaid = max(0.0, (float) ($lockedRental->amount_paid ?? 0));
                $lockedRental->outfits()->sync($syncData);

                $afterOutfitIds = array_values(array_unique(array_map('intval', $outfitIds)));
                sort($afterOutfitIds);
                $itemsAdded = array_values(array_diff($afterOutfitIds, $beforeOutfitIds));
                $itemsRemovedDirect = array_values(array_diff($beforeOutfitIds, $afterOutfitIds));
                if ($itemsAdded !== [] || $itemsRemovedDirect !== []) {
                    $fieldChanges['outfit_ids'] = [Outfit::codesForIds($beforeOutfitIds), Outfit::codesForIds($afterOutfitIds)];
                }

                $itemDiscountChanges = [];
                $fmtItemDiscount = fn (float $amt, string $type) => $amt <= 0.004
                    ? 'none'
                    : ($type === 'percent' ? rtrim(rtrim(number_format($amt, 2), '0'), '.').'%' : 'Rp '.number_format($amt, 0, ',', '.'));
                foreach ($syncData as $sid => $data) {
                    if (! isset($beforeItemDiscounts[$sid])) {
                        continue;
                    }
                    $prev = $beforeItemDiscounts[$sid];
                    $prevDiscount = $prev['discount'] !== null ? (float) $prev['discount'] : 0.0;
                    $newDiscount = $data['discount'] !== null && $data['discount'] !== '' ? (float) $data['discount'] : 0.0;
                    $prevType = $prev['discount_type'] ?? 'fixed';
                    $newType = $data['discount_type'] ?? 'fixed';
                    if (abs($prevDiscount - $newDiscount) > 0.004 || $prevType !== $newType) {
                        $code = $prev['code'] ?? ('#'.$sid);
                        $itemDiscountChanges[] = $code.': '.$fmtItemDiscount($prevDiscount, $prevType).' → '.$fmtItemDiscount($newDiscount, $newType);
                    }
                }
                if ($itemDiscountChanges !== []) {
                    $fieldChanges['item_discounts'] = ['', $itemDiscountChanges];
                }

                if ($fieldChanges !== []) {
                    $this->activityLogClassFor(get_class($lockedRental))::record($lockedRental, 'updated', $fieldChanges);
                }

                // Cap at the rent-only bill: overpay is returned as change and never enters
                // the shift. The overdue fine is excluded — it is collected and recorded by
                // the separate overdue-payment path, and capping against it here would let
                // rent-side change be double-counted as received cash.
                $lockedRental->load('outfits');
                $fin = RentalFinancialSummary::summarize($lockedRental, $this->pricingTaxPercent());
                $due = max(0.0, (float) $fin['net_incl_tax'] - max(0.0, (float) ($fin['overdue_fee_amount'] ?? 0)));
                RentalPaymentRecorder::recordIfNonZero(
                    $lockedRental,
                    min($newAmountPaid, $due) - min($prevAmountPaid, $due),
                    $paymentShiftId,
                    auth()->id(),
                    $lockedRental->payment_method,
                    $lockedRental->payment_kind,
                    totalDue: $due,
                );

                // A direct edit can change the total with no payment delta
                // (recordIfNonZero returns early on 0) — bring the ledger's
                // contract up to the new total. Best-effort; see
                // RentalLedgerSync::reconcileRental().
                if ($lockedRental instanceof Rental && ! ($lockedRental instanceof TutorialRental)) {
                    $refundInfo = \App\Support\RentalLedgerSync::reconcileRental($lockedRental, refundOverpayment: true, byUserId: auth()->id());
                }

                foreach ($allowedTakeovers as $sid => $fromRid) {
                    $from = $takeoverRentals->get($fromRid);
                    if ($from) {
                        $from->outfits()->detach($sid);
                    }
                }

                if (! empty($allowedTakeovers)) {
                    $fromIds = array_values(array_unique(array_values($allowedTakeovers)));
                    $remaining = Rental::withCount('outfits')->whereIn('id', $fromIds)->get()->filter(fn ($r) => (int) $r->outfits_count === 0)->pluck('id')->all();
                    if (! empty($remaining)) {
                        Rental::whereIn('id', $remaining)->update(['status' => 'cancelled']);
                    }

                    // A rental that lost an item to this edit has a smaller
                    // total now — bring its ledger contract down too. The ones
                    // emptied out and cancelled above are NOT touched: what
                    // happens to their deposit is the cancellation policy's
                    // call, and RentalLedgerSync::run() lists them for review.
                    foreach (array_diff($fromIds, $remaining) as $fromId) {
                        $source = Rental::find($fromId);
                        if ($source) {
                            \App\Support\RentalLedgerSync::reconcileRental($source);
                        }
                    }
                }

                if (! $hasOverduePaymentAttempt) {
                    return;
                }

                $fee = max(0.0, (float) ($lockedRental->overdue_fee_amount ?? $overdueFeeForPayment));
                $paidBefore = max(0.0, (float) ($lockedRental->overdue_amount_paid ?? 0));
                $methodBefore = $lockedRental->overdue_payment_method;
                $remaining = max(0.0, $fee - $paidBefore);
                if ($remaining <= 0.004) {
                    throw new \RuntimeException('Overdue charge is already settled.');
                }

                $paidAfter = $paidBefore;
                if ($overduePayEntry === 'increment') {
                    $add = ($overduePayAdd === null || $overduePayAdd === '') ? 0.0 : (float) $overduePayAdd;
                    $paidAfter = round($paidBefore + max(0.0, $add), 2);
                } else {
                    $paidAfter = round((float) ($overduePayReplace ?? 0), 2);
                }
                if ($paidAfter + 0.004 < $fee) {
                    throw new \RuntimeException('Overdue payment must cover the full overdue balance.');
                }

                $kind = 'full';
                $change = round(max(0.0, $paidAfter - $fee), 2);
                $overduePaymentChange = $change;
                $overduePaymentSettled = $kind === 'full';
                $appliedPaidAfter = round(min($paidAfter, $fee), 2);

                $lockedRental->forceFill([
                    'overdue_amount_paid' => $appliedPaidAfter,
                    'overdue_change_amount' => $change > 0.004 ? $change : null,
                    'overdue_payment_method' => $overduePayMethod,
                    'overdue_payment_kind' => $kind,
                    'overdue_payment_received_by_user_id' => auth()->id(),
                    'overdue_payment_shift_session_id' => $overdueShiftId,
                    'overdue_paid_at' => now(),
                ])->save();

                // Unlike the rest of this method, this save is a direct
                // forceFill() outside loggableRentalChanges()'s normal diff —
                // log it explicitly so collecting an overdue payment leaves
                // the same audit trail every other payment does.
                $overduePaymentLogChanges = ['overdue_amount_paid' => [$paidBefore, $appliedPaidAfter]];
                if ($methodBefore !== $overduePayMethod) {
                    $overduePaymentLogChanges['overdue_payment_method'] = [$methodBefore, $overduePayMethod];
                }
                $this->activityLogClassFor(get_class($lockedRental))::record($lockedRental, 'updated', $overduePaymentLogChanges);

                RentalPaymentRecorder::recordIfNonZero(
                    $lockedRental,
                    $appliedPaidAfter - $paidBefore,
                    $overdueShiftId,
                    auth()->id(),
                    $overduePayMethod,
                    'overdue',
                );
            });
        } catch (\RuntimeException $e) {
            $msg = $e->getMessage() !== '' ? $e->getMessage() : 'Overdue payment failed.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['overdue_payment' => [$msg]]], 409)
                : back()->withInput()->withErrors(['overdue_payment' => $msg]);
        }

        $rental->refresh();
        $rental->load('outfits');

        if ($wantsJson) {
            $paymentChange = null;
            if ($pricingEnabled) {
                $dueAfter = (float) RentalFinancialSummary::summarize($rental, $this->pricingTaxPercent())['net_incl_tax'];
                $paidAfter = (float) ($rental->amount_paid ?? 0);
                $diff = round($paidAfter - $dueAfter, 2);
                if ($diff > 0.004) {
                    $paymentChange = $diff;
                }
            }

            // The edit just left the customer overpaid — the refund entry only RECORDS
            // that money is owed back, it never moves real cash, so whoever saved this
            // edit needs to see it and hand it over.
            $refundMessage = null;
            if ($refundInfo !== null) {
                $accountLabel = implode(', ', array_map(
                    fn ($a) => $a['code'].' — '.$a['name'].' (Rp '.number_format($a['amount'], 0, ',', '.').')',
                    $refundInfo['accounts']
                ));
                $refundMessage = 'The customer overpaid by Rp '.number_format($refundInfo['amount'], 0, ',', '.')
                    .', recorded as Rental Refunds from '.$accountLabel.'. Make sure the money is actually returned to the customer.';
            }

            return response()->json([
                'ok' => true,
                'message' => $refundMessage ?? ($pendingItemRemovalApproval
                    ? 'Rental updated. Item removal was sent for approval and is not yet applied.'
                    : 'Rental updated successfully.'),
                'payment_change' => $paymentChange,
                'overdue_payment_change' => $overduePaymentChange > 0.004 ? $overduePaymentChange : null,
                'overdue_payment_settled' => $overduePaymentSettled,
                'pending_item_removal_approval_id' => $pendingItemRemovalApproval?->id,
                'refund' => $refundInfo,
                'refund_message' => $refundMessage,
            ]);
        }

        return redirect()->route('rentals.index')->with('success', 'Rental updated successfully.');
    }

    /**
     * Reports a pickup/return that really happened but was never clicked:
     * when, and who handled it. Applied only after a supervisor/management
     * (not the requester) approves — see RentalLateDone.
     */
    public function requestLateDone(Request $request, Rental $rental): JsonResponse
    {
        if (! $request->user()?->mayOperateRentTransactions()) {
            return response()->json(['message' => 'This role cannot report pickups or returns.', 'ok' => false], 403);
        }
        $data = $request->validate([
            'type' => 'required|in:pickup,return',
            'picked_up_at' => [$rental->picked_up_at ? 'nullable' : 'required', 'date', 'before_or_equal:now'],
            'picked_up_by' => [$rental->picked_up_at ? 'nullable' : 'required', 'integer', 'exists:users,id'],
            'returned_at' => ['required_if:type,return', 'nullable', 'date', 'before_or_equal:now'],
            'returned_by' => ['required_if:type,return', 'nullable', 'integer', 'exists:users,id'],
            'note' => 'nullable|string|max:300',
        ]);
        $pickedAt = $rental->picked_up_at ?? \Illuminate\Support\Carbon::parse($data['picked_up_at']);
        if ($data['type'] === 'return' && \Illuminate\Support\Carbon::parse($data['returned_at'])->lt($pickedAt)) {
            return response()->json(['message' => 'The return time cannot be before the pickup time.', 'ok' => false, 'errors' => ['returned_at' => ['Before pickup.']]], 422);
        }
        if ($msg = \App\Support\RentalLateDone::blockMessage($rental, $data['type'])) {
            return response()->json(['message' => $msg, 'ok' => false], 422);
        }

        $approval = \App\Support\RentalLateDone::submit($rental, $request->user(), $data, $this->currentUserOpenShiftId());

        return response()->json([
            'ok' => true,
            'message' => 'Report sent. A supervisor or management will approve it; the rent is unchanged until then.',
            'approval_id' => $approval->id,
        ]);
    }

    /** Asks a supervisor/management to make an unpaid rent free (bill Rp 0). */
    public function requestFree(Request $request, Rental $rental): JsonResponse
    {
        if (! $request->user()?->mayOperateRentTransactions()) {
            return response()->json(['message' => 'This role cannot request a free rent.', 'ok' => false], 403);
        }
        $data = $request->validate(['reason' => 'required|string|min:3|max:500']);

        $msg = $rental->is_tutorial ? 'Practice rents cannot be made free.' : \App\Support\RentalFreeRequest::blockMessage($rental);
        if ($msg === null && \App\Support\RentalFreeRequest::pendingFor($rental)) {
            $msg = 'This rent already has a request waiting for approval.';
        }
        if ($msg !== null) {
            return response()->json(['message' => $msg, 'ok' => false], 422);
        }

        $approval = \App\Support\RentalFreeRequest::submit($rental, $request->user(), trim($data['reason']), $this->currentUserOpenShiftId());

        return response()->json([
            'ok' => true,
            'message' => 'Free request sent. A supervisor or management must approve it; the rent is unchanged until then.',
            'approval_id' => $approval->id,
        ]);
    }

    public function destroy(Request $request, Rental $rental): RedirectResponse|JsonResponse
    {
        $wantsJson = $request->expectsJson() || $request->ajax() || $request->wantsJson();
        $user = $request->user();

        // Whatever an edit request still waiting decides (items, discount, dates), it
        // should still find the rental it was made for. Delete it (or ask to) only
        // after that is resolved.
        if ($this->hasPendingEditRequest($rental)) {
            $message = 'This rent has an edit request waiting for approval. Wait for management to decide before deleting it.';

            return $wantsJson
                ? response()->json(['message' => $message, 'ok' => false], 422)
                : redirect()->route('rentals.index')->withErrors(['delete' => $message]);
        }

        // Deleting a paid rent wipes that money from the books, so a customer who
        // cancels goes through Cancel (kept or refunded). The one exception is a
        // rent typed in by mistake (the money never existed): with a written
        // reason, management deletes it; a supervisor asks management; admin cannot.
        $deleteReason = null;
        if ($message = self::paidRentalDeleteBlockMessage($rental)) {
            $paid = $rental->moneyReceived();
            $wrongInput = $request->boolean('wrong_input');
            $reason = trim((string) $request->input('reason', ''));
            $mayWrongInput = $user && ($user->isManagement() || $user->isSuperAdmin() || $user->isSupervisor());

            if (! $wrongInput || ! $mayWrongInput) {
                $message .= $mayWrongInput
                    ? ' If it was entered by mistake (that money was never received), delete it as "wrong input" with a reason.'
                    : ' If it was entered by mistake, ask a supervisor or management to delete it as "wrong input".';

                return $wantsJson
                    ? response()->json(['message' => $message, 'ok' => false, 'needs_wrong_input' => $mayWrongInput, 'paid' => $paid], 422)
                    : redirect()->route('rentals.index')->withErrors(['delete' => $message]);
            }
            if (mb_strlen($reason) < 3 || mb_strlen($reason) > 500) {
                $message = 'Write why this rent was entered by mistake.';

                return $wantsJson
                    ? response()->json(['message' => $message, 'ok' => false, 'errors' => ['reason' => [$message]]], 422)
                    : redirect()->route('rentals.index')->withErrors(['reason' => $message]);
            }

            if ($user->isSupervisor()) {
                if ($this->approvalClassFor($rental)::query()->where('status', 'pending')->where('payload->kind', 'delete')->where('payload->rental_id', $rental->id)->exists()) {
                    $message = 'This rent already has a delete request waiting.';

                    return $wantsJson
                        ? response()->json(['message' => $message, 'ok' => false], 422)
                        : redirect()->route('rentals.index')->withErrors(['delete' => $message]);
                }
                $deleteApproval = $this->approvalClassFor($rental)::create([
                    'requested_by_user_id' => $user->id,
                    'source_shift_session_id' => $this->currentUserOpenShiftId(),
                    'payload' => [
                        'kind' => 'delete',
                        'rental_id' => $rental->id,
                        'customer_name' => $rental->customer_name,
                        'wrong_input' => true,
                        'reason' => $reason,
                        'paid' => $paid,
                    ],
                ]);
                $this->activityLogClassFor(get_class($rental))::record($rental, 'delete_requested', [
                    'delete_reason' => [null, 'Wrong input: '.$reason],
                    'money_reversed' => [null, $paid],
                ], note: 'Approval #'.$deleteApproval->id.' · needs management', userId: $user->id);
                $msg = 'Delete request (wrong input) sent to management. The rent stays until they approve.';

                return $wantsJson
                    ? response()->json(['ok' => true, 'mode' => 'delete_approval_request_sent', 'message' => $msg, 'pending_delete_approval_id' => $deleteApproval->id])
                    : redirect()->route('rentals.index')->with('success', $msg);
            }

            $deleteReason = ['reason' => $reason, 'paid' => $paid];
        }

        // Admin never deletes directly — the request goes to supervisor/management
        // for approval instead, same pattern as the edit-approval gate. Replaces the
        // old hard "you can't delete" denial with an actual request they can make.
        if ($user && $user->isAdmin()) {
            if ($redirect = $this->rejectUnlessRentOperator($request)) {
                return $redirect;
            }

            $deleteApproval = $this->approvalClassFor($rental)::create([
                'requested_by_user_id' => $user->id,
                'source_shift_session_id' => $this->currentUserOpenShiftId(),
                'payload' => [
                    'kind' => 'delete',
                    'rental_id' => $rental->id,
                ],
            ]);
            $this->activityLogClassFor(get_class($rental))::record($rental, 'delete_requested', [], note: 'Approval #'.$deleteApproval->id, userId: $user->id);

            $msg = 'Deletion submitted for supervisor/management approval. The rental has not been deleted.';

            return $wantsJson
                ? response()->json(['ok' => true, 'mode' => 'delete_approval_request_sent', 'message' => $msg, 'pending_delete_approval_id' => $deleteApproval->id])
                : redirect()->route('rentals.index')->with('success', $msg);
        }

        if (! RentDeleteRestriction::userMayDeleteRentals($user)) {
            $message = RentDeleteRestriction::denyMessage();

            return $wantsJson
                ? response()->json(['message' => $message, 'ok' => false], 403)
                : redirect()->route('rentals.index')->withErrors(['delete' => $message]);
        }

        $privilegedDelete = $user && ($user->isManagement() || $user->isSupervisor() || $user->isSuperAdmin());
        if (! $privilegedDelete) {
            if ($redirect = $this->rejectUnlessRentOperator($request)) {
                return $redirect;
            }

            if ($redirect = $this->rejectUnlessOpenShift($request)) {
                return $redirect;
            }
        }

        // A practice rent has no payment records, journals or shifts of its own — those
        // lookups go by id number and would hit the real rent that shares the number.
        $isPractice = $rental instanceof TutorialRental;
        $affectedShiftIds = $isPractice ? [] : \App\Support\ShiftClosedTotals::shiftIdsForRental($rental);
        $this->activityLogClassFor(get_class($rental))::record($rental, 'deleted', $deleteReason ? [
            'delete_reason' => [null, 'Wrong input: '.$deleteReason['reason']],
            'money_reversed' => [null, $deleteReason['paid']],
        ] : []);
        $rental->delete();
        if (! $isPractice) {
            \App\Support\RentalRevenueRecorder::reverseForCancelledRental($rental, $user?->id);
            \App\Support\ShiftClosedTotals::recomputeClosedShifts($affectedShiftIds);
        }

        return $wantsJson
            ? response()->json(['ok' => true, 'message' => 'Rental deleted successfully.'])
            : redirect()->route('rentals.index')->with('success', 'Rental deleted successfully.');
    }

    /** Permanently remove selected soft-deleted rentals (management only). */
    public function bulkForceDelete(Request $request): JsonResponse
    {
        $ids = array_values(array_filter(array_map('intval', (array) $request->input('ids', [])), fn (int $id) => $id > 0));
        if ($ids === []) {
            return response()->json(['message' => 'No transactions selected.', 'ok' => false], 422);
        }

        $rentals = Rental::onlyTrashed()->whereIn('id', $ids)->get();
        $affectedShiftIds = [];
        foreach ($rentals as $rental) {
            $affectedShiftIds = array_merge($affectedShiftIds, \App\Support\ShiftClosedTotals::shiftIdsForRental($rental));
            $rental->forceDelete();
        }
        \App\Support\ShiftClosedTotals::recomputeClosedShifts($affectedShiftIds);

        return response()->json(['ok' => true, 'deleted' => $rentals->count()]);
    }

    /** Permanently remove all soft-deleted rentals (management only). */
    public function clearDeleted(): JsonResponse
    {
        $rentals = Rental::onlyTrashed()->get();
        $affectedShiftIds = [];
        foreach ($rentals as $rental) {
            $affectedShiftIds = array_merge($affectedShiftIds, \App\Support\ShiftClosedTotals::shiftIdsForRental($rental));
            $rental->forceDelete();
        }
        \App\Support\ShiftClosedTotals::recomputeClosedShifts($affectedShiftIds);

        return response()->json(['ok' => true, 'deleted' => $rentals->count()]);
    }

    public function updateStatus(Request $request, Rental $rental): RedirectResponse|JsonResponse
    {
        if ($redirect = $this->rejectUnlessRentOperator($request)) {
            return $redirect;
        }

        if ($redirect = $this->rejectUnlessOpenShift($request)) {
            return $redirect;
        }

        $wantsJson = $request->expectsJson() || $request->ajax() || $request->wantsJson();

        $request->validate([
            'status' => 'required|in:active,returned,cancelled',
        ]);

        $targetStatus = $request->status;

        $actor = $request->user();
        $restrictAdminDeletes = \App\Support\AdminDeleteRestriction::enabled();
        if ($targetStatus === 'cancelled' && $restrictAdminDeletes && $actor && $actor->isAdmin()) {
            $message = \App\Support\AdminDeleteRestriction::denyMessage();

            return $wantsJson
                ? response()->json(['message' => $message, 'errors' => ['status' => [$message]]], 403)
                : redirect()->route('rentals.index')->withErrors(['status' => $message]);
        }

        $prevStatus = $rental->status;
        if ($prevStatus === $targetStatus) {
            $message = 'Rental status is already '.$targetStatus.'.';

            return $wantsJson
                ? response()->json(['ok' => true, 'message' => $message, 'status' => $targetStatus])
                : redirect()->route('rentals.index')->with('success', $message);
        }

        // Which moves are allowed, and who may make them. Returned and cancelled
        // are end states: only management can reopen one (and reopening a
        // cancelled rental reverses what the cancellation booked). A returned
        // rental can never be cancelled, and a cancelled one has to be reopened
        // before it can be returned.
        // Returning or cancelling acts on the rental's current total — with an edit
        // still pending, that total can still change, so both wait for it to be
        // decided first. Reopening is not blocked: it only undoes something.
        if ($prevStatus === 'active' && in_array($targetStatus, ['returned', 'cancelled'], true) && $this->hasPendingEditRequest($rental)) {
            $message = 'This rent has an edit request waiting for approval. Wait for management to decide before '.($targetStatus === 'returned' ? 'returning it.' : 'cancelling it.');

            return $wantsJson
                ? response()->json(['message' => $message, 'errors' => ['status' => [$message]]], 422)
                : redirect()->route('rentals.index')->withErrors(['status' => $message]);
        }

        $mayReopen = $actor && ($actor->isManagement() || $actor->isSuperAdmin());
        // A misclicked Return has no ledger effect and staff do undo it within seconds
        // (seen in the data), so whoever just returned it may take it back for a short
        // while; after that it takes management.
        $mayUndoOwnReturn = false;
        if ($prevStatus === 'returned' && $targetStatus === 'active' && $actor && ! $mayReopen) {
            $last = $this->activityLogClassFor(get_class($rental))::withoutGlobalScopes()->where('rental_id', $rental->id)->where('action', 'status_changed')->latest('id')->first();
            $mayUndoOwnReturn = $last
                && (int) $last->user_id === (int) $actor->id
                && ($last->changes['status'][1] ?? null) === 'returned'
                && $last->created_at->gte(now()->subMinutes(30));
        }
        $deny = null;
        $denyStatus = 422;
        if ($prevStatus === 'active' && $targetStatus === 'returned') {
            $deny = $this->returnBlockReason($rental);
        } elseif ($prevStatus === 'returned' && $targetStatus === 'cancelled') {
            $deny = 'A returned rental cannot be cancelled. Ask management to correct it (delete) instead.';
        } elseif ($prevStatus === 'cancelled' && $targetStatus === 'returned') {
            $deny = 'A cancelled rental must be reopened by management before it can be returned.';
        } elseif (in_array($prevStatus, ['returned', 'cancelled'], true) && $targetStatus === 'active' && ! $mayReopen && ! $mayUndoOwnReturn) {
            $deny = $prevStatus === 'returned'
                ? 'Only management can reopen a returned rental (you can only undo your own return within 30 minutes).'
                : 'Only management can reopen a cancelled rental.';
            $denyStatus = 403;
        }
        if ($deny !== null) {
            return $wantsJson
                ? response()->json(['message' => $deny, 'errors' => ['status' => [$deny]]], $denyStatus)
                : redirect()->route('rentals.index')->withErrors(['status' => $deny]);
        }

        // Cancelling normally keeps the money (deposit forfeited). Refunding it is
        // management's call, made explicitly at cancel time.
        $refundOnCancel = $targetStatus === 'cancelled' && $request->boolean('refund');
        if ($refundOnCancel && ! $mayReopen) {
            $message = 'Only management can cancel with a refund.';

            return $wantsJson
                ? response()->json(['message' => $message, 'errors' => ['refund' => [$message]]], 403)
                : redirect()->route('rentals.index')->withErrors(['refund' => $message]);
        }

        // Prevent reactivating a rental when its items are already used by
        // another active rental in the same date window.
        if ($targetStatus === 'active' && $rental->status !== 'active') {
            $outfitIds = $rental->outfits()->pluck('outfits.id')->map(fn ($id) => (int) $id)->all();
            $conflicts = $this->hasDateConflicts(
                $outfitIds,
                $rental->start_date->toDateString(),
                $rental->end_date->toDateString(),
                $rental->id
            );

            if (! empty($conflicts)) {
                $message = 'Cannot reactivate rental. Items already booked: '.implode(', ', $conflicts);

                return $wantsJson
                    ? response()->json(['message' => $message, 'errors' => ['status' => [$message]]], 422)
                    : redirect()->route('rentals.index')->withErrors([
                        'status' => $message,
                    ]);
            }
        }

        $rental->update([
            'status' => $targetStatus,
            'handled_by_user_id' => auth()->id(),
        ]);
        $logChanges = ['status' => [$prevStatus, $targetStatus]];
        if ($targetStatus === 'cancelled') {
            $logChanges['cancel_policy'] = [null, $refundOnCancel ? 'refund' : 'forfeit'];
        }
        $this->activityLogClassFor(get_class($rental))::record($rental, 'status_changed', $logChanges);

        // Cancelling (unlike Delete) keeps the rental row around, so this is
        // the only place a rental can flip INTO cancelled from a non-cancelled
        // state — settle the deposit here, once per cancellation: forfeited by
        // default, or refunded when management chose so. A practice rent has no ledger of its own: the
        // recorder looks payments up by rental id, which would hit the real rent that shares the number.
        $isPractice = $rental instanceof TutorialRental;
        if (! $isPractice && $targetStatus === 'cancelled' && $prevStatus !== 'cancelled') {
            if ($refundOnCancel) {
                \App\Support\RentalRevenueRecorder::refundForCancelledRental($rental, $actor?->id);
            } else {
                \App\Support\RentalRevenueRecorder::forfeitForCancelledRental($rental, $actor?->id);
            }
        }

        // Reopening a cancelled rental undoes what its cancellation booked, then
        // brings the ledger's contract up to the rental's current total.
        if (! $isPractice && $prevStatus === 'cancelled' && $targetStatus === 'active') {
            \App\Support\RentalRevenueRecorder::reverseCancellation($rental, $actor?->id);
            \App\Support\RentalLedgerSync::reconcileRental($rental->fresh());
        }

        // Keep pickup state consistent: a returned/cancelled rental is not "out"; and
        // a returned one that is reopened WAS picked up (it could not be returned
        // otherwise), so it goes back as picked up instead of forcing a second pickup.
        if (in_array($targetStatus, ['returned', 'cancelled'], true)) {
            $rental->update(['picked_up_at' => null]);
        } elseif ($prevStatus === 'returned' && $targetStatus === 'active') {
            $rental->update(['picked_up_at' => now()]);
        }

        $successMsg = 'Rental status updated to '.$targetStatus.'.';

        return $wantsJson
            ? response()->json(['ok' => true, 'message' => $successMsg, 'status' => $targetStatus])
            : redirect()->route('rentals.index')->with('success', $successMsg);
    }

    /**
     * Why this rental may not be marked returned yet, or null when it may.
     * Returning is the last step: it must have been picked up, and be paid in
     * full (there is no way to collect money once it is returned). Its start
     * date is deliberately NOT checked — bookings are made weeks ahead, and a
     * wrong date must not stop anyone from closing a rental.
     */
    private function returnBlockReason(Rental $rental): ?string
    {
        if ($rental->picked_up_at === null) {
            return 'Cannot return: mark the rental as picked up first.';
        }

        $remaining = $this->unpaidRemainder($rental);
        if ($remaining > 0.004) {
            return 'Cannot return: not fully paid yet — Rp '.number_format($remaining, 0, ',', '.').' still to collect (edit the rent and record the payment).';
        }

        return null;
    }

    /**
     * What the customer still owes on this rental (rent plus any overdue fee that
     * was charged), or 0. A store that does not track prices never blocks anything.
     */
    private function unpaidRemainder(Rental $rental): float
    {
        if (! StoreCache::get('pricing_enabled', true)) {
            return 0.0;
        }

        $summary = RentalFinancialSummary::summarize($rental->loadMissing('outfits'), $this->pricingTaxPercent());
        $due = round((float) $summary['net_incl_tax'], 2);
        // Overdue change is cash handed back to the customer, not money received.
        $paid = round((float) ($rental->amount_paid ?? 0) + (float) ($rental->overdue_amount_paid ?? 0), 2);

        return max(0.0, round($due - $paid, 2));
    }

    public function updatePickup(Request $request, Rental $rental): RedirectResponse|JsonResponse
    {
        if ($redirect = $this->rejectUnlessRentOperator($request)) {
            return $redirect;
        }

        if ($redirect = $this->rejectUnlessOpenShift($request)) {
            return $redirect;
        }

        $wantsJson = $request->expectsJson() || $request->ajax() || $request->wantsJson();

        $request->validate([
            'picked_up' => 'required|boolean',
        ]);

        if ($rental->status !== 'active') {
            $message = 'Pickup status can only be changed for active rentals.';

            return $wantsJson
                ? response()->json(['message' => $message, 'errors' => ['pickup' => [$message]]], 422)
                : redirect()->route('rentals.index')->with('success', $message);
        }

        $pickedUp = (bool) $request->boolean('picked_up');
        $wasPickedUp = $rental->picked_up_at !== null;

        // The outfit only leaves the shop once the rental is paid in full, and its total
        // is settled — no edit still waiting that could still move it. (Taking the
        // pickup back is always allowed.) The way out: resolve the pending edit, or
        // edit the rent and record the payment.
        if ($pickedUp && ! $wasPickedUp) {
            if ($this->hasPendingEditRequest($rental)) {
                $message = 'This rent has an edit request waiting for approval. Wait for management to decide before marking it picked up.';

                return $wantsJson
                    ? response()->json(['message' => $message, 'errors' => ['pickup' => [$message]]], 422)
                    : redirect()->route('rentals.index')->withErrors(['pickup' => $message]);
            }

            $remaining = $this->unpaidRemainder($rental);
            if ($remaining > 0.004) {
                $message = 'Cannot mark as picked up: not fully paid yet — Rp '.number_format($remaining, 0, ',', '.').' still to collect (edit the rent and record the payment).';

                return $wantsJson
                    ? response()->json(['message' => $message, 'errors' => ['pickup' => [$message]]], 422)
                    : redirect()->route('rentals.index')->withErrors(['pickup' => $message]);
            }
        }
        $rental->update([
            'picked_up_at' => $pickedUp ? now() : null,
            'handled_by_user_id' => auth()->id(),
        ]);
        if ($wasPickedUp !== $pickedUp) {
            $this->activityLogClassFor(get_class($rental))::record($rental, 'pickup_changed', [
                'picked_up' => [$wasPickedUp, $pickedUp],
            ]);
        }

        $successMsg = $pickedUp ? 'Marked as picked up.' : 'Pickup status cleared.';

        return $wantsJson
            ? response()->json(['ok' => true, 'message' => $successMsg, 'picked_up' => $pickedUp])
            : redirect()->route('rentals.index')->with('success', $successMsg);
    }

    public function search(Request $request): JsonResponse
    {
        $now = $this->debugNow($request);
        $actor = $request->user();
        $privileged = $actor && ($actor->isManagement() || $actor->isSuperAdmin());
        // Only Management defaults to All Stores when the toggle hasn't been
        // touched — matches index()'s checkbox default exactly. Super Admin
        // (and everyone else) instead follows whichever store is selected in
        // the sidebar switcher, same as Admin/Supervisor, unless they
        // explicitly check "All stores" themselves.
        $allStores = $request->has('all_stores')
            ? (bool) $request->boolean('all_stores')
            : (bool) ($actor && $actor->isManagement());

        $deletedMode = $request->boolean('deleted') && $privileged;

        // Onboarding tour "pick a rental to edit" step: practice rentals live
        // in their own table (TutorialRental — see that class) with their own
        // id sequence, so this is a real branch to a different model, not a
        // tag filter — real-mode's store/allStores/deleted-mode logic below
        // doesn't apply to tutorial data at all (always just "this user's
        // own", regardless of role).
        $tutorialMode = $request->boolean('tutorial');
        // A direct by-ID lookup (edit modal deep-link, "My Requests" rent
        // link, etc.) should always be able to find the caller's OWN tutorial
        // rental even if the call site forgot `?tutorial=1` — otherwise every
        // such lookup silently returns nothing for practice data outside the
        // guided tour's own tutorial-mode fetches. Practice and real rents have separate id
        // sequences, so this guess is only safe when no real rent has that id: otherwise the
        // Edit button of a real rent opens the caller's practice rent with the same number.
        // Links to a practice rent therefore carry tutorial=1 (My Requests, edit modal refresh).
        if (! $tutorialMode && $actor && $request->filled('id')) {
            $tutorialMode = ! Rental::withoutGlobalScopes()->whereKey($request->id)->exists()
                && TutorialRental::query()
                    ->whereKey($request->id)
                    ->where('created_by_user_id', $actor->id)
                    ->exists();
        }

        if ($tutorialMode) {
            $query = TutorialRental::with(['outfits', 'handledBy', 'createdBy'])
                ->where('created_by_user_id', $actor?->id);
        } elseif ($privileged && $allStores) {
            $storeIds = $actor->accessibleActiveStoreIds();
            $query = Rental::withoutGlobalScope('store_scope')->with(['outfits', 'handledBy', 'createdBy']);
            if ($storeIds !== []) {
                $query->where(function ($q) use ($storeIds) {
                    $q->whereIn('store_id', $storeIds)->orWhereNull('store_id');
                });
            }
        } else {
            $query = Rental::with(['outfits', 'handledBy', 'createdBy']);
        }

        if ($deletedMode) {
            $query->onlyTrashed();
        }

        if ($request->filled('id')) {
            $query->whereKey($request->id);
        }

        if ($request->boolean('overdue')) {
            $query->where('status', 'active')
                ->whereDate('end_date', '<', $now->toDateString());
        } elseif ($request->boolean('pickup_waiting')) {
            $query->where('status', 'active')->whereNull('picked_up_at');
        } elseif ($request->boolean('pickup_done')) {
            $query->where('status', 'active')->whereNotNull('picked_up_at');
        } elseif ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('payment_kind') && in_array($request->payment_kind, ['down_payment', 'full', 'final_payment'], true)) {
            if ($request->payment_kind === 'full') {
                $query->whereIn('payment_kind', ['full', 'final_payment']);
            } else {
                $query->where('payment_kind', $request->payment_kind);
            }
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhereHas('outfits', fn ($sq) => $sq->where('code', 'like', "%{$search}%"));
            });
        }

        $dateFrom = $request->query('date_from');
        $dateTo = $request->query('date_to');
        if (is_string($dateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFrom)) {
            $query->whereDate('end_date', '>=', $dateFrom);
        }
        if (is_string($dateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateTo)) {
            $query->whereDate('start_date', '<=', $dateTo);
        }

        // Debug-only filter: match the day the rent was recorded (created_at),
        // unlike date_from/date_to which match the rental period.
        $createdOn = $request->query('created_on');
        if (\App\Support\DebugTools::enabled() && is_string($createdOn) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $createdOn)) {
            $query->whereDate('created_at', $createdOn);
        }

        $perPage = in_array($request->per_page, [10, 25, 50]) ? (int) $request->per_page : 10;
        $allSelected = ! $request->boolean('overdue')
            && ! $request->boolean('pickup_waiting')
            && ! $request->boolean('pickup_done')
            && ! $request->filled('status');

        if ($allSelected) {
            $query->orderByDesc('id');
        } else {
            $query->latest();
        }

        $rentals = $query->paginate($perPage);

        // Batched (not per-row) lookup of which of this page's rentals have
        // ever had an item removed via the removal-approval flow — an
        // approved removal never adjusts amount_paid, so a rental can end up
        // with more paid than its (now smaller) total owed. The card's
        // generic "Change" line fires on that same excess with no way to
        // tell it apart from ordinary checkout change; this flag lets it use
        // a distinct label instead (see card.blade.php).
        $activityLogClass = $this->activityLogClassFor($tutorialMode ? TutorialRental::class : Rental::class);
        // Keyed by rental_id -> latest item_removal_approved log's changes,
        // so the edit modal can show the change-vs-refund split it was
        // computed with at approval time, not just a yes/no flag.
        $latestRemovalLogByRentalId = $activityLogClass::whereIn('rental_id', $rentals->getCollection()->pluck('id'))
            ->where('action', 'item_removal_approved')
            ->orderByDesc('id')
            ->get(['rental_id', 'changes'])
            ->unique('rental_id')
            ->keyBy('rental_id');
        $rentalIdsWithApprovedRemoval = $latestRemovalLogByRentalId->keys()->flip();
        // Rents made free through an approved Free request: shown as a FREE badge
        // with who asked, who approved and why (card + edit modal).
        $freeByRentalId = $tutorialMode ? collect() : RentalApprovalRequest::query()
            ->where('status', 'approved')
            ->where('payload->kind', \App\Support\RentalFreeRequest::KIND)
            ->whereIn('payload->rental_id', $rentals->getCollection()->pluck('id')->all())
            ->with(['requestedBy:id,name', 'decidedBy:id,name'])
            ->orderByDesc('id')
            ->get()
            ->unique(fn ($a) => (int) ($a->payload['rental_id'] ?? 0))
            ->keyBy(fn ($a) => (int) ($a->payload['rental_id'] ?? 0));

        $shiftContext = $this->buildShiftControlContext($actor);
        $mayOperateRentTransactions = (bool) ($shiftContext['mayOperateRentTransactions'] ?? false);
        $rentalMutationsLocked = (bool) ($shiftContext['rentalMutationsLocked'] ?? true);
        $mayDeleteRentals = RentDeleteRestriction::userMayDeleteRentals($actor);
        $mayViewActivityLog = (bool) ($actor?->mayViewActivityLog() ?? false);

        $taxPercent = $this->pricingTaxPercent();
        $rentals->getCollection()->transform(function ($r) use ($taxPercent, $now, $mayOperateRentTransactions, $rentalMutationsLocked, $mayDeleteRentals, $mayViewActivityLog, $rentalIdsWithApprovedRemoval, $latestRemovalLogByRentalId, $freeByRentalId) {
            $pricingEnabled = StoreCache::get('pricing_enabled', true);

            if ($pricingEnabled) {
                $pricingSummary = RentalFinancialSummary::summarize($r, $taxPercent);
                $effectiveTotal = $pricingSummary['net_incl_tax'];
                $billingDays = $pricingSummary['rental_days'];
                $billingBlocks = $pricingSummary['rental_blocks'] ?? intdiv(max(3, $billingDays), 3);
                $extraDays = $pricingSummary['extra_days'];
                $extraCharge = $pricingSummary['extra_charge'];
                $pricingMode = $pricingSummary['pricing_mode'];
                $outfitsData = $r->outfits->map(function ($s) {
                    $pricesIncludeTax = (bool) StoreCache::get('pricing_prices_include_tax', false);
                    $price = $pricesIncludeTax
                        ? ($s->pivot->price_incl_tax ?? $s->price ?? 0)
                        : ($s->pivot->price_excl_tax ?? $s->price ?? 0);
                    $discount = (float) ($s->pivot->discount ?? 0);
                    $discountType = $s->pivot->discount_type ?? 'fixed';

                    return [
                        'id' => $s->id,
                        'code' => $s->code,
                        'new_code' => $s->new_code,
                        'description' => $s->description,
                        'stock' => $s->stock,
                        'price' => $price,
                        'pivot_price_incl_tax' => $s->pivot->price_incl_tax ?? null,
                        'pivot_price_excl_tax' => $s->pivot->price_excl_tax ?? null,
                        'pivot_tax_amount' => $s->pivot->tax_amount ?? null,
                        'pivot_discount' => $discount,
                        'pivot_discount_type' => $discountType,
                        'pivot_discount_note' => $s->pivot->discount_note ?? null,
                    ];
                })->values()->all();
            } else {
                $outfitsData = $r->outfits->map(fn ($s) => [
                    'id' => $s->id,
                    'code' => $s->code,
                    'new_code' => $s->new_code,
                    'description' => $s->description,
                    'stock' => $s->stock,
                    'price' => null,
                    'pivot_discount' => 0,
                    'pivot_discount_type' => 'fixed',
                ])->values()->all();
                $effectiveTotal = 0;
                $billingDays = null;
                $extraDays = 0;
                $extraCharge = null;
                $pricingMode = 'block';
            }

            $overdueSeconds = 0;
            $overdueHuman = null;
            if ($r->status === 'active') {
                $overdueSeconds = OverdueDuration::overdueSecondsForEndDate($r->end_date, $now);
                if ($overdueSeconds > 0) {
                    $overdueHuman = OverdueDuration::formatSeconds($overdueSeconds);
                } elseif ($r->end_date->lt($now)) {
                    $days = max(1, $r->end_date->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()));
                    $overdueHuman = $days.' day'.($days === 1 ? '' : 's').' overdue';
                }
            }

            $removalLogChanges = optional($latestRemovalLogByRentalId->get($r->id))->changes ?? [];
            $row = [
                'id' => $r->id,
                'is_tutorial' => (bool) $r->is_tutorial,
                'has_item_removal_approved' => isset($rentalIdsWithApprovedRemoval[$r->id]),
                'free' => ($free = $freeByRentalId->get($r->id)) ? [
                    'approved_by' => $free->decidedBy?->name,
                    'requested_by' => $free->requestedBy?->name,
                    'reason' => (string) ($free->payload['reason'] ?? ''),
                    'approved_at' => $free->decided_at?->format('d M Y'),
                    'rent_total_before' => (float) ($free->payload['rent_total_before'] ?? 0),
                    'refund_amount' => (float) ($free->payload['refund_amount'] ?? 0),
                ] : null,
                'item_removal_change_amount' => $removalLogChanges['item_removal_change_amount'][1] ?? null,
                'item_removal_refund_amount' => $removalLogChanges['item_removal_refund_amount'][1] ?? null,
                'outfits' => $outfitsData,
                'outfit_list' => $r->outfits->map(fn ($s) => $s->code.($s->new_code ? ' | '.$s->new_code : ''))->toArray(),
                'outfit_codes' => $r->outfits->map(fn ($s) => $s->code.($s->new_code ? ' | '.$s->new_code : ''))->implode('<br>'),
                'outfit_prices' => [],
                'total_price' => max(0, $effectiveTotal),
                'billing_days' => $billingDays ?? null,
                'billing_blocks' => $billingBlocks ?? 1,
                'extra_days' => $extraDays ?? 0,
                'extra_charge' => $extraCharge,
                'pricing_mode' => $pricingMode ?? 'block',
                'outfit_ids' => $r->outfits->pluck('id')->toArray(),
                'customer_name' => $r->customer_name,
                'phone' => $r->phone,
                'instagram' => $r->instagram,
                'notes' => $r->notes,
                'payment_method' => $r->payment_method,
                'amount_paid' => $r->amount_paid !== null ? (float) $r->amount_paid : null,
                'down_payment_amount' => $r->down_payment_amount !== null ? (float) $r->down_payment_amount : null,
                'payment_kind' => $r->payment_kind,
                'overdue_amount_paid' => $r->overdue_amount_paid !== null ? (float) $r->overdue_amount_paid : null,
                'overdue_change_amount' => $r->overdue_change_amount !== null ? (float) $r->overdue_change_amount : null,
                'overdue_payment_method' => $r->overdue_payment_method,
                'overdue_payment_kind' => $r->overdue_payment_kind,
                'overdue_paid_at' => $r->overdue_paid_at?->toIso8601String(),
                'handled_by_name' => $r->handledBy?->name,
                'handled_by_role' => $r->handledBy?->role,
                'created_by_name' => $r->createdBy?->name,
                'created_by_role' => $r->createdBy?->role,
                'discount' => $r->discount !== null ? (float) $r->discount : null,
                'discount_type' => $r->discount_type,
                'discount_note' => $r->discount_note,
                'created_at' => $r->created_at?->format('Y-m-d'),
                'created_label' => $r->created_at?->format('d M Y H:i'),
                'start_date' => $r->start_date->format('Y-m-d'),
                'start_label' => $r->start_date->format('d M Y'),
                'end_date' => $r->end_date->format('Y-m-d'),
                'end_label' => $r->end_date->format('d M Y'),
                'overdue_base_end_date' => $r->overdue_base_end_date?->format('Y-m-d'),
                'overdue_charge_through_date' => $r->overdue_charge_through_date?->format('Y-m-d'),
                'overdue_fee_rate' => $r->overdue_fee_rate !== null ? (float) $r->overdue_fee_rate : null,
                'overdue_fee_days' => $r->overdue_fee_days !== null ? (int) $r->overdue_fee_days : null,
                'overdue_fee_amount' => $r->overdue_fee_amount !== null ? (float) $r->overdue_fee_amount : null,
                'overdue_fee_applied_at' => $r->overdue_fee_applied_at?->toIso8601String(),
                'status' => $r->status,
                'picked_up' => ! is_null($r->picked_up_at),
                'picked_up_at' => $r->picked_up_at?->format('Y-m-d H:i:s'),
                'overdue' => $r->status === 'active' && $r->end_date->lt($now),
                'overdue_seconds' => $overdueSeconds,
                'overdue_human' => $overdueHuman,
                'deleted_at' => $r->deleted_at?->format('Y-m-d H:i:s'),
            ];

            $clientRow = $row;

            $row['actions_html'] = view('rentals.partials.action-links', [
                'rental' => $clientRow,
                'rentalData' => $clientRow,
                'mayOperateRentTransactions' => $mayOperateRentTransactions,
                'mayDeleteRentals' => $mayDeleteRentals,
                'rentalMutationsLocked' => $rentalMutationsLocked,
                'mayViewActivityLog' => $mayViewActivityLog,
            ])->render();

            $row['card_html'] = view('rentals.partials.card', [
                'rental' => $clientRow,
                'rentalData' => $clientRow,
                'mayOperateRentTransactions' => $mayOperateRentTransactions,
                'mayDeleteRentals' => $mayDeleteRentals,
                'rentalMutationsLocked' => $rentalMutationsLocked,
                'mayViewActivityLog' => $mayViewActivityLog,
            ])->render();

            return $row;
        });

        $payload = $rentals->toArray();
        $payload['pagination_links_html'] = view('rentals.partials.pagination-links', [
            'currentPage' => $rentals->currentPage(),
            'lastPage' => $rentals->lastPage(),
        ])->render();
        $payload['pending_approval_rows'] = $this->pendingApprovalRowsForRentalsList($request);

        return response()->json($payload);
    }

    public function detailFragment(Request $request): JsonResponse
    {
        $code = trim((string) $request->query('code', ''));
        if ($code === '') {
            return response()->json(['ok' => false, 'message' => 'No item code provided.']);
        }

        $list = Rental::query()
            ->with('outfits')
            ->where('status', 'active')
            ->whereHas('outfits', function ($q) use ($code) {
                $q->where('code', 'like', "%{$code}%")
                    ->orWhere('new_code', 'like', "%{$code}%");
            })
            ->latest()
            ->limit(10)
            ->get();

        $startYmd = (string) $request->query('start', '');
        $endYmd = (string) $request->query('end', '');
        $hasRange = (bool) (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startYmd) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $endYmd));

        $found = null;
        if ($hasRange) {
            $rs = Carbon::parse($startYmd)->startOfDay();
            $re = Carbon::parse($endYmd)->startOfDay();
            foreach ($list as $r) {
                if ($r->start_date->lte($re) && $r->end_date->gte($rs)) {
                    $found = $r;
                    break;
                }
            }
        } elseif ($list->count() === 1) {
            $found = $list->first();
        }

        if (! $found && $list->count() > 1) {
            $rows = $list->map(fn (Rental $r) => [
                'id' => $r->id,
                'customer_name' => $r->customer_name,
                'start_label' => $r->start_date->format('d M Y'),
                'end_label' => $r->end_date->format('d M Y'),
            ])->values()->all();

            return response()->json([
                'ok' => true,
                'mode' => 'multi',
                'html' => view('outfits.partials.rental-detail-multi', ['rentals' => $rows])->render(),
            ]);
        }

        if ($found) {
            $outfitList = $found->outfits
                ->map(fn ($s) => $s->code.($s->new_code ? ' | '.$s->new_code : ''))
                ->values()
                ->all();

            $row = [
                'id' => $found->id,
                'customer_name' => $found->customer_name,
                'start_label' => $found->start_date->format('d M Y'),
                'end_label' => $found->end_date->format('d M Y'),
                'outfit_list' => $outfitList,
                'phone' => $found->phone,
                'instagram' => $found->instagram,
            ];

            return response()->json([
                'ok' => true,
                'mode' => 'single',
                'html' => view('outfits.partials.rental-detail-single', ['rental' => $row])->render(),
            ]);
        }

        return response()->json([
            'ok' => false,
            'message' => 'No active rent found for this item'.($hasRange ? ' in the selected date range.' : '.'),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pendingApprovalRowsForRentalsList(Request $request): array
    {
        $user = $request->user();
        if (! $user || $request->filled('id')) {
            return [];
        }

        if ($request->filled('status') || $request->boolean('overdue')
            || $request->boolean('pickup_waiting') || $request->boolean('pickup_done') || $request->filled('payment_kind')) {
            return [];
        }

        if ($user->isManagement() || $user->isSuperAdmin()) {
            $storeIds = $user->accessibleActiveStoreIds();
            $query = RentalApprovalRequest::withoutGlobalScope('store_scope')
                ->where('status', 'pending')
                ->whereIn('store_id', $storeIds)
                ->with('requestedBy')
                ->latest()
                ->limit(100);
        } else {
            $query = RentalApprovalRequest::query()
                ->where('status', 'pending')
                ->where('requested_by_user_id', $user->id)
                ->with('requestedBy')
                ->latest()
                ->limit(100);
        }

        $items = $query->get();

        $outfitIdsForBatch = $items
            ->flatMap(fn (RentalApprovalRequest $a) => $a->payload['outfit_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->filter()
            ->values()
            ->all();

        /** @var Collection<int, string> $codeById */
        $codeById = $outfitIdsForBatch === []
            ? collect()
            : Outfit::query()->whereIn('id', $outfitIdsForBatch)->pluck('code', 'id');

        if ($request->filled('search')) {
            $term = mb_strtolower(trim((string) $request->search));
            if ($term !== '') {
                $items = $items
                    ->filter(fn (RentalApprovalRequest $a) => $this->pendingApprovalMatchesSearch($a, $term, $codeById))
                    ->values();
            }
        }

        return $items
            ->map(fn (RentalApprovalRequest $a) => $this->pendingApprovalToListRow($a, $codeById))
            ->all();
    }

    private function pendingApprovalMatchesSearch(RentalApprovalRequest $approval, string $term, Collection $codeById): bool
    {
        $payload = $approval->payload ?? [];
        $r = $payload['rental'] ?? [];
        $parts = [
            mb_strtolower((string) ($r['customer_name'] ?? '')),
            mb_strtolower((string) ($r['phone'] ?? '')),
            mb_strtolower((string) ($r['notes'] ?? $r['address'] ?? '')),
        ];

        foreach (array_map('intval', $payload['outfit_ids'] ?? []) as $sid) {
            if ($sid <= 0) {
                continue;
            }
            $parts[] = mb_strtolower((string) ($codeById->get($sid) ?? ''));
        }

        $haystack = implode(' ', array_filter($parts));

        return $haystack !== '' && str_contains($haystack, $term);
    }

    /**
     * @param  Collection<int, string>  $codeById
     * @return array<string, mixed>
     */
    private function pendingApprovalToListRow(RentalApprovalRequest $approval, Collection $codeById): array
    {
        $row = $approval->summaryForJson($codeById);
        $row['row_kind'] = 'pending_approval';
        $row['requested_by_name'] = $approval->requestedBy?->name;
        $row['created_label'] = $approval->created_at?->diffForHumans() ?? '';
        $row['can_cancel'] = (int) $approval->requested_by_user_id === (int) auth()->id();
        $row['cancel_url'] = route('approvals.rentals.cancel', $approval);
        $row['conflict_lines'] = $approval->cooldownConflictLines();

        $sd = (string) ($row['start_date'] ?? '');
        $ed = (string) ($row['end_date'] ?? '');
        $row['start_label'] = $sd !== '' ? Carbon::parse($sd)->format('d M Y') : '—';
        $row['end_label'] = $ed !== '' ? Carbon::parse($ed)->format('d M Y') : '—';

        $payload = $approval->payload ?? [];
        $kind = (string) ($payload['kind'] ?? 'create');
        $editRentalId = isset($payload['rental_id']) ? (int) $payload['rental_id'] : null;
        $row['approval_kind'] = $kind;
        $row['target_rental_id'] = $editRentalId > 0 ? $editRentalId : null;
        $row['saved_start_label'] = null;
        $row['saved_end_label'] = null;
        if ($kind === 'edit' && $editRentalId > 0) {
            $saved = Rental::withoutGlobalScope('tutorial_scope')->find($editRentalId);
            if ($saved && $saved->status === 'active') {
                $row['saved_start_label'] = $saved->start_date->format('d M Y');
                $row['saved_end_label'] = $saved->end_date->format('d M Y');
            }
        }

        $outfits = [];
        $ids = $row['outfit_ids'] ?? [];
        $codes = $row['outfit_codes'] ?? [];
        foreach ($ids as $i => $sid) {
            $outfits[] = [
                'id' => (int) $sid,
                'code' => $codes[$i] ?? '#'.$sid,
                'new_code' => null,
                'description' => null,
                'stock' => null,
                'price' => null,
                'pivot_discount' => 0,
                'pivot_discount_type' => 'fixed',
            ];
        }
        $row['outfits'] = $outfits;
        $row['outfit_list'] = $codes;
        $row['total_price'] = 0;
        $row['overdue'] = false;
        $row['picked_up'] = false;

        $row['card_html'] = view('rentals.partials.pending-approval-card', [
            'row' => $row,
        ])->render();

        return $row;
    }

    public function bulkImport(Request $request): RedirectResponse
    {
        if ($redirect = $this->rejectUnlessRentOperator($request)) {
            return $redirect;
        }

        if ($redirect = $this->rejectUnlessOpenShift($request)) {
            return $redirect;
        }

        $request->validate([
            'data' => 'required|string|max:100000',
        ]);

        $lines = explode("\n", $request->data);
        $created = 0;
        $totalItems = 0;
        $errors = [];

        $bulkHandlerId = auth()->id();
        $bulkShiftId = ShiftSession::where('opened_by_user_id', $bulkHandlerId)
            ->whereNull('closed_at')
            ->latest('opened_at')
            ->value('id');

        // Build outfit lookup by code and new_code (BR 03 matches BR03 / br03)
        $allOutfits = Outfit::all();
        $outfitByCode = [];
        foreach ($allOutfits as $s) {
            $this->registerBulkImportOutfitLookupKeys($s, $outfitByCode);
        }

        $columnMap = null;
        $pendingRental = [];
        $taxPercent = $this->pricingTaxPercent();
        $taxRate = max(0.0, $taxPercent / 100);
        $pricesIncludeTax = (bool) StoreCache::get('pricing_prices_include_tax', false);

        foreach ($lines as $i => $line) {
            $raw = trim($line);
            if ($raw === '') {
                continue;
            }

            $cols = explode("\t", $raw);
            if (count($cols) < 4) {
                $cols = explode(',', $raw);
            }
            if (count($cols) < 4) {
                $errors[] = 'insufficient columns: '.mb_substr($raw, 0, 60);

                continue;
            }

            // Detect header row
            $lowerFirst = strtolower(trim($cols[0]));
            $knownHeaders = ['id booking', 'nama customer', 'no hp', 'pengambilan', 'pengembalian', 'sku/item', 'warna', 'size', 'tgl transaksi', 'new code', 'status', 'notes'];
            $headerScore = 0;
            foreach ($cols as $c) {
                if (in_array(strtolower(trim($c)), $knownHeaders)) {
                    $headerScore++;
                }
            }

            if ($i === 0 && $headerScore >= 3) {
                // Build column map from header
                $headerIndex = 0;
                $colNames = array_map(fn ($c) => strtolower(trim($c)), $cols);
                $columnMap = [
                    'customer' => array_search('nama customer', $colNames) ?: array_search('nama', $colNames),
                    'phone' => array_search('no hp', $colNames),
                    'start' => array_search('pengambilan', $colNames),
                    'end' => array_search('pengembalian', $colNames),
                    'outfit' => array_search('sku/item', $colNames),
                    'new_code' => array_search('new code', $colNames),
                    'status' => array_search('status', $colNames),
                    'tgl' => array_search('tgl transaksi', $colNames),
                ];

                continue;
            }

            // Use column map if available, else fallback to positional
            if ($columnMap) {
                $customerName = isset($columnMap['customer']) && $columnMap['customer'] !== false ? trim($cols[$columnMap['customer']] ?? '') : '';
                $phone = isset($columnMap['phone']) && $columnMap['phone'] !== false ? trim($cols[$columnMap['phone']] ?? '') : '';
                $startDate = isset($columnMap['start']) && $columnMap['start'] !== false ? $this->parseDate(trim($cols[$columnMap['start']] ?? '')) : null;
                $endDate = isset($columnMap['end']) && $columnMap['end'] !== false ? $this->parseDate(trim($cols[$columnMap['end']] ?? '')) : null;
                $outfitCode = isset($columnMap['outfit']) && $columnMap['outfit'] !== false ? trim($cols[$columnMap['outfit']] ?? '') : '';
                $newCodeVal = isset($columnMap['new_code']) && $columnMap['new_code'] !== false ? trim($cols[$columnMap['new_code']] ?? '') : '';
                $statusVal = isset($columnMap['status']) && $columnMap['status'] !== false ? strtolower(trim($cols[$columnMap['status']] ?? '')) : '';
                $tglVal = isset($columnMap['tgl']) && $columnMap['tgl'] !== false ? $this->parseDate(trim($cols[$columnMap['tgl']] ?? '')) : null;

                // If SKU/ITEM is empty, try NEW CODE column
                if (empty($outfitCode) && ! empty($newCodeVal)) {
                    $outfitCode = $newCodeVal;
                }
            } else {
                $offset = 0;
                $first0 = strtolower(trim($cols[0] ?? ''));
                if (count($cols) >= 6 && (empty($first0) || str_starts_with($first0, 'nik') || str_starts_with($first0, 'nisn') || ctype_digit(str_replace(' ', '', $first0)))) {
                    $offset = 1;
                }
                $customerName = trim($cols[0 + $offset] ?? '');
                $phone = trim($cols[1 + $offset] ?? '');
                $startDate = $this->parseDate(trim($cols[2 + $offset] ?? ''));
                $endDate = $this->parseDate(trim($cols[3 + $offset] ?? ''));
                $outfitCode = trim($cols[4 + $offset] ?? '');
                // Fallback: if SKU/ITEM is empty, try NEW CODE (12) or second SKU/ITEM (13) in absolute position
                if (empty($outfitCode)) {
                    foreach ([12, 13] as $pos) {
                        if ($pos < count($cols) && ! empty(trim($cols[$pos] ?? '')) && trim($cols[$pos]) !== '-') {
                            $outfitCode = trim($cols[$pos]);
                            break;
                        }
                    }
                }

                $statusVal = '';
                $tglVal = null;
            }

            // Continuation row: only outfit code provided (merged CSV cell), attach to previous rental
            if (empty($customerName) && empty($startDate) && empty($endDate) && ! empty($outfitCode)) {
                $outfit = $this->findOutfitForBulkImport($outfitCode, $outfitByCode);
                if (! $outfit) {
                    $outfit = Outfit::create(['code' => $outfitCode, 'description' => '', 'stock' => 1]);
                    $this->registerBulkImportOutfitLookupKeys($outfit, $outfitByCode);
                }
                $lastRental = ! empty($pendingRental) ? end($pendingRental) : null;
                if ($lastRental) {
                    $raw = (float) ($outfit->price ?? 0);
                    if ($pricesIncludeTax) {
                        $unitIncl = $raw;
                        $unitExcl = $taxRate > 0 ? round($unitIncl / (1 + $taxRate), 2) : $unitIncl;
                        $unitTax = round($unitIncl - $unitExcl, 2);
                    } else {
                        $unitExcl = $raw;
                        $unitTax = round($unitExcl * $taxRate, 2);
                        $unitIncl = round($unitExcl + $unitTax, 2);
                    }
                    $lastRental->outfits()->attach([$outfit->id => [
                        'discount' => null,
                        'discount_type' => 'fixed',
                        'price_incl_tax' => $unitIncl,
                        'price_excl_tax' => $unitExcl,
                        'tax_amount' => $unitTax,
                    ]]);
                    $totalItems++;
                } else {
                    $errors[] = "? – no previous rental to attach outfit '{$outfitCode}' to";
                }

                continue;
            }

            if (empty($customerName) || empty($startDate) || empty($endDate) || empty($outfitCode)) {
                $missing = [];
                if (empty($customerName)) {
                    $missing[] = 'name';
                }
                if (empty($startDate)) {
                    $missing[] = 'start date';
                }
                if (empty($endDate)) {
                    $missing[] = 'end date';
                }
                if (empty($outfitCode)) {
                    $missing[] = 'outfit code';
                }
                $errors[] = ($customerName ?: '?').' – missing '.implode(', ', $missing).' → '.mb_substr($raw, 0, 60);

                continue;
            }

            // Find outfit by code or new_code; auto-create if not found
            $outfit = $this->findOutfitForBulkImport($outfitCode, $outfitByCode);
            if (! $outfit) {
                $outfit = Outfit::create(['code' => $outfitCode, 'description' => '', 'stock' => 1]);
                $this->registerBulkImportOutfitLookupKeys($outfit, $outfitByCode);
            }

            // Map status
            $status = 'active';
            if (! empty($statusVal)) {
                if (in_array($statusVal, ['returned', 'kembali', 'done'])) {
                    $status = 'returned';
                } elseif (in_array($statusVal, ['cancelled', 'batal', 'cancel'])) {
                    $status = 'cancelled';
                }
            }

            // Bundling: if same customer+phone+dates as previous, attach to same rental
            $bundleKey = $customerName.'|'.$phone.'|'.$startDate.'|'.$endDate;
            if (isset($pendingRental[$bundleKey])) {
                $raw = (float) ($outfit->price ?? 0);
                if ($pricesIncludeTax) {
                    $unitIncl = $raw;
                    $unitExcl = $taxRate > 0 ? round($unitIncl / (1 + $taxRate), 2) : $unitIncl;
                    $unitTax = round($unitIncl - $unitExcl, 2);
                } else {
                    $unitExcl = $raw;
                    $unitTax = round($unitExcl * $taxRate, 2);
                    $unitIncl = round($unitExcl + $unitTax, 2);
                }
                $pendingRental[$bundleKey]->outfits()->attach([$outfit->id => [
                    'discount' => null,
                    'discount_type' => 'fixed',
                    'price_incl_tax' => $unitIncl,
                    'price_excl_tax' => $unitExcl,
                    'tax_amount' => $unitTax,
                ]]);
                $totalItems++;
            } else {
                $data = [
                    'customer_name' => $customerName,
                    'phone' => $phone,
                    'instagram' => null,
                    'notes' => '-',
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'status' => $status,
                    'handled_by_user_id' => $bulkHandlerId,
                    'created_by_user_id' => $bulkHandlerId ?? auth()->id(),
                    'shift_session_id' => $bulkShiftId,
                ];

                if ($tglVal) {
                    $data['created_at'] = $tglVal;
                    $data['updated_at'] = $tglVal;
                }

                $rental = Rental::create($data);
                $raw = (float) ($outfit->price ?? 0);
                if ($pricesIncludeTax) {
                    $unitIncl = $raw;
                    $unitExcl = $taxRate > 0 ? round($unitIncl / (1 + $taxRate), 2) : $unitIncl;
                    $unitTax = round($unitIncl - $unitExcl, 2);
                } else {
                    $unitExcl = $raw;
                    $unitTax = round($unitExcl * $taxRate, 2);
                    $unitIncl = round($unitExcl + $unitTax, 2);
                }
                $rental->outfits()->attach([$outfit->id => [
                    'discount' => null,
                    'discount_type' => 'fixed',
                    'price_incl_tax' => $unitIncl,
                    'price_excl_tax' => $unitExcl,
                    'tax_amount' => $unitTax,
                ]]);
                $pendingRental[$bundleKey] = $rental;
                $created++;
                $totalItems++;
            }
        }

        $msg = "{$created} rental(s) created successfully ({$totalItems} item(s) total).";
        if (! empty($errors)) {
            $msg .= '<br><br>Errors:<br>';
            foreach (array_slice($errors, 0, 100) as $err) {
                $msg .= '• '.e($err).'<br>';
            }
            if (count($errors) > 100) {
                $msg .= '• ... and '.(count($errors) - 100).' more error(s)';
            }
        }

        return redirect()->route('rentals.index')->with('success', $msg);
    }

    /**
     * Lowercased keys used to match pasted bulk codes so "BR 03", "BR03", and extra spaces map to one outfit.
     *
     * @return list<string>
     */
    private function bulkImportOutfitLookupKeys(string $code): array
    {
        $t = strtolower(trim($code));
        if ($t === '') {
            return [];
        }
        $keys = [$t];
        $noSpaces = preg_replace('/\s+/u', '', $t);
        if ($noSpaces !== '' && $noSpaces !== $t) {
            $keys[] = $noSpaces;
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, Outfit>  $outfitByCode
     */
    private function registerBulkImportOutfitLookupKeys(Outfit $outfit, array &$outfitByCode): void
    {
        foreach ([$outfit->code, $outfit->new_code] as $c) {
            if ($c === null || trim((string) $c) === '') {
                continue;
            }
            foreach ($this->bulkImportOutfitLookupKeys((string) $c) as $k) {
                $outfitByCode[$k] = $outfit;
            }
        }
    }

    /**
     * @param  array<string, Outfit>  $outfitByCode
     */
    private function findOutfitForBulkImport(string $outfitCode, array $outfitByCode): ?Outfit
    {
        foreach ($this->bulkImportOutfitLookupKeys($outfitCode) as $k) {
            if (isset($outfitByCode[$k])) {
                return $outfitByCode[$k];
            }
        }

        return null;
    }

    private function parseDate(string $raw): ?string
    {
        if (empty($raw)) {
            return null;
        }
        $raw = trim($raw);

        // Format: "7 Mei 2026"
        $months = [
            'januari' => '01', 'februari' => '02', 'maret' => '03', 'april' => '04',
            'mei' => '05', 'juni' => '06', 'juli' => '07', 'agustus' => '08',
            'september' => '09', 'oktober' => '10', 'november' => '11', 'desember' => '12',
        ];

        foreach ($months as $id => $num) {
            if (preg_match('/\b(\d+)\s*'.$id.'\s*(\d{4})\b/i', $raw, $m)) {
                return sprintf('%04d-%02d-%02d', $m[2], $num, $m[1]);
            }
            // Short form: "7 Mei" (no year, use current)
            if (preg_match('/\b(\d+)\s*'.$id.'\b/i', $raw, $m)) {
                return date('Y').'-'.$num.'-'.sprintf('%02d', $m[1]);
            }
        }

        // Try YYYY-MM-DD or DD-MM-YYYY
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        if (preg_match('/^(\d{2})-(\d{2})-(\d{4})$/', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $raw, $m)) {
            return "{$m[3]}-{$m[2]}-{$m[1]}";
        }

        return null;
    }

    private function hasDateConflicts(array $outfitIds, string $startDate, string $endDate, ?int $excludeId = null, array $allowedTakeovers = []): array
    {
        $conflicts = [];
        $bufferDays = $this->rentalReturnBufferDays();
        $parsedEnd = Carbon::parse($endDate);
        $parsedStart = Carbon::parse($startDate);
        foreach ($outfitIds as $outfitId) {
            $query = Rental::whereHas('outfits', fn ($q) => $q->where('outfits.id', $outfitId))
                ->where('status', 'active')
                ->where('start_date', '<=', $parsedEnd->copy()->addDays($bufferDays))
                ->where('end_date', '>=', $parsedStart->copy()->subDays($bufferDays));

            if ($excludeId !== null) {
                $query->where('id', '!=', $excludeId);
            }

            $conflictIds = $query->pluck('id')->map(fn ($id) => (int) $id);
            if (Schema::hasColumn('rentals', 'outfit_id')) {
                $fallbackQuery = Rental::where('outfit_id', $outfitId)
                    ->where('status', 'active')
                    ->where('start_date', '<=', $parsedEnd->copy()->addDays($bufferDays))
                    ->where('end_date', '>=', $parsedStart->copy()->subDays($bufferDays));
                if ($excludeId !== null) {
                    $fallbackQuery->where('id', '!=', $excludeId);
                }
                $fallbackIds = $fallbackQuery->pluck('id')->map(fn ($id) => (int) $id);
                $conflictIds = $conflictIds->merge($fallbackIds)->unique()->values();
            }

            if (isset($allowedTakeovers[$outfitId])) {
                $allowedRentalId = (int) $allowedTakeovers[$outfitId];
                $conflictIds = $conflictIds->reject(fn ($id) => (int) $id === $allowedRentalId);
            }

            // Editing an existing rental: sequential cool-down bookings (approved or manager-created)
            // still appear in the buffer-expanded window; they must not block saving like a hard double-book.
            if ($excludeId !== null && $bufferDays > 0) {
                $conflictIds = $conflictIds
                    ->filter(function (int $rid) use ($startDate, $endDate, $bufferDays) {
                        $other = Rental::find($rid);
                        if (! $other) {
                            return false;
                        }

                        return ! $this->rentalIsCooldownBufferTouchOnly($other, $startDate, $endDate, $bufferDays);
                    })
                    ->values();
            }

            $outfit = Outfit::find($outfitId);
            $stock = max(1, (int) ($outfit?->stock ?? 1));
            $activeUsageCount = $conflictIds->count();
            if ($activeUsageCount >= $stock) {
                $conflicts[] = $outfit ? $outfit->code : "#{$outfitId}";
            }
        }

        return $conflicts;
    }

    /**
     * True when the other rental only touches this request across the return buffer (legal sequential booking).
     */
    private function rentalIsCooldownBufferTouchOnly(Rental $rental, string $startDate, string $endDate, int $bufferDays): bool
    {
        if ($bufferDays <= 0) {
            return false;
        }

        $requestedStart = Carbon::parse($startDate)->startOfDay();
        $requestedEnd = Carbon::parse($endDate)->startOfDay();
        $boundaryForward = $rental->end_date->copy()->addDays($bufferDays)->isSameDay($requestedStart);
        $boundaryBackward = $rental->start_date->copy()->subDays($bufferDays)->isSameDay($requestedEnd);

        return $boundaryForward || $boundaryBackward;
    }

    private function hasOnlyBoundaryCooldownConflicts(array $outfitIds, string $startDate, string $endDate): bool
    {
        $bufferDays = $this->rentalReturnBufferDays();
        if ($bufferDays <= 0) {
            return false;
        }

        $requestedStart = Carbon::parse($startDate)->startOfDay();
        $requestedEnd = Carbon::parse($endDate)->startOfDay();
        $foundAnyConflicts = false;

        foreach ($outfitIds as $outfitId) {
            $query = Rental::whereHas('outfits', fn ($q) => $q->where('outfits.id', $outfitId))
                ->where('status', 'active')
                ->where('start_date', '<=', $requestedEnd->copy()->addDays($bufferDays))
                ->where('end_date', '>=', $requestedStart->copy()->subDays($bufferDays));
            $rentals = $query->get();
            if (Schema::hasColumn('rentals', 'outfit_id')) {
                $fallback = Rental::where('outfit_id', $outfitId)
                    ->where('status', 'active')
                    ->where('start_date', '<=', $requestedEnd->copy()->addDays($bufferDays))
                    ->where('end_date', '>=', $requestedStart->copy()->subDays($bufferDays))
                    ->get();
                $rentals = $rentals->merge($fallback)->unique('id')->values();
            }
            if ($rentals->isEmpty()) {
                continue;
            }

            $foundAnyConflicts = true;
            foreach ($rentals as $rental) {
                $boundaryForward = $rental->end_date->copy()->addDays($bufferDays)->isSameDay($requestedStart);
                $boundaryBackward = $rental->start_date->copy()->subDays($bufferDays)->isSameDay($requestedEnd);
                if (! ($boundaryForward || $boundaryBackward)) {
                    return false;
                }
            }
        }

        return $foundAnyConflicts;
    }

    /**
     * Outfit IDs that actually need cool-down manager approval on create (have a buffer conflict, resolved as boundary-only per outfit).
     * Other outfit IDs in the same request are included on the booking but were already fine to add without approval.
     *
     * @param  array<int>  $outfitIds
     * @return array<int>
     */
    private function outfitIdsNeedingCooldownApprovalFocusForCreate(array $outfitIds, string $startDate, string $endDate): array
    {
        $focus = [];
        foreach ($outfitIds as $rawId) {
            $sid = (int) $rawId;
            if ($sid <= 0) {
                continue;
            }
            $one = [$sid];
            if ($this->hasDateConflicts($one, $startDate, $endDate) === []) {
                continue;
            }
            if ($this->hasOnlyBoundaryCooldownConflicts($one, $startDate, $endDate)) {
                $focus[] = $sid;
            }
        }

        return array_values(array_unique($focus));
    }

    /**
     * True when proposed dates/items only touch other active rentals across the return buffer (same as create flow),
     * ignoring the rental being edited. Used to allow staff to queue manager approval from Quick edit.
     */
    private function hasOnlyBoundaryCooldownConflictsForEdit(array $outfitIds, string $startDate, string $endDate, int $excludeRentalId): bool
    {
        $bufferDays = $this->rentalReturnBufferDays();
        if ($bufferDays <= 0) {
            return false;
        }

        $requestedStart = Carbon::parse($startDate)->startOfDay();
        $requestedEnd = Carbon::parse($endDate)->startOfDay();
        $foundAnyConflicts = false;

        foreach ($outfitIds as $outfitId) {
            $query = Rental::whereHas('outfits', fn ($q) => $q->where('outfits.id', $outfitId))
                ->where('status', 'active')
                ->where('start_date', '<=', $requestedEnd->copy()->addDays($bufferDays))
                ->where('end_date', '>=', $requestedStart->copy()->subDays($bufferDays));
            $rentals = $query->get();
            if (Schema::hasColumn('rentals', 'outfit_id')) {
                $fallback = Rental::where('outfit_id', $outfitId)
                    ->where('status', 'active')
                    ->where('start_date', '<=', $requestedEnd->copy()->addDays($bufferDays))
                    ->where('end_date', '>=', $requestedStart->copy()->subDays($bufferDays))
                    ->get();
                $rentals = $rentals->merge($fallback)->unique('id')->values();
            }
            $rentals = $rentals->reject(fn ($r) => (int) $r->id === $excludeRentalId)->values();
            if ($rentals->isEmpty()) {
                continue;
            }

            $foundAnyConflicts = true;
            foreach ($rentals as $rental) {
                $boundaryForward = $rental->end_date->copy()->addDays($bufferDays)->isSameDay($requestedStart);
                $boundaryBackward = $rental->start_date->copy()->subDays($bufferDays)->isSameDay($requestedEnd);
                if (! ($boundaryForward || $boundaryBackward)) {
                    return false;
                }
            }
        }

        return $foundAnyConflicts;
    }

    private function calculateRentalNet(Rental $rental): float
    {
        return RentalFinancialSummary::netInclTax($rental, $this->pricingTaxPercent());
    }

    /**
     * @param  array<int|string, mixed>  $itemDiscounts
     * @param  array<int|string, mixed>  $itemDiscountNotes
     */
    private function missingItemDiscountNote(array $itemDiscounts, array $itemDiscountNotes): bool
    {
        foreach ($itemDiscounts as $outfitId => $value) {
            if ($value === null || $value === '' || (float) $value <= 0.0) {
                continue;
            }
            if (empty(trim((string) ($itemDiscountNotes[$outfitId] ?? '')))) {
                return true;
            }
        }

        return false;
    }
}
