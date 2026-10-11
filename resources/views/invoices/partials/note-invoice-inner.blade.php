@php
    $pricingEnabled = \App\Support\StoreCache::get('pricing_enabled', false);
    $pricingSummary = $pricingSummary ?? null;
    $taxPercent = (float) (($pricingSummary['tax_percent'] ?? 0));
    $subtotal = (float) (($pricingSummary['subtotal'] ?? 0));
    $rentalDiscountAmount = (float) (($pricingSummary['rental_discount_amount'] ?? 0));
    $taxAmount = (float) (($pricingSummary['tax_amount'] ?? 0));
    $grandTotal = (float) (($pricingSummary['grand_total'] ?? 0));
    $overdueFeeAmount = (float) (($pricingSummary['overdue_fee_amount'] ?? 0));
    $overdueFeeRate = (float) (($pricingSummary['overdue_fee_rate'] ?? ($rental->overdue_fee_rate ?? 0)));
    $overdueFeeDaysResolved = (int) (($pricingSummary['overdue_fee_days'] ?? ($rental->overdue_fee_days ?? 0)));
    $preTaxNet = (float) (($pricingSummary['pre_tax_net'] ?? max(0, $subtotal - $rentalDiscountAmount)));
    $amountPaid = (float) (($pricingSummary['amount_paid'] ?? 0));
    $rentPaid = (float) (($pricingSummary['rent_amount_paid'] ?? $amountPaid));
    $overduePaid = (float) (($pricingSummary['overdue_amount_paid'] ?? 0));
    $overdueChangeAmount = (float) (($pricingSummary['overdue_change_amount'] ?? 0));
    $overdueCashPaid = $overduePaid + $overdueChangeAmount;
    $customerPaid = $rentPaid + $overduePaid + $overdueChangeAmount;
    $rentOnlyTotal = max(0.0, $grandTotal - $overdueFeeAmount);
    $rentChangeAmount = max(0.0, $rentPaid - $rentOnlyTotal);
    $rentBalanceDueOnly = max(0.0, $rentOnlyTotal - $rentPaid);
    $balanceDue = (float) (($pricingSummary['balance_due'] ?? max(0, $grandTotal - $amountPaid)));
    $overdueBalanceDue = (float) (($pricingSummary['overdue_balance_due'] ?? max(0, $overdueFeeAmount - $overduePaid)));
    $changeAmount = max(0.0, $customerPaid - $grandTotal);
    // Same change-vs-refund split the edit modal and activity log already
    // show for an approved item removal — without this the receipt lumps a
    // removal's refund into one undifferentiated "Change" line. Only trust
    // the stored split if it still adds up to the current total change
    // (guards against a stale split if amount_paid changed since that
    // removal was approved — same check the edit modal uses).
    $itemRemovalRefundAmount = (float) (($pricingSummary['item_removal_refund_amount'] ?? 0));
    $itemRemovalPortionOfChange = (float) (($pricingSummary['item_removal_change_amount'] ?? 0));
    $trustItemRemovalSplit = $itemRemovalRefundAmount > 0.009
        && abs(($itemRemovalPortionOfChange + $itemRemovalRefundAmount) - $changeAmount) <= 1;
    $rentalDays = (int) (($pricingSummary['rental_days'] ?? 1));
    $extraDays = (int) (($pricingSummary['extra_days'] ?? 0));
    $extraCharge = (float) (($pricingSummary['extra_charge'] ?? 0));
    $payLabel = match ($rental->payment_method ?? '') {
        'cash' => 'Cash',
        'transfer' => 'Transfer',
        'qris' => 'QRIS',
        'edc' => 'EDC',
        'other' => 'Other',
        default => $rental->payment_method ? \Illuminate\Support\Str::headline((string) $rental->payment_method) : null,
    };
    $overduePayLabel = match (($pricingSummary['overdue_payment_method'] ?? '') ?: '') {
        'cash' => 'Cash',
        'transfer' => 'Transfer',
        'qris' => 'QRIS',
        'edc' => 'EDC',
        'other' => 'Other',
        default => ($pricingSummary['overdue_payment_method'] ?? null)
            ? \Illuminate\Support\Str::headline((string) $pricingSummary['overdue_payment_method'])
            : null,
    };
    $brandName = strtoupper($rental->store?->name ?? 'DEMO STORE ONE');
    $settled = ! $pricingEnabled || $balanceDue <= 0.004;
    $docLabel = 'Rent Receipt';
    $tz = config('app.timezone');
    $now = \Illuminate\Support\Carbon::now($tz);
    $endDate = \Illuminate\Support\Carbon::parse($rental->end_date, $tz);
    $isOverdueContext = $pricingEnabled && ($rental->status === 'active') && $endDate->copy()->startOfDay()->lt($now->copy()->startOfDay());
    $overdueSeconds = $isOverdueContext ? \App\Support\OverdueDuration::overdueSecondsForEndDate($endDate, $now) : 0;
    $overdueHuman = $isOverdueContext ? \App\Support\OverdueDuration::formatSeconds($overdueSeconds) : null;
    if ($isOverdueContext && ($overdueHuman === null || trim($overdueHuman) === '')) {
        $days = max(1, $endDate->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()));
        $overdueHuman = number_format($days, 0, ',', '.').' '.($days === 1 ? 'day overdue' : 'days overdue');
    }
    if ($overdueFeeDaysResolved <= 0) {
        if ($overdueFeeRate > 0.009 && $overdueFeeAmount > 0.009) {
            $overdueFeeDaysResolved = (int) max(1, round($overdueFeeAmount / $overdueFeeRate));
        } elseif ($isOverdueContext) {
            $overdueFeeDaysResolved = (int) max(1, $endDate->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()));
        }
    }
    $storeAdminPhone1 = trim((string) ($storeAdminPhone1 ?? $storeContactPhone ?? ''));
    $storeAdminPhone2 = trim((string) ($storeAdminPhone2 ?? ''));
    $storeAddress = trim((string) ($storeContactAddress ?? ''));
    $accent = trim((string) ($storePrimaryColor ?? ''));
    if ($accent !== '' && $accent[0] !== '#') {
        $accent = '#'.$accent;
    }
    $accent = preg_match('/^#[0-9a-fA-F]{6}$/', $accent) ? strtolower($accent) : '';
@endphp
<div class="invoice-note-root" @if($accent !== '') style="--store-accent: {{ $accent }};" @endif>
    <div class="note-paper">
        <div class="header">
            @if(! empty($storeLogoDataUri ?? null))
                <img src="{{ $storeLogoDataUri }}" alt="Store logo" style="max-width: 110px; max-height: 64px; margin: 0 auto 10px; display: block;">
            @endif
            <h1 class="title">{{ $brandName }}</h1>
            <div class="brand">{{ $docLabel }}</div>
            <div class="meta">
                @if(! empty($rental->invoice_number))
                    No. {{ $rental->invoice_number }} •
                @endif
                Rent #{{ $rental->id }} • {{ now()->format('d M Y H:i') }}
            </div>
            <div class="meta">Created: {{ $rental->created_at?->format('d M Y H:i') ?? '-' }}</div>
            @if($isOverdueContext)
                @php
                    $ovBits = [];
                    $ovBits[] = 'Overdue';
                    if ($overdueHuman) $ovBits[] = $overdueHuman;
                    if ($overdueFeeAmount > 0.009) {
                        if ($overdueBalanceDue > 0.009) $ovBits[] = 'Overdue due Rp '.number_format($overdueBalanceDue, 0, ',', '.');
                        else $ovBits[] = 'Settled';
                    }
                    if ($overdueChangeAmount > 0.009) $ovBits[] = 'Change Rp '.number_format($overdueChangeAmount, 0, ',', '.');
                @endphp
                <div class="meta" style="margin-top:6px;color:#92400e;font-weight:700;">{{ implode(' • ', $ovBits) }}</div>
            @endif
            @if($storeAdminPhone1 !== '' || $storeAdminPhone2 !== '')
                <div style="display:flex;gap:14px;justify-content:center;flex-wrap:wrap;">
                    @if($storeAdminPhone1 !== '')
                        <div class="meta" style="margin:0;">Admin 1: {{ $storeAdminPhone1 }}</div>
                    @endif
                    @if($storeAdminPhone2 !== '')
                        <div class="meta" style="margin:0;">Admin 2: {{ $storeAdminPhone2 }}</div>
                    @endif
                </div>
            @endif
            @if($storeAddress !== '')
                <div class="meta">{{ $storeAddress }}</div>
            @endif
        </div>

        <div class="block">
            <div class="label">Customer</div>
            <div class="value">{{ $rental->customer_name ?: '-' }}</div>
            <div class="value-meta">{{ $rental->phone ?: '-' }}</div>
        </div>

        @if($rental->handledBy)
            @php
                $hb = $rental->handledBy;
                $hbRoleLabel = $hb->role ? \Illuminate\Support\Str::headline($hb->role) : '';
                $hbShowRoleLabel = $hbRoleLabel !== '' && strcasecmp(trim($hb->name), $hbRoleLabel) !== 0;
            @endphp
        <div class="block">
            <div class="label">Handled by</div>
            <div class="value">{{ $hb->name }}</div>
            @if($hbShowRoleLabel)
                <div class="value-meta">{{ $hbRoleLabel }}</div>
            @endif
        </div>
        @endif

        <div class="block">
            <div class="label">Items</div>
            <table>
                <thead>
                    <tr>
                        <th>Item Code</th>
                        <th class="text-right">Price</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach(($pricingSummary['items'] ?? []) as $item)
                        <tr>
                            <td>{{ $item['code'] ?? '—' }}</td>
                            <td class="text-right">
                                @if($pricingEnabled)
                                    @php
                                        $unit = (float) ($item['unit_price_per_day'] ?? 0);
                                        $days = (int) ($item['days'] ?? $rentalDays ?? 1);
                                        $lineGross = (float) ($item['line_gross'] ?? ($unit * max(1, $days)));
                                        $lineNet = (float) ($item['line_net'] ?? $lineGross);
                                        $hasItemDiscount = ((float) ($item['item_discount'] ?? 0)) > 0.009;
                                    @endphp
                                    <div>Rp {{ number_format($unit, 0, ',', '.') }}/day</div>
                                    <div style="font-size:12px;color:#64748b;">
                                        × {{ number_format(max(1, $days), 0, ',', '.') }} {{ max(1, $days) === 1 ? 'day' : 'days' }}
                                    </div>
                                    @if($hasItemDiscount)
                                        <div style="margin-top:2px;">
                                            <span style="color:#94a3b8;text-decoration:line-through;">Rp {{ number_format($lineGross, 0, ',', '.') }}</span>
                                            <span style="font-weight:700;color:#166534;"> Rp {{ number_format($lineNet, 0, ',', '.') }}</span>
                                        </div>
                                    @else
                                        <div style="margin-top:2px;font-weight:700;">Rp {{ number_format($lineGross, 0, ',', '.') }}</div>
                                    @endif
                                @else
                                    –
                                @endif
                            </td>
                        </tr>
                    @endforeach
                    @if($pricingEnabled)
                    <tr class="row-detail">
                        <td>Subtotal <span style="font-size:12px;color:#94a3b8;font-weight:500;">(after line discounts)</span></td>
                        <td class="text-right">Rp {{ number_format($subtotal, 0, ',', '.') }}</td>
                    </tr>
                    @if($rentalDiscountAmount > 0)
                    <tr class="row-detail">
                        <td>Order discount</td>
                        <td class="text-right">-Rp {{ number_format($rentalDiscountAmount, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                    @if($extraCharge > 0.009)
                    <tr class="row-detail">
                        <td>Additional charge @if($extraDays > 0)<span style="font-size:12px;color:#94a3b8;font-weight:500;">({{ number_format($extraDays, 0, ',', '.') }} {{ $extraDays === 1 ? 'day' : 'days' }})</span>@endif</td>
                        <td class="text-right">Rp {{ number_format($extraCharge, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                    @if($overdueFeeAmount > 0.009)
                    <tr class="row-detail">
                        <td>Overdue fine</td>
                        <td class="text-right">Rp {{ number_format($overdueFeeAmount, 0, ',', '.') }}</td>
                    </tr>
                    @if($overdueFeeDaysResolved > 0)
                    <tr class="row-detail">
                        <td>Overdue days</td>
                        <td class="text-right">{{ number_format(max(1, $overdueFeeDaysResolved), 0, ',', '.') }} {{ max(1, $overdueFeeDaysResolved) === 1 ? 'day' : 'days' }}</td>
                    </tr>
                    @endif
                    @if($overdueFeeRate > 0.009)
                    <tr class="row-detail">
                        <td>Overdue rate</td>
                        <td class="text-right">Rp {{ number_format($overdueFeeRate, 0, ',', '.') }}/day</td>
                    </tr>
                    @endif
                    @endif
                    <tr class="summary-row total-emphasis">
                        <td>Total due</td>
                        <td class="text-right">Rp {{ number_format($grandTotal, 0, ',', '.') }}</td>
                    </tr>
                    @if($payLabel)
                    <tr class="row-detail">
                        <td>Payment method</td>
                        <td class="text-right">{{ $payLabel }}</td>
                    </tr>
                    @endif
                    @if($overdueFeeAmount > 0.009 && $overduePayLabel)
                    <tr class="row-detail">
                        <td>Overdue payment method</td>
                        <td class="text-right">{{ $overduePayLabel }}</td>
                    </tr>
                    @endif
                    @if($pricingEnabled && in_array($rental->payment_kind ?? '', ['down_payment', 'final_payment']) && $rentPaid > 0.009)
                    <tr>
                        <td colspan="2" style="padding:10px 0 4px;">
                            <div style="background:#fffbeb;border:1.5px solid #fcd34d;border-radius:8px;padding:10px 12px;">
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
                                    <span style="font-size:12px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.04em;">{{ ($rental->payment_kind ?? '') === 'final_payment' ? 'Final payment' : 'Down payment paid' }}</span>
                                    <span style="font-size:15px;font-weight:800;color:#78350f;white-space:nowrap;">Rp {{ number_format($rentPaid, 0, ',', '.') }}</span>
                                </div>
                                @if($rentBalanceDueOnly > 0.009)
                                <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:6px;border-top:1px solid #fde68a;padding-top:6px;">
                                    <span style="font-size:12px;font-weight:600;color:#92400e;">Remaining balance</span>
                                    <span style="font-size:13px;font-weight:700;color:#b45309;white-space:nowrap;">Rp {{ number_format($rentBalanceDueOnly, 0, ',', '.') }}</span>
                                </div>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @elseif($rentPaid > 0.009)
                    <tr class="row-detail">
                        <td>Rent paid</td>
                        <td class="text-right">Rp {{ number_format($rentPaid, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                    @if($overdueCashPaid > 0.009)
                    <tr class="row-detail">
                        <td>Overdue paid</td>
                        <td class="text-right">Rp {{ number_format($overdueCashPaid, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                    @if($rentChangeAmount > 0.009 && abs($rentChangeAmount - $changeAmount) > 0.009)
                    <tr class="row-detail">
                        <td>Rent change <span style="font-size:12px;color:#166534;font-weight:600;">(cash to return)</span></td>
                        <td class="text-right">Rp {{ number_format($rentChangeAmount, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                    @if($overdueChangeAmount > 0.009)
                    <tr class="row-detail">
                        <td>Overdue change <span style="font-size:12px;color:#166534;font-weight:600;">(cash to return)</span></td>
                        <td class="text-right">Rp {{ number_format($overdueChangeAmount, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                    @if($overdueFeeAmount > 0.009 && $overdueBalanceDue > 0.009)
                    <tr class="row-detail">
                        <td>Overdue balance due</td>
                        <td class="text-right">Rp {{ number_format($overdueBalanceDue, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                    @if($customerPaid > 0.009 && ($overdueFeeAmount > 0.009 || $overduePaid > 0.009 || $overdueChangeAmount > 0.009))
                    <tr class="row-detail">
                        <td>Customer paid</td>
                        <td class="text-right">Rp {{ number_format($customerPaid, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                    @if($balanceDue > 0.009)
                    <tr class="row-detail">
                        <td>Balance due</td>
                        <td class="text-right">Rp {{ number_format($balanceDue, 0, ',', '.') }}</td>
                    </tr>
                    @elseif($trustItemRemovalSplit)
                        @if($itemRemovalPortionOfChange > 0.009)
                        <tr class="row-detail">
                            <td>Change <span style="font-size:12px;color:#166534;font-weight:600;">(cash to return)</span></td>
                            <td class="text-right">Rp {{ number_format($itemRemovalPortionOfChange, 0, ',', '.') }}</td>
                        </tr>
                        @endif
                        <tr class="summary-row total-emphasis">
                            <td>Refund due <span style="font-size:12px;color:#991b1b;font-weight:600;">(item removed)</span></td>
                            <td class="text-right">Rp {{ number_format($itemRemovalRefundAmount, 0, ',', '.') }}</td>
                        </tr>
                    @elseif($changeAmount > 0.009)
                    <tr class="summary-row total-emphasis">
                        <td>{{ ($rentChangeAmount > 0.009 || $overdueChangeAmount > 0.009) ? 'Total change' : 'Change' }} <span style="font-size:12px;color:#166534;font-weight:600;">(cash to return)</span></td>
                        <td class="text-right">Rp {{ number_format($changeAmount, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                    <tr class="row-detail">
                        <td colspan="2" style="border-bottom:1px solid #e5e7eb;padding-top:10px;">
                            <span style="color:#475569;font-size:14px;">Rent period</span>
                            <div class="meta" style="margin-top:4px;">
                                {{ \Illuminate\Support\Carbon::parse($rental->start_date)->format('d M Y') }}
                                – {{ \Illuminate\Support\Carbon::parse($rental->end_date)->format('d M Y') }}
                                @php $calendarDaysDisplay = max(1, $rentalDays + $extraDays); @endphp
                                · {{ number_format($calendarDaysDisplay, 0, ',', '.') }} {{ $calendarDaysDisplay === 1 ? 'day' : 'days' }}
                            </div>
                        </td>
                    </tr>
                    @else
                    <tr class="row-detail">
                        <td colspan="2" style="border-bottom:1px solid #e5e7eb;padding-top:10px;">
                            <span style="color:#475569;font-size:14px;">Rent period</span>
                            <div class="meta" style="margin-top:4px;">
                                {{ \Illuminate\Support\Carbon::parse($rental->start_date)->format('d M Y') }}
                                – {{ \Illuminate\Support\Carbon::parse($rental->end_date)->format('d M Y') }}
                            </div>
                        </td>
                    </tr>
                    <tr class="summary-row total-emphasis">
                        <td>Total</td>
                        <td class="text-right">Rp {{ number_format(0, 0, ',', '.') }}</td>
                    </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>
</div>
