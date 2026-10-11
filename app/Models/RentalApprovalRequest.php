<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStore;
use App\Models\Concerns\ExcludesTutorialData;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class RentalApprovalRequest extends Model
{
    use BelongsToStore;
    use ExcludesTutorialData;

    protected $fillable = [
        'store_id',
        'requested_by_user_id',
        'decided_by_user_id',
        'source_shift_session_id',
        'payload',
        'status',
        'decision_note',
        'decided_at',
        'is_tutorial',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'decided_at' => 'datetime',
            'is_tutorial' => 'boolean',
        ];
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by_user_id');
    }

    /**
     * Which Rental (sub)class this approval's rental_id resolves against —
     * Rental for real requests, TutorialRental for tutorial ones (see
     * TutorialRentalApprovalRequest). Lets the shared logic below stay in
     * one place instead of being duplicated per subclass.
     */
    public function rentalModelClass(): string
    {
        return Rental::class;
    }

    /**
     * Outfit IDs staff highlighted for this approval: explicit payload, or (quick edit) items in the snapshot that were not on the saved rent yet.
     *
     * @return array<int, int>
     */
    public function resolvedApprovalFocusOutfitIds(): array
    {
        $payload = $this->payload ?? [];
        $payloadIds = array_values(array_filter(
            array_map('intval', $payload['outfit_ids'] ?? []),
            fn (int $id) => $id > 0
        ));
        $payloadIdSet = array_flip($payloadIds);

        $explicit = [];
        $seenExplicit = [];
        foreach ($payload['approval_focus_outfit_ids'] ?? [] as $raw) {
            $id = (int) $raw;
            if ($id > 0 && isset($payloadIdSet[$id]) && ! isset($seenExplicit[$id])) {
                $seenExplicit[$id] = true;
                $explicit[] = $id;
            }
        }
        if ($explicit !== []) {
            return $explicit;
        }

        $kind = (string) ($payload['kind'] ?? 'create');
        $editRentalId = isset($payload['rental_id']) ? (int) $payload['rental_id'] : null;
        if ($kind !== 'edit' || $editRentalId <= 0) {
            return [];
        }

        $rentalClass = $this->rentalModelClass();
        $saved = $rentalClass::query()->find($editRentalId);
        if (! $saved || $saved->status !== 'active') {
            return [];
        }

        $existingIds = $saved->outfits->pluck('id')->map(fn ($id) => (int) $id)->all();
        $inferred = [];
        $seenInf = [];
        foreach ($payloadIds as $sid) {
            if (! in_array($sid, $existingIds, true) && ! isset($seenInf[$sid])) {
                $seenInf[$sid] = true;
                $inferred[] = $sid;
            }
        }

        return $inferred;
    }

    /**
     * The saved rents this request's cool-down items clash with (for display).
     * One line per item+rent: code, rental id, customer, period, cool-down end.
     *
     * @return array<int, array{code: string, rental_id: int, customer: string, start_label: string, end_label: string, cooldown_until_label: string}>
     */
    public function cooldownConflictLines(): array
    {
        $payload = $this->payload ?? [];
        $r = $payload['rental'] ?? [];
        $startRaw = (string) ($r['start_date'] ?? '');
        $endRaw = (string) ($r['end_date'] ?? '');
        if ($startRaw === '' || $endRaw === '') {
            return [];
        }

        try {
            $reqStart = \Carbon\Carbon::parse($startRaw)->startOfDay();
            $reqEnd = \Carbon\Carbon::parse($endRaw)->startOfDay();
        } catch (\Throwable) {
            return [];
        }

        $focusIds = $this->resolvedApprovalFocusOutfitIds();
        $outfitIds = $focusIds !== []
            ? $focusIds
            : array_values(array_filter(array_map('intval', $payload['outfit_ids'] ?? []), fn (int $id) => $id > 0));
        if ($outfitIds === []) {
            return [];
        }

        $excludeRentalId = isset($payload['rental_id']) ? (int) $payload['rental_id'] : 0;
        $default = (int) config('app.rental_return_buffer_days', 1);
        $buffer = max(0, (int) \App\Support\StoreCache::get('rental_return_buffer_days', $default));

        $rentalClass = $this->rentalModelClass();
        $codeById = Outfit::query()->whereIn('id', $outfitIds)->pluck('code', 'id');
        $lines = [];
        foreach ($outfitIds as $sid) {
            $conflicts = $rentalClass::query()
                ->where('status', 'active')
                ->whereHas('outfits', fn ($q) => $q->whereKey($sid))
                ->where('start_date', '<=', $reqEnd->copy()->addDays($buffer)->toDateString())
                ->where('end_date', '>=', $reqStart->copy()->subDays($buffer)->toDateString())
                ->when($excludeRentalId > 0, fn ($q) => $q->where('id', '!=', $excludeRentalId))
                ->orderBy('start_date')
                ->get(['id', 'customer_name', 'start_date', 'end_date']);
            foreach ($conflicts as $c) {
                // Placeholder rents store names like "— Pending customer details";
                // strip the leading dash so the "rent #X — {customer}" template doesn't double it.
                $customer = (string) preg_replace('/^[\s\-–—]+/u', '', trim((string) $c->customer_name));
                $lines[] = [
                    'code' => (string) ($codeById->get($sid) ?? '#'.$sid),
                    'rental_id' => (int) $c->id,
                    'customer' => $customer !== '' ? $customer : '—',
                    'start_label' => $c->start_date->format('d M Y'),
                    'end_label' => $c->end_date->format('d M Y'),
                    'cooldown_until_label' => $c->end_date->copy()->addDays($buffer)->format('d M Y'),
                ];
            }
        }

        return $lines;
    }

    /**
     * Field-by-field comparison of this (edit-kind) request's payload against the rental
     * it would be applied to — saved value vs requested value, for showing an approver or
     * the requester "what actually changes" before/after Save. Used by both the approval
     * queue cards (pending-rental-approval-cards.blade.php) and the requester's own
     * "my pending" list + edit-modal highlights (rentals/index.blade.php); previously this
     * logic was duplicated near-identically in both places.
     *
     * @param  array<int, string>  $outfitCodes  Outfit codes for this approval's outfit_ids, in the same order (e.g. $approval->approval_outfit_codes).
     * @return array{
     *     fields: list<array{field: string, label: string, old: string, new: string, changed: bool}>,
     *     changed_field_keys: list<string>,
     *     fields_by_key: array<string, array{old: string, new: string}>,
     *     items: array{label: string, old: string, new: string, changed: bool}|null,
     *     item_discounts: list<array{outfit_id: int, label: string, old: string, new: string, changed: bool}>,
     *     item_discount_outfit_ids: list<int>,
     *     item_discounts_by_outfit_id: array<int, array{old: string, new: string}>,
     * }
     */
    public function diffAgainstSavedRental(?Rental $savedRental, array $outfitCodes = []): array
    {
        $empty = [
            'fields' => [],
            'changed_field_keys' => [],
            'fields_by_key' => [],
            'items' => null,
            'item_discounts' => [],
            'item_discount_outfit_ids' => [],
            'item_discounts_by_outfit_id' => [],
        ];
        if (! $savedRental) {
            return $empty;
        }

        $payload = $this->payload ?? [];
        $r = $payload['rental'] ?? [];
        $trackedFields = ['customer_name', 'phone', 'instagram', 'notes', 'payment_method', 'payment_kind', 'amount_paid', 'discount', 'discount_type', 'discount_note', 'extra_charge', 'start_date', 'end_date'];

        $fields = [];
        $changedFieldKeys = [];
        $fieldsByKey = [];
        foreach ($trackedFields as $field) {
            if (! array_key_exists($field, $r)) {
                continue;
            }
            $oldVal = $savedRental->{$field};
            $newVal = $r[$field];
            if (in_array($field, ['amount_paid', 'discount', 'extra_charge'], true)) {
                $changed = round((float) ($oldVal ?? 0), 2) !== round((float) ($newVal ?? 0), 2);
            } elseif (in_array($field, ['start_date', 'end_date'], true)) {
                $oldDate = $oldVal ? \Carbon\Carbon::parse($oldVal)->toDateString() : null;
                $newDate = $newVal ? \Carbon\Carbon::parse($newVal)->toDateString() : null;
                $changed = $oldDate !== $newDate;
            } else {
                $changed = trim((string) ($oldVal ?? '')) !== trim((string) ($newVal ?? ''));
            }
            $oldLabel = RentalActivityLog::formatChangeValue($field, $oldVal);
            $newLabel = RentalActivityLog::formatChangeValue($field, $newVal);
            $fields[] = [
                'field' => $field,
                'label' => RentalActivityLog::fieldLabel($field),
                'old' => $oldLabel,
                'new' => $newLabel,
                'changed' => $changed,
            ];
            if ($changed) {
                $changedFieldKeys[] = $field;
                $fieldsByKey[$field] = ['old' => $oldLabel, 'new' => $newLabel];
            }
        }

        // Items live in outfit_ids, not a plain rental attribute, so they need their own
        // before/after comparison rather than the tracked-field loop above.
        $savedOutfitIds = $savedRental->outfits->pluck('id')->map('intval')->sort()->values()->all();
        $payloadOutfitIds = collect($payload['outfit_ids'] ?? [])->map('intval')->unique()->sort()->values()->all();
        $items = null;
        if ($savedOutfitIds !== $payloadOutfitIds) {
            $items = [
                'label' => 'Items',
                'old' => $savedRental->outfits->pluck('code')->implode(', ') ?: 'None',
                'new' => count($outfitCodes) ? implode(', ', $outfitCodes) : 'None',
                'changed' => true,
            ];
        }

        // Per-item discounts live in their own payload keys (item_discounts / item_discount_types /
        // item_discount_notes, keyed by outfit id) — not part of the rental sub-array above.
        $payloadItemDiscounts = $payload['item_discounts'] ?? [];
        $payloadItemDiscountTypes = $payload['item_discount_types'] ?? [];
        $payloadItemDiscountNotes = $payload['item_discount_notes'] ?? [];
        $savedOutfitsById = $savedRental->outfits->keyBy('id');
        $commonOutfitIds = array_values(array_intersect($savedOutfitIds, $payloadOutfitIds));
        $itemDiscounts = [];
        $itemDiscountOutfitIds = [];
        $itemDiscountsByOutfitId = [];
        foreach ($commonOutfitIds as $sid) {
            $item = $savedOutfitsById->get($sid);
            if (! $item) {
                continue;
            }
            $oldDiscAmt = round((float) ($item->pivot->discount ?? 0), 2);
            $oldDiscType = $item->pivot->discount_type ?: 'fixed';
            $oldDiscNote = trim((string) ($item->pivot->discount_note ?? ''));
            $newDiscAmt = round((float) ($payloadItemDiscounts[$sid] ?? $payloadItemDiscounts[(string) $sid] ?? 0), 2);
            $newDiscType = $payloadItemDiscountTypes[$sid] ?? $payloadItemDiscountTypes[(string) $sid] ?? 'fixed';
            $newDiscNote = trim((string) ($payloadItemDiscountNotes[$sid] ?? $payloadItemDiscountNotes[(string) $sid] ?? ''));
            if ($oldDiscAmt === $newDiscAmt && $oldDiscType === $newDiscType && $oldDiscNote === $newDiscNote) {
                continue;
            }
            $oldLabel = $oldDiscAmt > 0 ? ($oldDiscType === 'percent' ? $oldDiscAmt.'%' : 'Rp '.number_format($oldDiscAmt, 0, ',', '.')) : 'No discount';
            $newLabel = $newDiscAmt > 0 ? ($newDiscType === 'percent' ? $newDiscAmt.'%' : 'Rp '.number_format($newDiscAmt, 0, ',', '.')) : 'No discount';
            if ($newDiscNote !== '') {
                $newLabel .= ' ("'.$newDiscNote.'")';
            }
            $itemDiscounts[] = [
                'outfit_id' => $sid,
                'label' => 'Discount on '.$item->code,
                'old' => $oldLabel,
                'new' => $newLabel,
                'changed' => true,
            ];
            $itemDiscountOutfitIds[] = $sid;
            $itemDiscountsByOutfitId[$sid] = ['old' => $oldLabel, 'new' => $newLabel];
        }

        return [
            'fields' => $fields,
            'changed_field_keys' => $changedFieldKeys,
            'fields_by_key' => $fieldsByKey,
            'items' => $items,
            'item_discounts' => $itemDiscounts,
            'item_discount_outfit_ids' => $itemDiscountOutfitIds,
            'item_discounts_by_outfit_id' => $itemDiscountsByOutfitId,
        ];
    }

    /**
     * Full snapshot for JSON clients and inline “my pending” UI after a request is queued.
     *
     * @param  Collection<int, string>|null  $codeById  Preloaded outfit id => code map (avoids N+1 when building many rows).
     * @return array<string, mixed>
     */
    public function summaryForJson(?Collection $codeById = null): array
    {
        $payload = $this->payload ?? [];
        $rental = $payload['rental'] ?? [];
        $outfitIds = array_values(array_unique(array_map('intval', $payload['outfit_ids'] ?? [])));
        /** @var Collection<int, string> $codeById */
        if ($codeById === null) {
            $codeById = $outfitIds === []
                ? collect()
                : Outfit::query()->whereIn('id', $outfitIds)->pluck('code', 'id');
        }
        $outfitCodes = collect($outfitIds)
            ->map(fn (int $id) => $codeById->get($id) ?? '#'.$id)
            ->values()
            ->all();
        $approvalFocusOutfitIds = $this->resolvedApprovalFocusOutfitIds();
        $approvalFocusOutfitIds = array_values(array_filter(
            $approvalFocusOutfitIds,
            fn (int $id) => $id > 0 && in_array($id, $outfitIds, true)
        ));
        $approvalFocusOutfitCodes = collect($approvalFocusOutfitIds)
            ->map(fn (int $id) => $codeById->get($id) ?? '#'.$id)
            ->values()
            ->all();
        $customerPending = (bool) ($rental['customer_pending'] ?? false);
        $kind = (string) ($payload['kind'] ?? 'create');
        $editRentalId = isset($payload['rental_id']) ? (int) $payload['rental_id'] : null;

        return [
            'approval_id' => $this->id,
            'kind' => $kind,
            'rental_id' => $editRentalId > 0 ? $editRentalId : null,
            'items_only' => $customerPending,
            'customer_pending' => $customerPending,
            'outfit_ids' => $outfitIds,
            'outfit_codes' => $outfitCodes,
            'approval_focus_outfit_ids' => $approvalFocusOutfitIds,
            'approval_focus_outfit_codes' => $approvalFocusOutfitCodes,
            'rental' => $rental,
            'item_discounts' => $payload['item_discounts'] ?? [],
            'item_discount_types' => $payload['item_discount_types'] ?? [],
            'customer_name' => (string) ($rental['customer_name'] ?? ''),
            'phone' => (string) ($rental['phone'] ?? ''),
            'instagram' => $rental['instagram'] ?? null,
            'notes' => (string) ($rental['notes'] ?? $rental['address'] ?? ''),
            'address' => (string) ($rental['address'] ?? ''),
            'payment_method' => $rental['payment_method'] ?? null,
            'discount' => $rental['discount'] ?? null,
            'discount_type' => $rental['discount_type'] ?? 'fixed',
            'extra_charge' => $rental['extra_charge'] ?? null,
            'start_date' => (string) ($rental['start_date'] ?? ''),
            'end_date' => (string) ($rental['end_date'] ?? ''),
        ];
    }
}
