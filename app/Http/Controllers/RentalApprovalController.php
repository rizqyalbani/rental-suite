<?php

namespace App\Http\Controllers;

use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\Outfit;
use App\Models\TutorialRental;
use App\Models\TutorialRentalActivityLog;
use App\Models\TutorialRentalApprovalRequest;
use App\Providers\AppServiceProvider;
use App\Support\JournalEntryValidationException;
use App\Support\RentalFinancialSummary;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreCache;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\View\View;

class RentalApprovalController extends Controller
{
    /**
     * The RentalActivityLog (sub)class matching a given Rental (sub)class —
     * TutorialRental's activity needs to land in tutorial_rental_activity_logs
     * (its rental_id FK points at tutorial_rentals, not the real table), so
     * every ::record() call touching a rental that could be either type needs
     * this instead of hardcoding RentalActivityLog.
     */
    private function activityLogClassFor(string $rentalClass): string
    {
        return $rentalClass === TutorialRental::class ? TutorialRentalActivityLog::class : RentalActivityLog::class;
    }

    public function pendingSnapshot(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['count' => 0, 'latest_id' => null]);
        }

        if ($user->isManagement() || $user->isSuperAdmin()) {
            $storeIds = $user->accessibleActiveStoreIds();
            $q = RentalApprovalRequest::withoutGlobalScope('store_scope')
                ->where('status', 'pending')
                ->whereIn('store_id', $storeIds)
                ->where(fn ($w) => $w->whereNull('payload->kind')->orWhere('payload->kind', '!=', \App\Support\PaymentShiftAssignment::KIND));
        } else {
            $q = RentalApprovalRequest::query()
                ->where('status', 'pending')
                ->where('requested_by_user_id', $user->id);
        }

        $count = (clone $q)->count();
        $latestId = (clone $q)->max('id');

        // IDs of this user's own pending requests (for clearing my-pending-approvals-section after remote approve).
        $myPendingIds = RentalApprovalRequest::query()
            ->where('status', 'pending')
            ->where('requested_by_user_id', $user->id)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->all();

        return response()->json([
            'count' => (int) $count,
            'latest_id' => $latestId !== null ? (int) $latestId : null,
            'my_pending_ids' => $myPendingIds,
        ]);
    }

    /**
     * HTML fragments for updating approval UI without a full page reload (keeps in-progress form input).
     */
    public function queueFragment(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || (! $user->isManagement() && ! $user->isSuperAdmin())) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $storeIds = $user->accessibleActiveStoreIds();
        $pendingRentalApprovals = RentalApprovalRequest::withoutGlobalScope('store_scope')
            ->where('status', 'pending')
            ->whereIn('store_id', $storeIds)
            ->where(fn ($w) => $w->whereNull('payload->kind')->orWhere('payload->kind', '!=', \App\Support\PaymentShiftAssignment::KIND))
            ->with('requestedBy')
            ->latest()
            ->limit(5)
            ->get();

        AppServiceProvider::attachApprovalOutfitCodes($pendingRentalApprovals);

        $queueTotal = RentalApprovalRequest::withoutGlobalScope('store_scope')
            ->where('status', 'pending')
            ->whereIn('store_id', $storeIds)
            ->where(fn ($w) => $w->whereNull('payload->kind')->orWhere('payload->kind', '!=', \App\Support\PaymentShiftAssignment::KIND))
            ->count();

        $inlineHtml = null;
        $dockHtml = null;

        if ($pendingRentalApprovals->isNotEmpty()) {
            $inlineHtml = view('rentals.partials.manager-pending-inline', [
                'managerApprovalQueue' => $pendingRentalApprovals,
                'pendingRentalApprovalsCount' => $queueTotal,
            ])->render();

            $dockHtml = view('partials.pending-rental-approval-cards', [
                'approvals' => $pendingRentalApprovals,
                'queueTotal' => $queueTotal,
            ])->render();
        }

        return response()->json([
            'inline_html' => $inlineHtml,
            'dock_html' => $dockHtml,
            'queue_total' => (int) $queueTotal,
        ]);
    }

    /**
     * A request is decided only by someone who can access ITS store. The {approval} binding does not scope
     * by store, so a supervisor of store 2 could approve or reject a store 1 request (overnight review C2-4).
     * Management and super admin reach every active store, so nothing changes for them.
     */
    private function refuseOtherStoreDecision(Request $request, RentalApprovalRequest $approval, bool $wantsJson): RedirectResponse|JsonResponse|null
    {
        $storeId = (int) ($approval->store_id ?? 0);
        if ($storeId === 0 || in_array($storeId, $request->user()->accessibleActiveStoreIds(), true)) {
            return null;
        }

        $msg = 'This request belongs to another store.';

        return $wantsJson
            ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 403)
            : back()->withErrors(['approval' => $msg]);
    }

    public function approve(Request $request, RentalApprovalRequest $approval): RedirectResponse|JsonResponse
    {
        $wantsJson = $request->expectsJson() || $request->ajax() || $request->wantsJson();

        if ($response = $this->refuseOtherStoreDecision($request, $approval, $wantsJson)) {
            return $response;
        }

        if ($approval->status !== 'pending') {
            $msg = 'This request has already been processed.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                : back()->withErrors(['approval' => $msg]);
        }

        $payload = $approval->payload ?? [];
        $kind = (string) ($payload['kind'] ?? 'create');

        // Only the shift owner decides these, on their own accept/reject route.
        if ($kind === \App\Support\PaymentShiftAssignment::KIND) {
            $msg = 'Only the owner of that shift can accept this payment.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 403)
                : back()->withErrors(['approval' => $msg]);
        }

        if ($kind === \App\Support\RentalLateDone::KIND) {
            $msg = \App\Support\RentalLateDone::approve($approval, $request->user(), (string) $request->input('decision_note', ''));
            if ($msg !== null) {
                return $wantsJson
                    ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                    : back()->withErrors(['approval' => $msg]);
            }

            return $wantsJson
                ? response()->json(['ok' => true, 'mode' => 'approved', 'kind' => 'late_done', 'rental_id' => (int) ($payload['rental_id'] ?? 0), 'approval_id' => $approval->id])
                : back()->with('success', 'Pickup/return report approved and applied.');
        }

        if ($kind === \App\Support\RentalFreeRequest::KIND) {
            $msg = \App\Support\RentalFreeRequest::approve($approval, $request->user(), (string) $request->input('decision_note', ''));
            if ($msg !== null) {
                return $wantsJson
                    ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                    : back()->withErrors(['approval' => $msg]);
            }

            $refundAmount = (float) ($approval->fresh()->payload['refund_amount'] ?? 0);
            $freeMsg = $refundAmount > 0.004
                ? 'Rent is now free (Rp 0). Hand Rp '.number_format($refundAmount, 0, ',', '.').' back to the customer — it is recorded as a FREE refund.'
                : 'Rent is now free (Rp 0).';

            return $wantsJson
                ? response()->json(['ok' => true, 'mode' => 'approved', 'kind' => 'free', 'rental_id' => (int) ($payload['rental_id'] ?? 0), 'approval_id' => $approval->id, 'message' => $freeMsg, 'refund_amount' => $refundAmount])
                : back()->with('success', $freeMsg);
        }

        if ($kind === 'delete') {
            $deleteRentalId = isset($payload['rental_id']) ? (int) $payload['rental_id'] : 0;
            // A practice approval points at tutorial_rentals, whose ids overlap the real table.
            $deleteRentalClass = $approval->rentalModelClass();
            $deleteRental = $deleteRentalId > 0 ? $deleteRentalClass::withoutGlobalScope('store_scope')->find($deleteRentalId) : null;
            if (! $deleteRental) {
                $msg = 'Rental not found — it may have already been deleted.';

                return $wantsJson
                    ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                    : back()->withErrors(['approval' => $msg]);
            }

            // A paid rent is only deleted as "wrong input" (money never received):
            // that needs management, never the person who asked.
            $wrongInput = ! empty($payload['wrong_input']);
            // Practice data (the in-app tour) has no money behind it and the person taking the tour plays every
            // role, so both guards below apply to real requests only (decision D8, 2026-10-10).
            $practiceApproval = $approval instanceof TutorialRentalApprovalRequest;
            if ($wrongInput && ! $practiceApproval && ! ($request->user()->isManagement() || $request->user()->isSuperAdmin())) {
                $msg = 'Deleting a paid rent (wrong input) needs management approval.';

                return $wantsJson
                    ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 403)
                    : back()->withErrors(['approval' => $msg]);
            }
            if ($wrongInput && ! $practiceApproval && (int) $approval->requested_by_user_id === (int) $request->user()->id) {
                $msg = 'You submitted this request — it needs a decision from someone else.';

                return $wantsJson
                    ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 403)
                    : back()->withErrors(['approval' => $msg]);
            }

            // A request made before the rent was paid (or before this rule) must not
            // wipe the money now — reject the request and cancel the rent instead.
            if (! $wrongInput && ($msg = RentalController::paidRentalDeleteBlockMessage($deleteRental))) {
                return $wantsJson
                    ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                    : back()->withErrors(['approval' => $msg]);
            }

            $deciderId = $request->user()->id;
            DB::transaction(function () use ($deleteRental, $deleteRentalClass, $approval, $request, $deciderId) {
                $isPractice = $deleteRentalClass === TutorialRental::class;
                $affectedShiftIds = $isPractice ? [] : \App\Support\ShiftClosedTotals::shiftIdsForRental($deleteRental);
                $this->activityLogClassFor($deleteRentalClass)::record($deleteRental, 'delete_approved', ! empty($approval->payload['wrong_input']) ? [
                    'delete_reason' => [null, 'Wrong input: '.($approval->payload['reason'] ?? '')],
                    'money_reversed' => [null, $deleteRental->moneyReceived()],
                    'requested_by' => [null, $approval->requestedBy?->name],
                    'approved_by' => [null, $request->user()->name],
                ] : [], note: 'Approval #'.$approval->id, userId: $deciderId);
                $deleteRental->delete();
                if (! $isPractice) {
                    \App\Support\RentalRevenueRecorder::reverseForCancelledRental($deleteRental, $deciderId);
                    \App\Support\ShiftClosedTotals::recomputeClosedShifts($affectedShiftIds);
                }

                $approval->update([
                    'status' => 'approved',
                    'decided_by_user_id' => $deciderId,
                    'decision_note' => (string) $request->input('decision_note', ''),
                    'decided_at' => now(),
                ]);
            });

            return $wantsJson
                ? response()->json(['ok' => true, 'mode' => 'approved', 'kind' => 'delete', 'rental_id' => $deleteRental->id, 'approval_id' => $approval->id])
                : back()->with('success', 'Rental deleted.');
        }

        $rentalData = $payload['rental'] ?? [];
        $outfitIds = array_values(array_unique(array_map('intval', is_array($payload['outfit_ids'] ?? null) ? $payload['outfit_ids'] : [])));
        $outfitIds = array_values(array_filter($outfitIds, fn ($id) => $id > 0));

        // The payload is written by the app, but anything that reaches the database
        // half-broken (nonexistent items, unreadable dates, a text amount) used to
        // surface as a server error in the middle of the transaction.
        $startDate = is_array($rentalData) ? \App\Support\DateInput::date($rentalData['start_date'] ?? null, '') : '';
        $endDate = is_array($rentalData) ? \App\Support\DateInput::date($rentalData['end_date'] ?? null, '') : '';
        $rawPaid = is_array($rentalData) ? ($rentalData['amount_paid'] ?? null) : null;
        $payloadBroken = ! is_array($rentalData)
            || $startDate === '' || $endDate === '' || $endDate < $startDate
            || ($rawPaid !== null && $rawPaid !== '' && (! is_numeric($rawPaid) || (float) $rawPaid < 0))
            || ($outfitIds !== [] && Outfit::withoutGlobalScopes()->whereIn('id', $outfitIds)->count() !== count($outfitIds));

        if (empty($rentalData) || empty($outfitIds) || $payloadBroken) {
            $msg = 'Approval payload is invalid.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                : back()->withErrors(['approval' => $msg]);
        }

        // Edit requests are no longer filed by Admin only (a Supervisor can file one through the cool-down
        // shortcut, and D11 sends an item added to a paid rent to the queue for every role), so nobody decides
        // their own edit request (practice requests are exempt, like the other self-approval guards).
        if ($kind === 'edit'
            && ! ($approval instanceof TutorialRentalApprovalRequest)
            && (int) $approval->requested_by_user_id === (int) $request->user()->id) {
            $msg = 'You submitted this request — it needs a decision from someone else.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 403)
                : back()->withErrors(['approval' => $msg]);
        }

        $editRentalId = isset($payload['rental_id']) ? (int) $payload['rental_id'] : null;
        $excludeForHardCheck = $kind === 'edit' && $editRentalId > 0 ? $editRentalId : null;

        $conflicts = $this->hardConflictsOnly(
            $outfitIds,
            (string) ($rentalData['start_date'] ?? ''),
            (string) ($rentalData['end_date'] ?? ''),
            $excludeForHardCheck
        );
        if (! empty($conflicts)) {
            $msg = 'Approval failed. Items no longer available: '.implode(', ', $conflicts);

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                : back()->withErrors(['approval' => $msg]);
        }

        $itemDiscounts = $payload['item_discounts'] ?? [];
        $itemDiscountTypes = $payload['item_discount_types'] ?? [];
        $itemDiscountNotes = $payload['item_discount_notes'] ?? [];
        $pricingEnabled = (bool) StoreCache::get('pricing_enabled', true);

        $existing = null;
        if ($kind === 'edit' && $editRentalId > 0) {
            // $approval is either a real RentalApprovalRequest (rental_id
            // points into `rentals`) or a TutorialRentalApprovalRequest
            // (rental_id points into `tutorial_rentals`, a completely
            // separate table with its own id sequence) — rentalModelClass()
            // is the single source of truth for which one applies here.
            $rentalClass = $approval->rentalModelClass();
            $existing = $rentalClass::query()->whereKey($editRentalId)->first();
            if (! $existing || $existing->status !== 'active') {
                $msg = 'Approval failed. Rent is no longer active.';

                return $wantsJson
                    ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                    : back()->withErrors(['approval' => $msg]);
            }

            // The request is a full snapshot of the rental as it looked when it was
            // made (items, discount, dates). If another edit / item removal was
            // decided since, applying this one would silently overwrite it (an item
            // removed in between would come back). Payments recorded meanwhile are
            // fine — they are handled separately. The way out: reject this request
            // and have it submitted again against the current rental.
            if ($rentalClass === Rental::class) {
                // "Since it was submitted" is measured on the log's own ordering (its
                // edit_requested entry) — two events in the same second still sort.
                $requestedLogId = RentalActivityLog::withoutGlobalScopes()
                    ->where('rental_id', $existing->id)
                    ->where('action', 'edit_requested')
                    ->where('note', 'Approval #'.$approval->id)
                    ->value('id');
                // A plain "updated" entry that only touched payment fields (the exempt
                // direct-payment path — see update()'s $isPurePaymentAction) does not
                // structurally conflict with anything this snapshot would apply, so it
                // does not make a waiting edit stale. An 'updated' entry touching
                // anything else (items, discount, dates, ...) does.
                $paymentOnlyFields = ['payment_method', 'payment_kind', 'amount_paid'];
                $changedSince = RentalActivityLog::withoutGlobalScopes()
                    ->where('rental_id', $existing->id)
                    ->whereIn('action', ['edit_approved', 'item_removal_approved', 'updated'])
                    ->when($requestedLogId,
                        fn ($q) => $q->where('id', '>', $requestedLogId),
                        fn ($q) => $q->where('created_at', '>=', $approval->created_at))
                    ->get()
                    ->contains(function ($log) use ($paymentOnlyFields) {
                        if ($log->action !== 'updated') {
                            return true;
                        }

                        return ! empty(array_diff(array_keys((array) ($log->changes ?? [])), $paymentOnlyFields));
                    });
                if ($changedSince) {
                    $msg = 'This request is out of date: the rental was changed (another edit or item removal was decided) after it was submitted. Reject it and ask for it to be submitted again.';

                    return $wantsJson
                        ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                        : back()->withErrors(['approval' => $msg]);
                }
            }
        }

        $itemDiscountArray = is_array($itemDiscounts) ? $itemDiscounts : [];
        $itemDiscountNoteArray = is_array($itemDiscountNotes) ? $itemDiscountNotes : [];
        $orderNoteMissing = (float) ($rentalData['discount'] ?? 0) > 0.0
            && empty(trim((string) ($rentalData['discount_note'] ?? '')));
        if ($pricingEnabled
            && ($orderNoteMissing || $this->missingItemDiscountNote($itemDiscountArray, $itemDiscountNoteArray))) {
            $msg = 'Discount note is required when applying a discount.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['discount_note' => [$msg]]], 422)
                : back()->withErrors(['discount_note' => $msg]);
        }

        if ($pricingEnabled && (($rentalData['payment_kind'] ?? null) === 'full')) {
            $preview = RentalFinancialSummary::previewRentalForTotals(
                $outfitIds,
                $itemDiscountArray,
                is_array($itemDiscountTypes) ? $itemDiscountTypes : [],
                $rentalData['discount'] ?? null,
                $rentalData['discount_type'] ?? 'fixed',
                $rentalData['start_date'] ?? null,
                $rentalData['end_date'] ?? null,
                null,
                $existing ? RentalFinancialSummary::lockedUnitPrices($existing) : null,
                $existing instanceof TutorialRental,
            );
            $tax = max(0, (float) StoreCache::get('pricing_tax_percent', 0));
            $due = (float) RentalFinancialSummary::summarize($preview, $tax)['net_incl_tax'];
            $paid = (float) ($rentalData['amount_paid'] ?? 0);
            if (round($paid, 2) < round($due, 2)) {
                $msg = 'When payment is Full, the amount paid must be at least the total due (Rp '
                    .number_format($due, 0, ',', '.')
                    .', including tax and after discounts).';

                return $wantsJson
                    ? response()->json(['message' => $msg, 'errors' => ['amount_paid' => [$msg]]], 422)
                    : back()->withErrors(['amount_paid' => $msg]);
            }
        }

        $refundInfo = null;
        if ($kind === 'edit' && $editRentalId > 0) {
            $rental = DB::transaction(function () use ($approval, $request, $rentalData, $outfitIds, $itemDiscounts, $itemDiscountTypes, $itemDiscountNotes, $payload, $existing, $rentalClass, &$refundInfo) {
                // Two approvers clicking at once both pass the pending check above;
                // only the first may apply the edit (its refund/adjustment would
                // otherwise be posted twice).
                $approvalClass = get_class($approval);
                $lockedApproval = $approvalClass::query()->withoutGlobalScope('store_scope')->whereKey($approval->id)->lockForUpdate()->first();
                if (! $lockedApproval || $lockedApproval->status !== 'pending') {
                    abort(409, 'This request was already decided.');
                }

                $deciderId = $request->user()->id;
                $taxPercent = max(0, (float) StoreCache::get('pricing_tax_percent', 0));
                $taxRate = max(0.0, $taxPercent / 100);
                $pricesIncludeTax = (bool) StoreCache::get('pricing_prices_include_tax', false);
                $outfitsById = Outfit::query()
                    ->whereIn('id', $outfitIds)
                    ->get(['id', 'price'])
                    ->keyBy('id');
                $updateData = [
                    'customer_name' => trim((string) ($rentalData['customer_name'] ?? '')),
                    'phone' => trim((string) ($rentalData['phone'] ?? '')),
                    'instagram' => $rentalData['instagram'] ?? null,
                    'notes' => trim((string) ($rentalData['notes'] ?? $rentalData['address'] ?? '')),
                    'payment_method' => $rentalData['payment_method'] ?? null,
                    'amount_paid' => array_key_exists('amount_paid', $rentalData) ? $rentalData['amount_paid'] : null,
                    'payment_kind' => $rentalData['payment_kind'] ?? null,
                    'discount' => $rentalData['discount'] ?? null,
                    'discount_type' => $rentalData['discount_type'] ?? 'fixed',
                    'extra_charge' => $rentalData['extra_charge'] ?? null,
                    'discount_note' => $rentalData['discount_note'] ?? null,
                    'start_date' => $rentalData['start_date'],
                    'end_date' => $rentalData['end_date'],
                    'status' => 'active',
                    // The staff member who served the customer and submitted
                    // the edit, not whoever later approves it — same fallback
                    // pattern as the 'create' kind above. Approving someone
                    // else's paperwork shouldn't reassign credit for the sale.
                    'handled_by_user_id' => $rentalData['handled_by_user_id'] ?? $approval->requested_by_user_id,
                ];
                // Admin edit-approvals cover any change, including dropping items —
                // captured here (before sync) so we can tell afterwards whether this
                // approval also amounted to an item removal, same as the dedicated
                // item_removal-kind flow, for the "Refund due" vs "Change" distinction.
                $existingOutfitIdsBeforeSync = $existing->outfits()->pluck('outfits.id')->map(fn ($id) => (int) $id)->all();
                $existing->load('outfits');
                $dueBeforeFin = RentalFinancialSummary::summarize($existing, $taxPercent);
                $dueBefore = max(0.0, (float) $dueBeforeFin['net_incl_tax'] - max(0.0, (float) ($dueBeforeFin['overdue_fee_amount'] ?? 0)));
                $prevAmountPaid = max(0.0, (float) ($existing->amount_paid ?? 0));

                // The request carries the amount paid as it was WHEN IT WAS MADE. Money
                // may have been recorded since (a direct payment), and applying the old
                // figure would wipe it out. So: apply only what this request changed
                // relative to its own starting point, on top of what is recorded now.
                // (Requests made before that starting point was stored can only ever add.)
                if (array_key_exists('amount_paid', $updateData) && $updateData['amount_paid'] !== null) {
                    $requestedPaid = (float) $updateData['amount_paid'];
                    $updateData['amount_paid'] = isset($payload['base_amount_paid'])
                        ? max(0.0, round($prevAmountPaid + ($requestedPaid - (float) $payload['base_amount_paid']), 2))
                        : max($prevAmountPaid, $requestedPaid);
                }
                $existing->update($updateData);
                $newAmountPaid = max(0.0, (float) ($existing->amount_paid ?? 0));

                // Items already on the rent keep the price they were booked at; only a newly
                // added item is priced from today's catalog (D3 = A, price locked at creation).
                $lockedPrices = RentalFinancialSummary::lockedUnitPrices($existing);
                $syncData = [];
                foreach ($outfitIds as $outfitId) {
                    $raw = (float) (($outfitsById->get($outfitId)?->price) ?? 0);
                    if (isset($lockedPrices[$outfitId])) {
                        ['price_incl_tax' => $unitIncl, 'price_excl_tax' => $unitExcl, 'tax_amount' => $unitTax] = $lockedPrices[$outfitId];
                    } elseif ($pricesIncludeTax) {
                        $unitIncl = $raw;
                        // $raw is tax-inclusive — unwrap by dividing by
                        // (1+rate), not by multiplying by rate directly
                        // (which taxes the tax itself). Matches
                        // RentalController::update()'s already-correct version.
                        $unitExcl = $taxRate > 0 ? round($unitIncl / (1 + $taxRate), 2) : $unitIncl;
                        $unitTax = round($unitIncl - $unitExcl, 2);
                    } else {
                        $unitExcl = $raw;
                        $unitTax = round($unitExcl * $taxRate, 2);
                        $unitIncl = round($unitExcl + $unitTax, 2);
                    }
                    $syncData[$outfitId] = [
                        'discount' => $itemDiscounts[$outfitId] ?? null,
                        'discount_type' => $itemDiscountTypes[$outfitId] ?? 'fixed',
                        'discount_note' => isset($itemDiscountNotes[$outfitId]) ? (string) $itemDiscountNotes[$outfitId] : null,
                        'price_incl_tax' => $unitIncl,
                        'price_excl_tax' => $unitExcl,
                        'tax_amount' => $unitTax,
                    ];
                }
                $existing->outfits()->sync($syncData);

                // Cap at the rent-only bill (overdue fines are recorded by the separate
                // overdue path): overpay is returned as change and never enters the shift.
                $existing->load('outfits');
                $existingFin = RentalFinancialSummary::summarize($existing, $taxPercent);
                $due = max(0.0, (float) $existingFin['net_incl_tax'] - max(0.0, (float) ($existingFin['overdue_fee_amount'] ?? 0)));
                RentalPaymentRecorder::recordIfNonZero(
                    $existing,
                    min($newAmountPaid, $due) - min($prevAmountPaid, $due),
                    $approval->source_shift_session_id,
                    $approval->requested_by_user_id,
                    $updateData['payment_method'] ?? null,
                    $updateData['payment_kind'] ?? null,
                    // Was missing here (RentalController passes it) — without
                    // the total, a down payment/final payment recorded through
                    // an approved edit books only its own amount as the whole
                    // contract, leaving Rental Receivable and Customer Deposits short.
                    totalDue: $due,
                );

                // The edit may have changed the total (items, dates, discount)
                // with no payment delta at all — the recorder above only
                // journals payments, so bring Rental Receivable/Customer Deposits/revenue up to
                // the new contract. Best-effort; see RentalLedgerSync.
                // If the edit left the customer having paid more than the new
                // total, the difference is handed back the way they paid
                // (cash from Store Cash, QRIS/transfer from Bank).
                if ($rentalClass === Rental::class) {
                    $refundInfo = \App\Support\RentalLedgerSync::reconcileRental($existing, refundOverpayment: true, byUserId: $deciderId);
                }

                $takeoverFrom = $payload['takeover_from'] ?? [];
                $allowedTakeovers = is_array($takeoverFrom) ? $takeoverFrom : [];
                $takeoverRentalIds = array_values(array_unique(array_map('intval', array_values($allowedTakeovers))));
                $takeoverRentals = Rental::withCount('outfits')->whereIn('id', $takeoverRentalIds)->get()->keyBy('id');
                foreach ($allowedTakeovers as $outfitId => $fromRentalId) {
                    $sid = (int) $outfitId;
                    $fromRid = (int) $fromRentalId;
                    $from = $takeoverRentals->get($fromRid);
                    if ($from) {
                        $from->outfits()->detach($sid);
                    }
                }
                if ($allowedTakeovers !== []) {
                    $fromIds = array_values(array_unique(array_map('intval', array_values($allowedTakeovers))));
                    $remaining = Rental::withCount('outfits')->whereIn('id', $fromIds)->get()->filter(fn ($r) => (int) $r->outfits_count === 0)->pluck('id')->all();
                    if ($remaining !== []) {
                        Rental::whereIn('id', $remaining)->update(['status' => 'cancelled']);
                    }

                    // A rental that lost an item to this edit has a smaller
                    // total now — bring its ledger contract down too. The ones
                    // emptied out and cancelled above are NOT touched: what
                    // happens to their deposit is the cancellation policy's
                    // call, and RentalLedgerSync::run() lists them for review.
                    if ($rentalClass === Rental::class) {
                        foreach (array_diff($fromIds, $remaining) as $fromId) {
                            $source = Rental::find($fromId);
                            if ($source) {
                                \App\Support\RentalLedgerSync::reconcileRental($source);
                            }
                        }
                    }
                }

                $approval->update([
                    'status' => 'approved',
                    'decided_by_user_id' => $deciderId,
                    'decision_note' => (string) $request->input('decision_note', ''),
                    'decided_at' => now(),
                ]);

                $this->activityLogClassFor($rentalClass)::record($existing, 'edit_approved', [], note: 'Approval #'.$approval->id, userId: $deciderId);

                $removedOutfitIdsViaEdit = array_values(array_diff($existingOutfitIdsBeforeSync, $outfitIds));
                if ($removedOutfitIdsViaEdit !== []) {
                    $diffFull = $newAmountPaid - $due;
                    $changeFromPayment = min($diffFull, max(0.0, $newAmountPaid - $dueBefore));
                    $refundFromRemoval = $diffFull - $changeFromPayment;
                    $this->activityLogClassFor($rentalClass)::record($existing, 'item_removal_approved', [
                        'removed_outfit_ids' => [Outfit::codesForIds($removedOutfitIdsViaEdit), null],
                        'item_removal_change_amount' => [null, round($changeFromPayment, 2)],
                        'item_removal_refund_amount' => [null, round($refundFromRemoval, 2)],
                    ], note: 'Approval #'.$approval->id, userId: $deciderId);
                }

                return $existing->fresh();
            });
        } else {
            $rental = DB::transaction(function () use ($approval, $request, $rentalData, $outfitIds, $itemDiscounts, $itemDiscountTypes, $itemDiscountNotes) {
                $cn = trim((string) ($rentalData['customer_name'] ?? ''));
                $ph = trim((string) ($rentalData['phone'] ?? ''));
                $notes = trim((string) ($rentalData['notes'] ?? $rentalData['address'] ?? ''));
                $taxPercent = max(0, (float) StoreCache::get('pricing_tax_percent', 0));
                $taxRate = max(0.0, $taxPercent / 100);
                $pricesIncludeTax = (bool) StoreCache::get('pricing_prices_include_tax', false);
                $outfitsById = Outfit::query()
                    ->whereIn('id', $outfitIds)
                    ->get(['id', 'price'])
                    ->keyBy('id');
                $createAmountPaid = array_key_exists('amount_paid', $rentalData) ? $rentalData['amount_paid'] : null;
                $createPaymentKind = $rentalData['payment_kind'] ?? null;
                $createData = [
                    'customer_name' => $cn !== '' ? $cn : '— Pending customer details',
                    'phone' => $ph !== '' ? $ph : '—',
                    'instagram' => $rentalData['instagram'] ?? null,
                    'notes' => $notes !== '' ? $notes : null,
                    'payment_method' => $rentalData['payment_method'] ?? null,
                    'amount_paid' => $createAmountPaid,
                    'payment_kind' => $createPaymentKind,
                    'down_payment_amount' => $createPaymentKind === 'down_payment' ? round((float) $createAmountPaid, 2) : null,
                    'discount' => $rentalData['discount'] ?? null,
                    'discount_type' => $rentalData['discount_type'] ?? 'fixed',
                    'extra_charge' => $rentalData['extra_charge'] ?? null,
                    'discount_note' => $rentalData['discount_note'] ?? null,
                    'start_date' => $rentalData['start_date'],
                    'end_date' => $rentalData['end_date'],
                    'status' => 'active',
                    'handled_by_user_id' => $rentalData['handled_by_user_id'] ?? $approval->requested_by_user_id,
                    'created_by_user_id' => $approval->requested_by_user_id,
                    'shift_session_id' => $approval->source_shift_session_id,
                    'is_tutorial' => $approval->is_tutorial,
                ];

                $rental = Rental::create($createData);

                $attachData = [];
                foreach ($outfitIds as $outfitId) {
                    $raw = (float) (($outfitsById->get($outfitId)?->price) ?? 0);
                    if ($pricesIncludeTax) {
                        $unitIncl = $raw;
                        // $raw is tax-inclusive — unwrap by dividing by
                        // (1+rate), not by multiplying by rate directly
                        // (which taxes the tax itself). Matches
                        // RentalController::update()'s already-correct version.
                        $unitExcl = $taxRate > 0 ? round($unitIncl / (1 + $taxRate), 2) : $unitIncl;
                        $unitTax = round($unitIncl - $unitExcl, 2);
                    } else {
                        $unitExcl = $raw;
                        $unitTax = round($unitExcl * $taxRate, 2);
                        $unitIncl = round($unitExcl + $unitTax, 2);
                    }
                    $attachData[$outfitId] = [
                        'discount' => $itemDiscounts[$outfitId] ?? null,
                        'discount_type' => $itemDiscountTypes[$outfitId] ?? 'fixed',
                        'discount_note' => isset($itemDiscountNotes[$outfitId]) ? (string) $itemDiscountNotes[$outfitId] : null,
                        'price_incl_tax' => $unitIncl,
                        'price_excl_tax' => $unitExcl,
                        'tax_amount' => $unitTax,
                    ];
                }
                $rental->outfits()->attach($attachData);

                // Cap at the rent-only bill (overdue fines are recorded by the separate
                // overdue path): overpay is returned as change and never enters the shift.
                $rental->load('outfits');
                $createdFin = RentalFinancialSummary::summarize($rental, $taxPercent);
                $due = max(0.0, (float) $createdFin['net_incl_tax'] - max(0.0, (float) ($createdFin['overdue_fee_amount'] ?? 0)));
                RentalPaymentRecorder::recordIfNonZero(
                    $rental,
                    min(max(0.0, (float) ($createAmountPaid ?? 0)), $due),
                    $approval->source_shift_session_id,
                    $approval->requested_by_user_id,
                    $createData['payment_method'] ?? null,
                    $createPaymentKind,
                    // Was missing (RentalController::store passes it): a rental
                    // created through approval booked its down payment as the
                    // whole contract, so no Rental Receivable was ever posted for the
                    // unpaid remainder (seen on a real rental).
                    totalDue: $due,
                );

                $approval->update([
                    'status' => 'approved',
                    'decided_by_user_id' => $request->user()->id,
                    'decision_note' => (string) $request->input('decision_note', ''),
                    'decided_at' => now(),
                ]);

                RentalActivityLog::record($rental, 'created', [], note: 'Approval #'.$approval->id, userId: $request->user()->id);

                return $rental;
            });
        }

        // Approving this may have just posted a refund (the edit left the customer
        // overpaid) — the entry only RECORDS that the money is owed back, it never
        // moves real cash, so whoever approved it needs to see this and hand it over.
        $refundMessage = null;
        if ($refundInfo !== null) {
            $accountLabel = implode(', ', array_map(
                fn ($a) => $a['code'].' — '.$a['name'].' (Rp '.number_format($a['amount'], 0, ',', '.').')',
                $refundInfo['accounts']
            ));
            $refundMessage = 'Approved. The customer overpaid by Rp '.number_format($refundInfo['amount'], 0, ',', '.')
                .', recorded as Rental Refunds from '.$accountLabel.'. Make sure the money is actually returned to the customer.';
        }

        return $wantsJson
            ? response()->json([
                'ok' => true,
                'mode' => 'approved',
                'kind' => $kind,
                'rental_id' => $rental->id,
                'approval_id' => $approval->id,
                'refund' => $refundInfo,
                'refund_message' => $refundMessage,
            ])
            : back()->with('success', $refundMessage ?? 'Rental request approved and created successfully.');
    }

    public function reject(Request $request, RentalApprovalRequest $approval): RedirectResponse|JsonResponse
    {
        $wantsJson = $request->expectsJson() || $request->ajax() || $request->wantsJson();

        if ($response = $this->refuseOtherStoreDecision($request, $approval, $wantsJson)) {
            return $response;
        }

        if ($approval->status !== 'pending') {
            $msg = 'This request has already been processed.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                : back()->withErrors(['approval' => $msg]);
        }

        $rejPayload = $approval->payload ?? [];
        $rejKind = (string) ($rejPayload['kind'] ?? 'create');
        $rejRentalId = isset($rejPayload['rental_id']) ? (int) $rejPayload['rental_id'] : null;

        if ($rejKind === \App\Support\PaymentShiftAssignment::KIND) {
            $msg = 'Only the owner of that shift can reject this payment.';

            return $wantsJson
                ? response()->json(['message' => $msg], 403)
                : back()->withErrors(['approval' => $msg]);
        }

        // Same reasoning as approveItemRemoval(): only item_removal requests
        // can be both submitted and decided by the same Supervisor/Management
        // roles, so it's the only kind that needs this check — edit/delete
        // requests are always submitted by Admin, who can't reach this route.
        if ($rejKind === 'item_removal' && ! ($approval instanceof TutorialRentalApprovalRequest) && (int) $approval->requested_by_user_id === (int) $request->user()->id) {
            $msg = 'You submitted this request — it needs a decision from someone else.';

            return $wantsJson
                ? response()->json(['message' => $msg], 403)
                : back()->withErrors(['approval' => $msg]);
        }

        $approval->update([
            'status' => 'rejected',
            'decided_by_user_id' => $request->user()->id,
            'decision_note' => (string) $request->input('decision_note', ''),
            'decided_at' => now(),
        ]);

        $rejRentalClass = $approval->rentalModelClass();
        $rejRentalQuery = $rejRentalClass === Rental::class
            ? $rejRentalClass::withoutGlobalScope('store_scope')
            : $rejRentalClass::query();

        if ($rejKind === 'item_removal' && $rejRentalId) {
            $rejRental = $rejRentalQuery->find($rejRentalId);
            if ($rejRental) {
                $this->activityLogClassFor($rejRentalClass)::record($rejRental, 'item_removal_rejected', [
                    'removed_outfit_ids' => [Outfit::codesForIds($rejPayload['removed_outfit_ids'] ?? []), null],
                ], note: 'Approval #'.$approval->id);
            }
        } elseif ($rejKind === 'edit' && $rejRentalId) {
            $rejRental = $rejRentalQuery->find($rejRentalId);
            if ($rejRental) {
                $this->activityLogClassFor($rejRentalClass)::record($rejRental, 'edit_rejected', [], note: 'Approval #'.$approval->id);
            }
        } elseif ($rejKind === 'delete' && $rejRentalId) {
            $rejRental = $rejRentalQuery->find($rejRentalId);
            if ($rejRental) {
                $this->activityLogClassFor($rejRentalClass)::record($rejRental, 'delete_rejected', [], note: 'Approval #'.$approval->id);
            }
        } elseif ($rejKind === \App\Support\RentalLateDone::KIND && $rejRentalId) {
            $rejRental = $rejRentalQuery->find($rejRentalId);
            if ($rejRental) {
                $this->activityLogClassFor($rejRentalClass)::record($rejRental, 'late_done_rejected', [
                    'requested_by' => [null, $approval->requestedBy?->name],
                    'rejected_by' => [null, $request->user()->name],
                ], note: 'Approval #'.$approval->id.($approval->decision_note ? ' · '.$approval->decision_note : ''));
            }
        } elseif ($rejKind === \App\Support\RentalFreeRequest::KIND && $rejRentalId) {
            $rejRental = $rejRentalQuery->find($rejRentalId);
            if ($rejRental) {
                $this->activityLogClassFor($rejRentalClass)::record($rejRental, 'free_rejected', [
                    'requested_by' => [null, $approval->requestedBy?->name],
                    'rejected_by' => [null, $request->user()->name],
                ], note: 'Approval #'.$approval->id.($approval->decision_note ? ' · '.$approval->decision_note : ''));
            }
        }

        return $wantsJson
            ? response()->json([
                'ok' => true,
                'mode' => 'rejected',
                'kind' => $rejKind,
                'rental_id' => $rejRentalId > 0 ? $rejRentalId : null,
                'approval_id' => $approval->id,
            ])
            : back()->with('success', 'Rental request rejected.');
    }

    /** Requester withdraws their own pending request (no manager involvement). */
    public function cancel(Request $request, RentalApprovalRequest $approval): RedirectResponse|JsonResponse
    {
        $wantsJson = $request->expectsJson() || $request->ajax() || $request->wantsJson();

        if ((int) $approval->requested_by_user_id !== (int) $request->user()->id) {
            $msg = 'Only the requester can cancel this request.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 403)
                : back()->withErrors(['approval' => $msg]);
        }

        if ($approval->status !== 'pending') {
            $msg = 'This request has already been processed.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                : back()->withErrors(['approval' => $msg]);
        }

        $approval->update([
            'status' => 'cancelled',
            'decided_by_user_id' => $request->user()->id,
            'decision_note' => 'Cancelled by requester',
            'decided_at' => now(),
        ]);

        $cancelPayload = $approval->payload ?? [];
        $cancelKind = (string) ($cancelPayload['kind'] ?? 'create');
        $cancelRentalId = isset($cancelPayload['rental_id']) ? (int) $cancelPayload['rental_id'] : null;

        // Same as approve()/reject(): a 'create' (new cool-down booking) request has no
        // rental yet to attach a log entry to, so only the other three kinds are logged
        // here — matching the existing reject() branches above.
        $cancelRentalClass = $approval->rentalModelClass();
        $cancelRentalQuery = $cancelRentalClass === Rental::class
            ? $cancelRentalClass::withoutGlobalScope('store_scope')
            : $cancelRentalClass::query();

        if ($cancelKind === 'item_removal' && $cancelRentalId) {
            $cancelRental = $cancelRentalQuery->find($cancelRentalId);
            if ($cancelRental) {
                $this->activityLogClassFor($cancelRentalClass)::record($cancelRental, 'item_removal_cancelled', [
                    'removed_outfit_ids' => [Outfit::codesForIds($cancelPayload['removed_outfit_ids'] ?? []), null],
                ], note: 'Approval #'.$approval->id);
            }
        } elseif ($cancelKind === 'edit' && $cancelRentalId) {
            $cancelRental = $cancelRentalQuery->find($cancelRentalId);
            if ($cancelRental) {
                $this->activityLogClassFor($cancelRentalClass)::record($cancelRental, 'edit_cancelled', [], note: 'Approval #'.$approval->id);
            }
        } elseif ($cancelKind === 'delete' && $cancelRentalId) {
            $cancelRental = $cancelRentalQuery->find($cancelRentalId);
            if ($cancelRental) {
                $this->activityLogClassFor($cancelRentalClass)::record($cancelRental, 'delete_cancelled', [], note: 'Approval #'.$approval->id);
            }
        } elseif ($cancelKind === \App\Support\RentalLateDone::KIND && $cancelRentalId) {
            $cancelRental = $cancelRentalQuery->find($cancelRentalId);
            if ($cancelRental) {
                $this->activityLogClassFor($cancelRentalClass)::record($cancelRental, 'late_done_cancelled', [], note: 'Approval #'.$approval->id);
            }
        } elseif ($cancelKind === \App\Support\PaymentShiftAssignment::KIND && $cancelRentalId) {
            $cancelRental = $cancelRentalQuery->find($cancelRentalId);
            if ($cancelRental) {
                $this->activityLogClassFor($cancelRentalClass)::record($cancelRental, 'payment_assign_cancelled', [], note: 'Approval #'.$approval->id);
            }
        } elseif ($cancelKind === \App\Support\RentalFreeRequest::KIND && $cancelRentalId) {
            $cancelRental = $cancelRentalQuery->find($cancelRentalId);
            if ($cancelRental) {
                $this->activityLogClassFor($cancelRentalClass)::record($cancelRental, 'free_cancelled', [], note: 'Approval #'.$approval->id);
            }
        }

        return $wantsJson
            ? response()->json(['ok' => true, 'mode' => 'cancelled', 'approval_id' => $approval->id])
            : back()->with('success', 'Request cancelled.');
    }

    /**
     * Standalone queue page: pending/approved/rejected "remove item after payment" requests.
     * Visible to supervisor, management, and super_admin.
     */
    public function itemRemovalIndex(Request $request): View
    {
        $user = $request->user();
        abort_unless($user && $user->mayApproveItemRemoval(), 403);

        $status = (string) $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = 'pending';
        }

        $storeIds = $user->accessibleActiveStoreIds();
        $taxPercent = $this->pricingTaxPercentForApproval();

        // Onboarding tour: practice item-removal requests live in their own
        // tables (TutorialRentalApprovalRequest / TutorialRental — see
        // TutorialRental) so they never share the real table's id sequence.
        // Isolation is by table, not a tag, so no extra filtering is needed
        // beyond "this user's own" in tutorial mode.
        $tutorialMode = $request->boolean('tutorial');
        $approvalClass = $tutorialMode ? TutorialRentalApprovalRequest::class : RentalApprovalRequest::class;
        $rentalClass = $tutorialMode ? TutorialRental::class : Rental::class;

        $requestsQuery = $approvalClass::query()->where('status', $status)->where('payload->kind', 'item_removal');
        if ($tutorialMode) {
            $requestsQuery->where('requested_by_user_id', $user->id);
        } else {
            $requestsQuery->withoutGlobalScope('store_scope')->whereIn('store_id', $storeIds);
        }
        $requests = $requestsQuery->with(['requestedBy', 'decidedBy'])->latest()->get();

        $rentalIds = $requests->map(fn ($r) => (int) ($r->payload['rental_id'] ?? 0))->filter()->unique()->values();
        $rentalsQuery = $rentalClass::query();
        if (! $tutorialMode) {
            $rentalsQuery->withoutGlobalScope('store_scope');
        }
        $rentals = $rentalsQuery->whereIn('id', $rentalIds)->with('outfits')->get()->keyBy('id');

        $allOutfitIds = $requests->flatMap(fn ($r) => $r->payload['removed_outfit_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values();
        $outfitsById = Outfit::withoutGlobalScope('tutorial_scope')->whereIn('id', $allOutfitIds)->get()->keyBy('id');

        $rows = $requests->map(function (RentalApprovalRequest $approval) use ($rentals, $outfitsById, $taxPercent) {
            $rentalId = (int) ($approval->payload['rental_id'] ?? 0);
            $rental = $rentals->get($rentalId);
            $removedOutfitIds = array_map('intval', $approval->payload['removed_outfit_ids'] ?? []);
            $removedOutfits = collect($removedOutfitIds)->map(fn ($id) => $outfitsById->get($id))->filter()->values();

            $currentTotal = null;
            $amountPaid = null;
            $newTotalEstimate = null;
            $overpayment = false;

            if ($rental) {
                $summary = RentalFinancialSummary::summarize($rental, $taxPercent);
                $currentTotal = (float) ($summary['net_incl_tax'] ?? 0);
                $amountPaid = (float) ($rental->amount_paid ?? 0);

                $removedTotal = $rental->outfits
                    ->whereIn('id', $removedOutfitIds)
                    ->sum(fn ($s) => (float) ($s->pivot->price_incl_tax ?? 0));
                $newTotalEstimate = max(0.0, $currentTotal - $removedTotal);
                $overpayment = $amountPaid > ($newTotalEstimate + 0.004);
            }

            return [
                'approval' => $approval,
                'rental' => $rental,
                'removed_outfits' => $removedOutfits,
                'current_total' => $currentTotal,
                'amount_paid' => $amountPaid,
                'new_total_estimate' => $newTotalEstimate,
                'overpayment' => $overpayment,
            ];
        });

        $countsBase = function (string $forStatus) use ($tutorialMode, $storeIds, $user, $approvalClass) {
            $q = $approvalClass::query()->where('status', $forStatus)->where('payload->kind', 'item_removal');
            if ($tutorialMode) {
                return $q->where('requested_by_user_id', $user->id)->count();
            }

            return $q->withoutGlobalScope('store_scope')->whereIn('store_id', $storeIds)->count();
        };
        $counts = [
            'pending' => $countsBase('pending'),
            'approved' => $countsBase('approved'),
            'rejected' => $countsBase('rejected'),
        ];

        $paymentAccounts = \App\Models\Account::query()
            ->where('is_cash_equivalent', true)
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code', 'name']);

        return view('rentals.item-removal-requests.index', [
            'rows' => $rows,
            'status' => $status,
            'counts' => $counts,
            'paymentAccounts' => $paymentAccounts,
        ]);
    }

    /**
     * Standalone queue page for "quick edit" / cool-down create/edit approval
     * requests — the ones surfaced inline on the rentals page, but capped there
     * to a handful. This is the full list with pending/approved/rejected tabs,
     * same audience as the item-removal queue (supervisor, management, super admin).
     */
    public function editRequestsIndex(Request $request): View
    {
        $user = $request->user();
        abort_unless($user && $user->mayApproveItemRemoval(), 403);

        $status = (string) $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = 'pending';
        }

        $storeIds = $user->accessibleActiveStoreIds();
        // Onboarding tour: practice edit requests live in their own table
        // (TutorialRentalApprovalRequest — see TutorialRental), so this is a
        // switch of which table to query rather than a tag filter.
        $tutorialMode = $request->boolean('tutorial');
        $approvalClass = $tutorialMode ? TutorialRentalApprovalRequest::class : RentalApprovalRequest::class;

        $baseQuery = function () use ($storeIds, $tutorialMode, $user, $approvalClass) {
            $query = $approvalClass::query()
                ->where(fn ($q) => $q->whereNull('payload->kind')->orWhereNotIn('payload->kind', ['item_removal', \App\Support\PaymentShiftAssignment::KIND]));
            if ($tutorialMode) {
                $query->where('requested_by_user_id', $user->id);
            } else {
                $query->withoutGlobalScope('store_scope')->whereIn('store_id', $storeIds);
            }

            return $query;
        };

        $approvals = $baseQuery()
            ->where('status', $status)
            ->with(['requestedBy', 'decidedBy'])
            ->latest()
            ->get();

        AppServiceProvider::attachApprovalOutfitCodes($approvals);

        $counts = [
            'pending' => $baseQuery()->where('status', 'pending')->count(),
            'approved' => $baseQuery()->where('status', 'approved')->count(),
            'rejected' => $baseQuery()->where('status', 'rejected')->count(),
        ];

        return view('rentals.edit-requests.index', [
            'approvals' => $approvals,
            'status' => $status,
            'counts' => $counts,
        ]);
    }

    /**
     * Onboarding tour (Supervisor/Management branch): unlike Admin, editing a
     * rental directly applies — nothing lands in Edit Requests for them to
     * practice approving. There's no real request to walk them through deciding
     * on, so this seeds one: a tutorial-tagged rental + a pending edit-approval
     * request against it, both excluded from every real view/report the same way
     * the rest of the tutorial data is. Idempotent — reuses an existing
     * not-yet-decided practice request for this user instead of piling up
     * duplicates each time the tour is replayed.
     */
    public function seedTutorialEditRequest(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! ($user->isManagement() || $user->isSupervisor() || $user->isSuperAdmin())) {
            abort(403);
        }

        $existing = TutorialRentalApprovalRequest::where('status', 'pending')
            ->where('requested_by_user_id', $user->id)
            ->where('payload->kind', 'edit')
            ->latest()
            ->first();
        if ($existing) {
            return response()->json(['ok' => true, 'approval_id' => $existing->id]);
        }

        $outfit = Outfit::withoutGlobalScope('tutorial_scope')->where('is_tutorial', true)->orderBy('code')->first();
        if (! $outfit) {
            return response()->json(['ok' => false, 'message' => 'No tutorial items configured.'], 422);
        }

        $start = now()->addDays(90)->toDateString();
        $originalEnd = now()->addDays(92)->toDateString();
        $requestedEnd = now()->addDays(93)->toDateString();

        $rental = TutorialRental::create([
            'customer_name' => 'Tutorial Practice',
            'phone' => '000000000',
            'start_date' => $start,
            'end_date' => $originalEnd,
            'status' => 'active',
            'handled_by_user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => (float) $outfit->price,
        ]);
        $rental->outfits()->attach([$outfit->id => [
            'price_incl_tax' => (float) $outfit->price,
            'price_excl_tax' => (float) $outfit->price,
            'tax_amount' => 0,
        ]]);
        TutorialRentalActivityLog::record($rental, 'created');

        $approval = TutorialRentalApprovalRequest::create([
            'requested_by_user_id' => $user->id,
            'payload' => [
                'kind' => 'edit',
                'rental_id' => $rental->id,
                'rental' => [
                    'customer_name' => $rental->customer_name,
                    'phone' => $rental->phone,
                    'instagram' => null,
                    'notes' => '',
                    'start_date' => $start,
                    'end_date' => $requestedEnd,
                    'status' => 'active',
                    'handled_by_user_id' => $user->id,
                    'customer_pending' => false,
                    'payment_method' => 'cash',
                    'payment_kind' => 'full',
                    'amount_paid' => (float) $outfit->price,
                    'discount' => null,
                    'discount_type' => 'fixed',
                    'extra_charge' => null,
                    'discount_note' => null,
                ],
                'outfit_ids' => [$outfit->id],
                'item_discounts' => [$outfit->id => null],
                'item_discount_types' => [$outfit->id => 'fixed'],
                'item_discount_notes' => [$outfit->id => null],
            ],
        ]);

        return response()->json(['ok' => true, 'approval_id' => $approval->id]);
    }

    /**
     * Practice rent for the "Report missed pickup/return" tour, any role that
     * runs rents: paid, ended a few days ago, never marked picked up/returned,
     * carrying the automatic Rp 100.000 overdue fee. Tutorial tables only.
     */
    public function seedTutorialLateDoneRent(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user && $user->mayOperateRentTransactions(), 403);

        // Fresh each run: drop this user's unfinished practice from a previous run.
        TutorialRentalApprovalRequest::where('requested_by_user_id', $user->id)->where('status', 'pending')
            ->where('payload->kind', \App\Support\RentalLateDone::KIND)->delete();
        $rental = $this->makeLateDonePracticeRent($user);

        return $rental
            ? response()->json(['ok' => true, 'rental_id' => $rental->id])
            : response()->json(['ok' => false, 'message' => 'No tutorial items configured.'], 422);
    }

    private function makeLateDonePracticeRent(\App\Models\User $user): ?TutorialRental
    {
        $outfit = Outfit::withoutGlobalScope('tutorial_scope')->where('is_tutorial', true)->orderBy('code')->first();
        if (! $outfit) {
            return null;
        }
        $start = now()->subDays(6)->startOfDay();
        $end = now()->subDays(4)->startOfDay();
        $rental = TutorialRental::create([
            // Flags it as practice for the rents list too (e.g. an admin without an
            // open shift still gets Edit on it, like the other tours' practice rent).
            'is_tutorial' => true,
            'customer_name' => 'Sample Missed Return',
            'phone' => '000000000',
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'status' => 'active',
            'handled_by_user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => (float) $outfit->price,
            'overdue_base_end_date' => $end->toDateString(),
            'overdue_fee_rate' => 100000,
            'overdue_fee_days' => 1,
            'overdue_fee_amount' => 100000,
            'overdue_fee_applied_at' => now()->subDays(3),
        ]);
        $rental->outfits()->attach([$outfit->id => [
            'price_incl_tax' => (float) $outfit->price,
            'price_excl_tax' => (float) $outfit->price,
            'tax_amount' => 0,
        ]]);
        TutorialRentalActivityLog::record($rental, 'created');

        return $rental;
    }

    /**
     * Practice data for the "Report missed pickup/return" tour: a paid practice
     * rent that ended a few days ago, never marked picked up or returned, with
     * the automatic Rp 100.000 overdue fee on it — plus a
     * pending report saying it was picked up and returned on time. Lives only
     * in the tutorial tables; idempotent like the other seeds.
     */
    public function seedTutorialLateDoneRequest(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! ($user->isManagement() || $user->isSupervisor() || $user->isSuperAdmin())) {
            abort(403);
        }

        $existing = TutorialRentalApprovalRequest::where('status', 'pending')
            ->where('requested_by_user_id', $user->id)
            ->where('payload->kind', \App\Support\RentalLateDone::KIND)
            ->latest()
            ->first();
        if ($existing) {
            return response()->json(['ok' => true, 'approval_id' => $existing->id]);
        }

        $rental = $this->makeLateDonePracticeRent($user);
        if (! $rental) {
            return response()->json(['ok' => false, 'message' => 'No tutorial items configured.'], 422);
        }
        $start = $rental->start_date->copy();
        $end = $rental->end_date->copy();

        $approval = TutorialRentalApprovalRequest::create([
            'requested_by_user_id' => $user->id,
            'payload' => [
                'kind' => \App\Support\RentalLateDone::KIND,
                'rental_id' => $rental->id,
                'customer_name' => $rental->customer_name,
                'type' => 'return',
                'already_picked_up' => false,
                'picked_up_at' => $start->copy()->setTime(10, 0)->format('Y-m-d H:i:s'),
                'picked_up_by' => $user->id,
                'picked_up_by_name' => $user->name,
                'returned_at' => $end->copy()->setTime(16, 0)->format('Y-m-d H:i:s'),
                'returned_by' => $user->id,
                'returned_by_name' => $user->name,
                'note' => 'Example: forgot to click pickup & return',
            ],
        ]);

        return response()->json(['ok' => true, 'approval_id' => $approval->id]);
    }

    /**
     * Same idea as seedTutorialEditRequest(), for the Removal Requests demo:
     * a paid two-item practice rental with a pending request to remove one of
     * them. Amount paid intentionally covers both items, so after removal the
     * new estimated total drops below what's already paid — triggering the
     * real overpayment warning the review page shows, rather than a flat
     * "everything's fine" card that skips the whole point of that UI.
     */
    public function seedTutorialItemRemovalRequest(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $user || ! $user->mayApproveItemRemoval()) {
            abort(403);
        }

        $existing = TutorialRentalApprovalRequest::where('status', 'pending')
            ->where('payload->kind', 'item_removal')
            ->where('requested_by_user_id', $user->id)
            ->latest()
            ->first();
        if ($existing) {
            return response()->json(['ok' => true, 'approval_id' => $existing->id]);
        }

        $outfits = Outfit::withoutGlobalScope('tutorial_scope')->where('is_tutorial', true)->orderBy('code')->limit(2)->get();
        if ($outfits->count() < 2) {
            return response()->json(['ok' => false, 'message' => 'Not enough tutorial items configured.'], 422);
        }
        $keepOutfit = $outfits[0];
        $removeOutfit = $outfits[1];

        $start = now()->addDays(90)->toDateString();
        $end = now()->addDays(93)->toDateString();

        $rental = TutorialRental::create([
            'customer_name' => 'Tutorial Practice',
            'phone' => '000000000',
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'active',
            'handled_by_user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => (float) $keepOutfit->price + (float) $removeOutfit->price,
        ]);
        $rental->outfits()->attach([
            $keepOutfit->id => ['price_incl_tax' => (float) $keepOutfit->price, 'price_excl_tax' => (float) $keepOutfit->price, 'tax_amount' => 0],
            $removeOutfit->id => ['price_incl_tax' => (float) $removeOutfit->price, 'price_excl_tax' => (float) $removeOutfit->price, 'tax_amount' => 0],
        ]);
        TutorialRentalActivityLog::record($rental, 'created');

        $approval = TutorialRentalApprovalRequest::create([
            'requested_by_user_id' => $user->id,
            'payload' => [
                'kind' => 'item_removal',
                'rental_id' => $rental->id,
                'removed_outfit_ids' => [$removeOutfit->id],
            ],
        ]);

        return response()->json(['ok' => true, 'approval_id' => $approval->id]);
    }

    /**
     * Deletes every tutorial-tagged rental + approval request the current
     * user has created, regardless of status. Called when the onboarding
     * tour is skipped/closed early (nothing left to auto-cancel via the
     * normal finish step) and again at the start of a fresh tour run (a
     * previous run that was abandoned mid-way, e.g. a closed tab, otherwise
     * leaves its practice request sitting in "My Requests" forever).
     */
    public function tutorialCleanup(Request $request): JsonResponse
    {
        $user = $request->user();
        abort_unless($user, 403);

        // Practice data lives in its own tables now (see TutorialRental), so
        // this is a real, permanent delete — not a status-tag cleanup — and
        // forceDelete() (SoftDeletes would otherwise just set deleted_at) is
        // what actually triggers the tutorial_rental_outfit /
        // tutorial_rental_activity_logs cascade, so neither needs a manual
        // pass here anymore.
        TutorialRentalApprovalRequest::where('requested_by_user_id', $user->id)->delete();
        TutorialRental::where('created_by_user_id', $user->id)->get()->each->forceDelete();

        return response()->json(['ok' => true]);
    }

    /**
     * "My Requests" — every approval request the current user personally
     * submitted (edit, delete, cool-down create, item-removal), across all
     * statuses. Available to everyone, not just approval-capable roles — this
     * is how admin (who can no longer see the approval queues, since those are
     * about deciding other people's requests) tracks what happened to their own.
     */
    public function myRequestsIndex(Request $request): View
    {
        $user = $request->user();
        abort_unless($user, 403);

        $status = (string) $request->query('status', 'pending');
        if (! in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = 'pending';
        }

        // This page intentionally shows both real AND practice requests
        // together (the tour deliberately wants the user to see their
        // pending practice request appear here before it gets cancelled) —
        // now that they live in separate tables, that means querying both
        // and merging, rather than one query with a tag filter.
        $baseQuery = fn () => RentalApprovalRequest::query()->where('requested_by_user_id', $user->id);
        $tutorialBaseQuery = fn () => TutorialRentalApprovalRequest::where('requested_by_user_id', $user->id);

        $approvals = $baseQuery()->where('status', $status)->with('decidedBy')->get()
            ->merge($tutorialBaseQuery()->where('status', $status)->with('decidedBy')->get())
            ->sortByDesc('created_at')
            ->values();

        $counts = [
            'pending' => $baseQuery()->where('status', 'pending')->count() + $tutorialBaseQuery()->where('status', 'pending')->count(),
            'approved' => $baseQuery()->where('status', 'approved')->count() + $tutorialBaseQuery()->where('status', 'approved')->count(),
            'rejected' => $baseQuery()->where('status', 'rejected')->count() + $tutorialBaseQuery()->where('status', 'rejected')->count(),
        ];

        return view('rentals.my-requests.index', [
            'approvals' => $approvals,
            'status' => $status,
            'counts' => $counts,
        ]);
    }

    public function approveItemRemoval(Request $request, RentalApprovalRequest $approval): RedirectResponse|JsonResponse
    {
        $wantsJson = $request->expectsJson() || $request->ajax() || $request->wantsJson();
        $user = $request->user();

        if ($response = $this->refuseOtherStoreDecision($request, $approval, $wantsJson)) {
            return $response;
        }

        if (! $user || ! $user->mayApproveItemRemoval()) {
            $msg = 'Your account cannot approve item removal requests.';

            return $wantsJson
                ? response()->json(['message' => $msg], 403)
                : back()->withErrors(['approval' => $msg]);
        }

        // An item-removal request can be submitted by the same Supervisor/Management
        // roles that are also allowed to decide one — without this check, the same
        // person could submit a removal and immediately approve it themselves,
        // defeating the second-person check this queue exists for. (Edit requests
        // carry the same rule in approve().)
        if (! ($approval instanceof TutorialRentalApprovalRequest) && (int) $approval->requested_by_user_id === (int) $user->id) {
            $msg = 'You submitted this request — it needs a decision from someone else.';

            return $wantsJson
                ? response()->json(['message' => $msg], 403)
                : back()->withErrors(['approval' => $msg]);
        }

        $payload = $approval->payload ?? [];
        if (($payload['kind'] ?? null) !== 'item_removal') {
            abort(404);
        }

        if ($approval->status !== 'pending') {
            $msg = 'This request has already been processed.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['approval' => [$msg]]], 422)
                : back()->withErrors(['approval' => $msg]);
        }

        $rentalId = (int) ($payload['rental_id'] ?? 0);
        $removedOutfitIds = array_values(array_unique(array_map('intval', $payload['removed_outfit_ids'] ?? [])));
        $rentalClass = $approval->rentalModelClass();
        $rentalQuery = $rentalClass === Rental::class ? $rentalClass::withoutGlobalScope('store_scope') : $rentalClass::query();
        $rental = $rentalQuery->find($rentalId);

        if (! $rental || $removedOutfitIds === []) {
            $msg = 'This rental or its items no longer exist.';

            return $wantsJson
                ? response()->json(['message' => $msg], 422)
                : back()->withErrors(['approval' => $msg]);
        }

        // Validated up front, outside the transaction/lock below — a missing
        // account when a refund turns out to be owed should surface as a
        // clean "pick an account and resubmit" error, not a mid-transaction
        // failure. Required only once $refundFromRemoval is actually known
        // to be positive (checked again below); the field can be absent on
        // every request that never produces a refund.
        $refundPaymentAccountId = $request->filled('refund_payment_account_id')
            ? (int) $request->input('refund_payment_account_id')
            : null;

        // The refund is credited to this account, so it must be an active cash or bank account; an empty
        // value follows how the customer paid (overnight review B9).
        if ($refundPaymentAccountId !== null
            && ! \App\Models\Account::query()->whereKey($refundPaymentAccountId)->where('is_active', true)->where('is_cash_equivalent', true)->exists()) {
            $msg = 'Pick an active cash or bank account for the refund.';

            return $wantsJson
                ? response()->json(['message' => $msg, 'errors' => ['refund_payment_account_id' => [$msg]]], 422)
                : back()->withErrors(['refund_payment_account_id' => $msg]);
        }

        $refundPostedAmount = null;
        $blockedMessage = null;

        try {
            DB::transaction(function () use ($rental, $removedOutfitIds, $approval, $request, $rentalClass, $refundPaymentAccountId, &$refundPostedAmount, &$blockedMessage) {
                $lockedQuery = $rentalClass === Rental::class ? $rentalClass::withoutGlobalScope('store_scope') : $rentalClass::query();
                $lockedRental = $lockedQuery->whereKey($rental->id)->lockForUpdate()->first();

                // A cancelled rent was already settled by its cancellation (refunded in full or deposit
                // forfeited); removing an item now would refund a second time (overnight review C2-1).
                if ($rentalClass === Rental::class && $lockedRental->status === 'cancelled') {
                    $blockedMessage = 'This rental is cancelled, so the item cannot be removed. Reactivate the rental first, or reject this request.';

                    return;
                }

                // Split the resulting overpayment into "customer already
                // overpaid this" vs. "this only appeared because the item got
                // removed" — computed here, once, at the moment of approval,
                // since this is the only place we still know both the
                // before- and after-removal due. Persisted on the log entry
                // so a later modal reopen (no live cart state to diff
                // against) can still show the split.
                $taxPercent = max(0, (float) StoreCache::get('pricing_tax_percent', 0));
                $lockedRental->load('outfits');
                $dueBefore = (float) RentalFinancialSummary::summarize($lockedRental, $taxPercent)['net_incl_tax'];

                $lockedRental->outfits()->detach($removedOutfitIds);
                $lockedRental->load('outfits');
                $dueAfter = (float) RentalFinancialSummary::summarize($lockedRental, $taxPercent)['net_incl_tax'];

                $paidNow = max(0.0, (float) ($lockedRental->amount_paid ?? 0));
                $diff = $paidNow - $dueAfter;
                $changeFromPayment = min($diff, max(0.0, $paidNow - $dueBefore));
                $refundFromRemoval = round($diff - $changeFromPayment, 2);

                // Only a real (non-tutorial) rental with an actual refund
                // owed needs an account picked — fail loudly and roll back
                // everything (outfit detach included) rather than silently
                // approving without ever posting the refund the approver
                // never got a chance to route.
                // No account picked = follow how the customer paid (cash →
                // Store Cash, QRIS/transfer → Bank); picking one still overrides.
                if ($rentalClass === Rental::class && $refundFromRemoval > 0.004 && ! $refundPaymentAccountId) {
                    $refundPaymentAccountId = \App\Support\RentalRevenueRecorder::resolveCashAccountIdForRental($lockedRental);
                }

                $approval->update([
                    'status' => 'approved',
                    'decided_by_user_id' => $request->user()->id,
                    'decision_note' => (string) $request->input('decision_note', ''),
                    'decided_at' => now(),
                ]);

                $this->activityLogClassFor($rentalClass)::record($lockedRental, 'item_removal_approved', [
                    'removed_outfit_ids' => [Outfit::codesForIds($removedOutfitIds), null],
                    'item_removal_change_amount' => [null, round($changeFromPayment, 2)],
                    'item_removal_refund_amount' => [null, $refundFromRemoval],
                ], note: 'Approval #'.$approval->id);

                // Only real (non-tutorial) rentals reach the ledger — the
                // TutorialRental branch has no accounting counterpart at all.
                if ($rentalClass === Rental::class && $refundFromRemoval > 0.004) {
                    \App\Support\RentalRevenueRecorder::refundForItemRemoval(
                        rental: $lockedRental,
                        amount: $refundFromRemoval,
                        paymentAccountId: $refundPaymentAccountId,
                        approvalId: $approval->id,
                        byUserId: $request->user()->id,
                    );
                    $refundPostedAmount = $refundFromRemoval;
                }

                // Removing an item lowers the total the ledger booked. Any
                // refund was journaled just above (evaluate() counts it), so
                // this only brings the contract down to the new total.
                if ($rentalClass === Rental::class) {
                    \App\Support\RentalLedgerSync::reconcileRental($lockedRental);
                }
            });
        } catch (JournalEntryValidationException $e) {
            return $wantsJson
                ? response()->json(['message' => $e->getMessage()], 422)
                : back()->withErrors(['approval' => $e->getMessage()]);
        }

        if ($blockedMessage !== null) {
            return $wantsJson
                ? response()->json(['message' => $blockedMessage, 'errors' => ['approval' => [$blockedMessage]]], 422)
                : back()->withErrors(['approval' => $blockedMessage]);
        }

        return $wantsJson
            ? response()->json([
                'ok' => true,
                'mode' => 'approved',
                'kind' => 'item_removal',
                'rental_id' => $rental->id,
                'approval_id' => $approval->id,
                'refund_posted' => $refundPostedAmount,
            ])
            : back()->with('success', 'Item removal approved.');
    }

    private function pricingTaxPercentForApproval(): float
    {
        return max(0.0, (float) StoreCache::get('pricing_tax_percent', 0));
    }

    private function rentalReturnBufferDays(): int
    {
        $default = (int) config('app.rental_return_buffer_days', 1);
        $bufferDays = (int) StoreCache::get('rental_return_buffer_days', $default);

        return max(0, $bufferDays);
    }

    private function hardConflictsOnly(array $outfitIds, string $startDate, string $endDate, ?int $excludeRentalId = null): array
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

            if ($excludeRentalId !== null) {
                $rentals = $rentals->reject(fn ($r) => (int) $r->id === $excludeRentalId)->values();
            }

            if ($rentals->isEmpty()) {
                continue;
            }

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
