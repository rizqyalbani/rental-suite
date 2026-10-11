@php
    $rentalData = $rentalData ?? (is_array($rental ?? null) ? $rental : []);

    $pricingEnabled = \App\Support\StoreCache::get('pricing_enabled', true);
    $pricingPricesIncludeTax = (bool) \App\Support\StoreCache::get('pricing_prices_include_tax', false);

    $status = (string) ($rentalData['status'] ?? '');
    $pickedUp = (bool) ($rentalData['picked_up'] ?? false);
    $overdue = (bool) ($rentalData['overdue'] ?? false);

    $startYmd = (string) ($rentalData['start_date'] ?? '');
    $endYmd = (string) ($rentalData['end_date'] ?? '');
    try {
        $start = $startYmd !== '' ? \Carbon\Carbon::createFromFormat('Y-m-d', $startYmd)->startOfDay() : null;
        $end = $endYmd !== '' ? \Carbon\Carbon::createFromFormat('Y-m-d', $endYmd)->startOfDay() : null;
    } catch (\Throwable) {
        $start = null;
        $end = null;
    }
    $rentalDays = ($start && $end) ? max(1, $start->diffInDays($end) + 1) : 1;
    $billingDays = isset($rentalData['billing_days']) && is_int($rentalData['billing_days'])
        ? $rentalData['billing_days']
        : $rentalDays;
    $pricingMode = (string) ($rentalData['pricing_mode'] ?? 'block');
    $extraDaysCount = (int) ($rentalData['extra_days'] ?? 0);
    $billingBlocks = isset($rentalData['billing_blocks']) ? (int) $rentalData['billing_blocks'] : intdiv(max(3, $billingDays), 3);

    $statusBadge = $status === 'active'
        ? '<span class="px-2 py-0.5 text-xs font-semibold rounded-md border border-green-300 bg-green-100 text-green-800">Active</span>'
        : ($status === 'returned'
            ? '<span class="px-2 py-0.5 text-xs font-semibold rounded-md border border-gray-400 bg-gray-100 text-gray-800">Returned</span>'
            : '<span class="px-2 py-0.5 text-xs font-semibold rounded-md border border-red-300 bg-red-100 text-red-800">Cancelled</span>');

    $pickupBadge = '';
    if ($status === 'active') {
        $pickupBadge = $pickedUp
            ? '<span class="px-2 py-0.5 text-xs font-semibold rounded-md border border-blue-300 bg-blue-100 text-blue-800">Picked up</span>'
            : '<span class="px-2 py-0.5 text-xs font-semibold rounded-md border border-amber-300 bg-amber-100 text-amber-800">Waiting pickup</span>';
    }

    $paymentKind = $pricingEnabled ? (string) ($rentalData['payment_kind'] ?? '') : '';
    $paymentLabel = null;
    $paymentBadge = '';
    if ($pricingEnabled && $paymentKind !== '') {
        if ($paymentKind === 'down_payment') {
            $paymentLabel = 'Down payment';
        } elseif ($paymentKind === 'full') {
            $paymentLabel = 'Full Payment';
        } elseif ($paymentKind === 'final_payment') {
            $paymentLabel = 'Final Payment';
        } else {
            $paymentLabel = ucwords(str_replace('_', ' ', $paymentKind));
        }
        $cls = in_array($paymentKind, ['full', 'final_payment'], true)
            ? 'border-emerald-300 bg-emerald-100 text-emerald-800'
            : ($paymentKind === 'down_payment'
                ? 'border-amber-300 bg-amber-100 text-amber-800'
                : 'border-gray-300 bg-gray-100 text-gray-800');
        $paymentBadge = '<span class="px-2 py-0.5 text-xs font-semibold rounded-md border '.$cls.'">'.e($paymentLabel).'</span>';
    }

    // Approved Free request: FREE replaces the payment badge (a "Down payment"
    // label on a Rp 0 rent would mislead), with the story in one line below.
    $free = is_array($rentalData['free'] ?? null) ? $rentalData['free'] : null;
    if ($free) {
        $paymentBadge = '<span class="px-2 py-0.5 text-xs font-bold tracking-wide rounded-md border border-transparent bg-emerald-600 text-white" title="'
            .e('Free — approved by '.($free['approved_by'] ?? '?').', requested by '.($free['requested_by'] ?? '?').'. Reason: '.($free['reason'] ?? '')).'">FREE</span>';
    }

    $items = is_array($rentalData['outfits'] ?? null) ? $rentalData['outfits'] : [];
    $itemLines = [];
    foreach ($items as $s) {
        if (! is_array($s)) {
            continue;
        }
        $line = e((string) ($s['code'] ?? ''));
        $newCode = trim((string) ($s['new_code'] ?? ''));
        if ($newCode !== '') {
            $line .= ' | '.e($newCode);
        }
        if ($pricingEnabled && ($s['price'] ?? null) !== null) {
            $unit = (float) $s['price'];
            if ($pricingMode === 'block') {
                $gross = $unit * $billingBlocks;
                $line .= ' - Rp '.number_format($unit, 0, ',', '.').'/3 day';
                if ($billingBlocks > 1) {
                    $line .= ' × '.$billingBlocks;
                }
                $line .= ' = Rp '.number_format($gross, 0, ',', '.');
            } else {
                $gross = $unit * $billingDays;
                $line .= ' - Rp '.number_format($unit, 0, ',', '.').'/day';
                if ($billingDays > 1) {
                    $line .= ' × '.$billingDays.' = Rp '.number_format($gross, 0, ',', '.');
                }
            }
            $pivotDiscount = (float) ($s['pivot_discount'] ?? 0);
            if ($pivotDiscount > 0) {
                $dtype = (string) ($s['pivot_discount_type'] ?? 'fixed');
                $ep = $dtype === 'percent'
                    ? $gross * (1 - $pivotDiscount / 100)
                    : $gross - $pivotDiscount;
                $line .= ' → Rp '.number_format(max(0, $ep), 0, ',', '.');
            }
        }
        $itemLines[] = $line;
    }
    $itemsHtml = implode('<br>', $itemLines);

    $extraCharge = $pricingEnabled && isset($rentalData['extra_charge']) && is_numeric($rentalData['extra_charge']) && (float) $rentalData['extra_charge'] > 0
        ? (float) $rentalData['extra_charge']
        : null;
    $hasTotal = $pricingEnabled && array_key_exists('total_price', $rentalData) && $rentalData['total_price'] !== null && $rentalData['total_price'] !== '' && is_numeric($rentalData['total_price']);
    $hasPaid = $pricingEnabled && array_key_exists('amount_paid', $rentalData) && $rentalData['amount_paid'] !== null && $rentalData['amount_paid'] !== '' && is_numeric($rentalData['amount_paid']);
    $totalDue = $hasTotal ? (float) $rentalData['total_price'] : 0;
    $amountPaidRaw = $hasPaid ? (float) $rentalData['amount_paid'] : 0;

    // The rent side of amount_paid is never capped at save time (unlike overdue_amount_paid,
    // which is already capped — see RentalController::update), so it can include change the
    // customer already received. Cap the displayed figure at the rent-only bill so this row
    // never shows more than the store actually kept, and surface overdue paid + any real
    // remaining/change against the combined total — matching the edit modal's ledger.
    $overdueFeeAmt = $pricingEnabled && isset($rentalData['overdue_fee_amount']) && is_numeric($rentalData['overdue_fee_amount']) ? (float) $rentalData['overdue_fee_amount'] : 0.0;
    $overduePaidAmt = $pricingEnabled && isset($rentalData['overdue_amount_paid']) && is_numeric($rentalData['overdue_amount_paid']) ? (float) $rentalData['overdue_amount_paid'] : 0.0;
    $overdueChangeAmt = $pricingEnabled && isset($rentalData['overdue_change_amount']) && is_numeric($rentalData['overdue_change_amount']) ? (float) $rentalData['overdue_change_amount'] : 0.0;
    $rentOnlyDue = max(0, $totalDue - $overdueFeeAmt);
    $amountPaid = $hasPaid ? min($amountPaidRaw, $rentOnlyDue) : 0;
    $totalKept = $amountPaid + $overduePaidAmt;
    $totalHandedOver = $amountPaidRaw + $overduePaidAmt + $overdueChangeAmt;
@endphp

<div class="bg-white border border-gray-200 rounded-lg shadow-sm{{ $overdue ? ' border-l-4 border-l-red-500' : '' }}">
    <div class="p-3 sm:p-4">
        <div class="flex items-start justify-between gap-2 mb-2">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-2 flex-wrap">
                    <span class="text-xs font-mono font-medium text-gray-500 bg-gray-100 border border-gray-200 rounded px-1.5 py-0.5 shrink-0">#{{ $rentalData['id'] ?? '' }}</span>
                    <span class="font-semibold text-gray-900">{{ $rentalData['customer_name'] ?? '' }}</span>
                    {!! $statusBadge !!}
                    {!! $pickupBadge !!}
                    {!! $paymentBadge !!}
                </div>
            </div>
            <div class="flex items-center gap-1 shrink-0 text-xs sm:text-sm flex-wrap justify-end">
                @include('rentals.partials.action-links', [
                    'rentalData' => $rentalData,
                    'mayOperateRentTransactions' => $mayOperateRentTransactions,
                    'mayDeleteRentals' => $mayDeleteRentals,
                    'rentalMutationsLocked' => $rentalMutationsLocked,
                    'mayViewActivityLog' => $mayViewActivityLog ?? false,
                ])
            </div>
        </div>

        @if($free)
            <div class="mb-2 rounded-md border border-emerald-200 bg-emerald-50 px-2.5 py-1.5 text-xs text-emerald-900">
                <span class="font-semibold">Free rent</span>
                (was Rp {{ number_format((float) ($free['rent_total_before'] ?? 0), 0, ',', '.') }})
                · approved by <span class="font-medium">{{ $free['approved_by'] ?? '—' }}</span>
                · requested by {{ $free['requested_by'] ?? '—' }}
                · {{ $free['approved_at'] ?? '' }}
                @if(($free['reason'] ?? '') !== '')<span class="block text-emerald-700">Reason: {{ $free['reason'] }}</span>@endif
                @if(($free['refund_amount'] ?? 0) > 0)<span class="block text-emerald-700">Refunded to customer: Rp {{ number_format((float) $free['refund_amount'], 0, ',', '.') }}</span>@endif
            </div>
        @endif

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1 text-sm text-gray-600">
            <div class="sm:col-span-2">
                <span class="font-medium text-gray-700">Items:</span><br>
                <span class="text-xs leading-relaxed">{!! $itemsHtml !== '' ? $itemsHtml : '—' !!}</span>
            </div>
            <div><span class="font-medium text-gray-700">Phone:</span> {{ $rentalData['phone'] ?? '' }}</div>
            <div><span class="font-medium text-gray-700">Instagram:</span> {!! ! empty($rentalData['instagram']) ? '@'.e((string) $rentalData['instagram']) : '-' !!}</div>
            <div><span class="font-medium text-gray-700">Notes:</span> {{ ($rentalData['notes'] ?? ($rentalData['address'] ?? '')) ?: '-' }}</div>

            @if($extraCharge !== null)
                <div class="text-[calc(0.875rem-1px)]">
                    <span class="font-medium text-gray-700">Extra ({{ $extraDaysCount }} day{{ $extraDaysCount === 1 ? '' : 's' }}):</span>
                    <span class="tabular-nums">Rp {{ number_format($extraCharge, 0, ',', '.') }}</span>
                </div>
            @endif

            @if($hasTotal)
                <div class="text-base">
                    <span class="font-semibold text-gray-700">{{ $pricingPricesIncludeTax ? 'Total due:' : 'Total due (incl. tax):' }}</span>
                    <span class="font-bold text-indigo-900">Rp {{ number_format($totalDue, 0, ',', '.') }}</span>
                    <div class="mt-0.5 text-[11px] text-gray-400">Net (excl. tax) after discounts, incl. overdue fee</div>
                </div>
            @endif

            @if($hasPaid)
                <div class="text-[calc(0.875rem-1px)]">
                    <span class="font-medium text-gray-700">
                        {{ $paymentKind === 'down_payment' ? 'Down payment:' : ($paymentKind === 'full' ? 'Paid off:' : 'Amount paid:') }}
                    </span>
                    <span class="tabular-nums">Rp {{ number_format($amountPaid, 0, ',', '.') }}</span>
                </div>
            @endif

            @if($overduePaidAmt > 0.004)
                <div class="text-[calc(0.875rem-1px)]">
                    <span class="font-medium text-gray-700">Overdue paid:</span>
                    <span class="tabular-nums">Rp {{ number_format($overduePaidAmt, 0, ',', '.') }}</span>
                </div>
            @endif

            @if($hasTotal && ($hasPaid || $overduePaidAmt > 0.004) && $totalDue - $totalKept > 0.004)
                <div class="text-[calc(0.875rem-1px)]">
                    <span class="font-medium text-gray-700">Remaining:</span>
                    <span class="font-semibold tabular-nums text-amber-900">Rp {{ number_format($totalDue - $totalKept, 0, ',', '.') }}</span>
                </div>
            @endif

            @if($pricingEnabled && $paymentLabel)
                <div class="text-[calc(0.875rem-1px)]">
                    <span class="font-medium text-gray-700">Payment:</span>
                    <span class="font-semibold">{{ $paymentLabel }}</span>
                </div>
            @endif

            @if($hasTotal && $totalHandedOver > $totalDue + 0.004)
                {{-- An approved item removal never adjusts amount_paid, so this
                     same "paid more than owed" condition can also mean "an item
                     was removed after payment, customer is owed a refund" —
                     a completely different situation from ordinary checkout
                     change, and confusing to label the same way (see
                     RentalController::search()'s has_item_removal_approved). --}}
                @php $isRefundDue = ! empty($rentalData['has_item_removal_approved']); @endphp
                <div class="text-[calc(0.875rem-1px)] {{ $isRefundDue ? 'text-red-800' : 'text-emerald-900' }}">
                    <span class="font-medium text-gray-700">{{ $isRefundDue ? 'Refund due (item removed):' : 'Change:' }}</span>
                    <span class="font-semibold tabular-nums">Rp {{ number_format($totalHandedOver - $totalDue, 0, ',', '.') }}</span>
                </div>
            @endif
        </div>

        <div class="text-sm text-gray-500 mt-1">
            {{ $rentalData['start_label'] ?? '' }} - {{ $rentalData['end_label'] ?? '' }} · {{ $billingDays }} {{ $billingDays === 1 ? 'day' : 'days' }}@if($pricingMode === 'block' && $extraDaysCount > 0) <span class="text-amber-700 font-medium">+{{ $extraDaysCount }} {{ $extraDaysCount === 1 ? 'day' : 'days' }} additional</span>@endif
        </div>

        <div class="mt-0.5 text-xs text-gray-400">Created: {{ $rentalData['created_label'] ?? ($rentalData['created_at'] ?? '—') }}@if(! empty($rentalData['created_by_name'])) · by {{ $rentalData['created_by_name'] }}@endif</div>

        @if($overdue)
            <div class="mt-1 text-xs font-semibold text-red-700 js-overdue-duration" data-end-ymd="{{ $rentalData['end_date'] ?? '' }}" aria-live="polite">{{ $rentalData['overdue_human'] ?? '' }}</div>
        @endif
    </div>
</div>
