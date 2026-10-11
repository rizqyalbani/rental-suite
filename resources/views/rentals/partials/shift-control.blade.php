@error('shift')
    <div class="mb-4 bg-amber-50 border border-amber-300 text-amber-900 px-4 py-3 rounded relative text-sm">
        {{ $message }}
    </div>
@enderror

@if($rentalMutationsLocked)
    <div class="mb-4 bg-amber-50 border border-amber-200 text-amber-900 px-4 py-3 rounded text-sm">
        @if(! $mayOperateRentTransactions)
            <span class="font-semibold">View only.</span> Your account cannot create or change rents, open shifts, or send invoices.
        @else
            <span class="font-semibold">Shift required.</span> Open a shift above to create rents, edit transactions, change status, or delete records.
        @endif
    </div>
@endif

@if(Auth::user()->isManagement())
    <div class="mb-4 bg-white border border-gray-200 rounded-lg p-4">
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-2 w-full">
            <h3 class="text-base font-semibold text-gray-800 shrink-0 min-w-0">Open shifts now</h3>
            <p class="text-xs text-gray-500 max-w-xs text-right">Shows which staff account is currently operating.</p>
        </div>
        @php
            $shifts = ($openShifts ?? collect());
        @endphp
        <div class="mt-3">
            @if($shifts->isEmpty())
                <p class="text-sm text-gray-600">No staff has an open shift right now.</p>
            @else
                <ul class="space-y-1.5 text-sm text-gray-800">
                    @foreach($shifts as $s)
                        <li class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1 border-t border-gray-100 first:border-t-0 pt-2 first:pt-0">
                            <div class="min-w-0">
                                <span class="font-semibold text-gray-900">{{ $s->openedBy?->name ?? '—' }}</span>
                                <span class="text-gray-600">· {{ $s->periodLabel() }}</span>
                                @if($s->store)
                                    <span class="text-gray-500">· {{ $s->store->name }}</span>
                                @endif
                            </div>
                            <div class="text-xs text-gray-500 tabular-nums shrink-0">
                                Opened {{ $s->opened_at?->format('d M Y H:i') }}
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>
@else
    @php
        // The Real/Practice tab switch only exists at all while a practice
        // SESSION is ongoing (started automatically by the shift tutorial,
        // ended by "Exit practice") — outside of that, this card is
        // real-only, identical to production, with no toggle visible or
        // reachable at all. Single toggle, single slot while a session IS
        // ongoing: only ONE of Real/Practice is ever shown in the Open
        // Shift / Close Shift row and the body below it, in the exact same
        // position — switching tabs is a pure view preference (never
        // touches data), only "Exit practice" wipes the practice shift.
        $tutorialSessionOngoing = $mayOperateRentTransactions && ($tutorialShiftActive ?? false);
        $isTutorialTab = $tutorialSessionOngoing && (($tutorialView ?? 'latihan') === 'latihan');
    @endphp
    <div id="shiftControlCard" class="mb-4 bg-white border border-gray-200 rounded-lg p-4">
        @error('period')
            <p class="text-xs text-red-600 mb-2">{{ $message }}</p>
        @enderror
        <div class="flex flex-wrap items-center justify-between gap-x-6 gap-y-3 w-full">
            <div class="flex items-center gap-3 min-w-0 flex-wrap">
                <h3 class="text-base font-semibold text-gray-800 shrink-0 min-w-0">Shift Control</h3>
                @if($tutorialSessionOngoing)
                    <div class="inline-flex rounded-md border border-gray-200 p-0.5 bg-gray-50 text-xs font-semibold shrink-0">
                        <form method="POST" action="{{ route('shifts.tutorial.view-real') }}">
                            @csrf
                            <button type="submit" id="shiftViewRealTabBtn" class="px-2.5 py-1 rounded-[5px] cursor-pointer {{ ! $isTutorialTab ? 'bg-white shadow-sm text-gray-900' : 'text-gray-500 hover:text-gray-700' }}">Real</button>
                        </form>
                        <form method="POST" action="{{ route('shifts.tutorial.start') }}">
                            @csrf
                            <button type="submit" id="shiftTutorialStartBtn" class="px-2.5 py-1 rounded-[5px] cursor-pointer {{ $isTutorialTab ? 'bg-indigo-600 text-white shadow-sm' : 'text-gray-500 hover:text-gray-700' }}">Practice</button>
                        </form>
                    </div>
                @endif
            </div>
            <div class="flex items-center gap-2 shrink-0 ml-auto">
                @if(! $isTutorialTab)
                    @if($mayOperateRentTransactions)
                        @if(!$currentShift)
                            <form method="POST" action="{{ route('shifts.open') }}" class="flex flex-wrap items-center justify-end gap-2">
                                @csrf
                                <label for="shift_period" class="sr-only">Shift period</label>
                                <select name="period" id="shift_period" required class="form-select text-sm">
                                    <option value="morning" {{ old('period', 'morning') === 'morning' ? 'selected' : '' }}>Morning</option>
                                    <option value="afternoon" {{ old('period') === 'afternoon' ? 'selected' : '' }}>Afternoon</option>
                                </select>
                                <button type="submit" id="shiftOpenBtn" class="px-3 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">Open Shift</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('shifts.close') }}">
                                @csrf
                                <button type="submit" id="shiftCloseBtn" class="px-3 py-2 bg-emerald-600 text-white rounded-md text-sm font-medium hover:bg-emerald-700 cursor-pointer">Close Shift</button>
                            </form>
                            <a href="{{ route('shifts.report', $currentShift) }}" id="shiftFullReportLink" class="px-3 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">Full report &amp; print</a>
                        @endif
                    @else
                        <p class="text-xs text-gray-500 max-w-xs text-right">Shift open/close is available to Admin staff only.</p>
                    @endif
                @else
                    @if(! $tutorialShift)
                        <form method="POST" action="{{ route('shifts.open') }}" class="flex flex-wrap items-center justify-end gap-2">
                            @csrf
                            <input type="hidden" name="tutorial_practice" value="1">
                            <label for="tutorial_shift_period" class="sr-only">Shift period</label>
                            <select name="period" id="tutorial_shift_period" required class="form-select text-sm">
                                <option value="morning" selected>Morning</option>
                                <option value="afternoon">Afternoon</option>
                            </select>
                            <button type="submit" id="tutorialShiftOpenBtn" class="px-3 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">Open Shift</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('shifts.close') }}">
                            @csrf
                            <input type="hidden" name="tutorial_practice" value="1">
                            <button type="submit" id="tutorialShiftCloseBtn" class="px-3 py-2 bg-emerald-600 text-white rounded-md text-sm font-medium hover:bg-emerald-700 cursor-pointer">Close Shift</button>
                        </form>
                        <a href="{{ route('shifts.tutorial.report', $tutorialShift) }}" id="tutorialShiftFullReportLink" class="px-3 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">Full report &amp; print</a>
                    @endif
                    <form method="POST" action="{{ route('shifts.tutorial.exit') }}">
                        @csrf
                        <button type="submit" id="tutorialShiftExitBtn" class="px-2.5 py-2 text-indigo-700 text-xs font-medium hover:underline cursor-pointer">Exit practice</button>
                    </form>
                @endif
            </div>
        </div>

        <div class="mt-3 w-full min-w-0 space-y-3">
            @if(! $isTutorialTab)
            @if($currentShift)
            <p class="text-sm text-gray-500">Counts all rents recorded on this shift.</p>
            <p class="text-sm text-gray-700 leading-snug">
                <span class="font-semibold text-gray-900">{{ $currentShift->periodLabel() }} shift</span>
                · Opened: {{ $currentShift->opened_at?->format('d M Y H:i') }}
                · {{ (int) ($shiftStats['transactions'] ?? 0) }} rents
                · {{ (int) ($shiftStats['item_count'] ?? 0) }} items out
                · {{ (int) ($shiftStats['picked_up_count'] ?? 0) }} picked up
            </p>
            @php
                $sft = $shiftStats['financial_totals'] ?? [];
                $stp = (float) ($shiftStats['tax_percent'] ?? 0);
                $stax = (float) ($sft['tax'] ?? 0);
                $showTax = $stp > 0.009 && $stax > 0.009;
            @endphp
            <div id="shiftFinancialSummary" class="w-full rounded-lg border border-gray-200 bg-gray-50/90 px-4 py-3.5 text-sm text-gray-800">
                <p class="text-xs font-semibold uppercase tracking-wide text-gray-600 mb-2.5">Payments &amp; discounts</p>
                <ul class="space-y-2 text-sm w-full">
                    <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 text-base font-bold text-emerald-800">
                        <span>Paid</span>
                        <span class="tabular-nums">Rp {{ number_format((float) ($shiftStats['total_received'] ?? 0), 0, ',', '.') }}</span>
                    </li>
                    <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 text-amber-900">
                        <span>Remaining</span>
                        <span class="font-semibold tabular-nums">Rp {{ number_format((float) ($shiftStats['total_outstanding'] ?? 0), 0, ',', '.') }}</span>
                    </li>
                    <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5">
                        <span class="text-gray-600">Item total</span>
                        <span class="font-semibold tabular-nums text-gray-900">Rp {{ number_format((float) ($sft['gross'] ?? 0), 0, ',', '.') }}</span>
                    </li>
                    <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 text-amber-900">
                        <span>Item discounts @if($showTax)<span class="text-amber-800/90">(pre-tax)</span>@endif</span>
                        <span class="font-semibold tabular-nums">−Rp {{ number_format((float) ($sft['item_discounts'] ?? 0), 0, ',', '.') }}</span>
                    </li>
                    <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 text-amber-900">
                        <span>Order discounts @if($showTax)<span class="text-amber-800/90">(pre-tax)</span>@endif</span>
                        <span class="font-semibold tabular-nums">−Rp {{ number_format((float) ($sft['order_discounts'] ?? 0), 0, ',', '.') }}</span>
                    </li>
                    @if($showTax)
                    <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 border-t border-gray-200/80 pt-2 mt-1">
                        <span class="text-gray-600">Subtotal (pre-tax)</span>
                        <span class="font-semibold tabular-nums text-gray-900">Rp {{ number_format((float) ($sft['pre_tax'] ?? 0), 0, ',', '.') }}</span>
                    </li>
                    <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5">
                        <span class="text-gray-600">Tax <span class="text-gray-500">({{ number_format($stp, 2, ',', '.') }}%)</span></span>
                        <span class="font-semibold tabular-nums text-gray-900">Rp {{ number_format((float) ($sft['tax'] ?? 0), 0, ',', '.') }}</span>
                    </li>
                    @endif
                    <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 text-base font-bold text-green-800 pt-1">
                        <span>{{ $showTax ? 'Net incl. tax' : 'Net total' }}</span>
                        <span class="tabular-nums">Rp {{ number_format((float) ($sft['net_incl_tax'] ?? 0), 0, ',', '.') }}</span>
                    </li>
                    <li class="text-[11px] text-gray-400 -mt-1">
                        Billed value of rents recorded this shift (item total − discounts, incl. overdue fee{{ $showTax ? ' and tax' : '' }}) — not the same as <strong>Paid</strong> above, which is cash actually collected.
                    </li>
                </ul>
            </div>
            @if(! empty($shiftStats['details']))
                <details class="w-full rounded-md border border-gray-200 bg-gray-50/80 px-4 py-3 text-sm">
                    <summary class="cursor-pointer font-semibold text-gray-900 select-none text-base">Rent detail this shift</summary>
                    <div class="mt-3 w-full overflow-x-auto max-h-72 overflow-y-auto">
                        <table class="w-full min-w-[640px] text-left text-sm text-gray-800">
                            <thead class="text-xs uppercase text-gray-600 border-b border-gray-200">
                                <tr>
                                    <th class="py-2 pr-3 font-semibold">Customer</th>
                                    <th class="py-2 pr-3 font-semibold">Items</th>
                                    <th class="py-2 pr-3 font-semibold">Dates</th>
                                    <th class="py-2 pr-3 font-semibold">Pickup</th>
                                    <th class="py-2 pr-3 font-semibold">Pay</th>
                                    <th class="py-2 pr-3 font-semibold text-right">Paid</th>
                                    <th class="py-2 pr-3 font-semibold text-right">Remaining</th>
                                    <th class="py-2 pr-3 font-semibold">Handled</th>
                                    <th class="py-2 pr-3 font-semibold">Discounts</th>
                                    <th class="py-2 pr-3 font-semibold text-right">Net</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                @foreach($shiftStats['details'] as $row)
                                    @php
                                        $r = $row['rental'];
                                        $f = $row['financial'];
                                        $paid = (float) ($row['paid'] ?? 0);
                                        $remaining = (float) ($row['outstanding'] ?? 0);
                                        $payKind = (string) ($r->payment_kind ?? '');
                                        $payKindLabel = $payKind === 'down_payment' ? 'DP' : ($payKind === 'full' ? 'Full' : '—');
                                        $payMethod = $r->payment_method ?: '—';
                                        $dpBefore = ! empty($row['from_other_shift']) && $r->down_payment_amount !== null && (float) $r->down_payment_amount > 0.004
                                            ? (float) $r->down_payment_amount
                                            : null;
                                    @endphp
                                    <tr class="align-top">
                                        <td class="py-2 pr-3 whitespace-nowrap">
                                            {{ $r->customer_name }}@if(! empty($row['from_other_shift'])) <span class="ml-1 inline-block rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-800 align-middle">Payment only</span>@endif @if($paid < 0) <span class="ml-1 inline-block rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-800 align-middle">Correction</span>@endif
                                            <div class="text-[10px] font-normal text-gray-400">Created: {{ $r->created_at?->format('d M Y H:i') ?? '—' }}</div>
                                        </td>
                                        <td class="py-2 pr-3 max-w-[14rem] break-words text-gray-700">{!! str_replace('; ', '<br>', e($f['items_display'] ?? '—')) !!}</td>
                                        <td class="py-2 pr-3 whitespace-nowrap text-gray-600">{{ $r->start_date?->format('d M') }} → {{ $r->end_date?->format('d M') }}</td>
                                        <td class="py-2 pr-3 whitespace-nowrap">{{ $r->picked_up_at ? $r->picked_up_at->format('d M H:i') : '—' }}</td>
                                        <td class="py-2 pr-3 whitespace-nowrap">{{ $payMethod }} <span class="text-gray-500 text-xs">({{ $payKindLabel }})</span></td>
                                        <td class="py-2 pr-3 whitespace-nowrap text-right tabular-nums">
                                            Rp {{ number_format($paid, 0, ',', '.') }}
                                            @if($dpBefore !== null)
                                                <div class="text-[10px] font-normal text-gray-500 whitespace-nowrap">DP paid before: Rp {{ number_format($dpBefore, 0, ',', '.') }}</div>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-3 whitespace-nowrap text-right tabular-nums">Rp {{ number_format($remaining, 0, ',', '.') }}</td>
                                        <td class="py-2 pr-3 whitespace-nowrap">{{ $r->handledBy?->name ?? '—' }}</td>
                                        <td class="py-2 pr-3 max-w-[10rem] break-words text-gray-700">{{ $f['discounts_display'] }}</td>
                                        <td class="py-2 pr-3 text-right font-semibold tabular-nums">Rp {{ number_format((float) ($f['net_incl_tax'] ?? 0), 0, ',', '.') }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </details>
            @endif
            @else
            <p class="text-sm text-gray-600">No open shift. Open shift to start daily rent tracking.</p>
            @endif
            @else
            {{-- Practice tab body --}}
            <p class="text-xs text-indigo-700">Practice is safe: it uses example data and does not affect real shifts or reports.</p>
            @if($tutorialShift)
                <p class="text-sm text-indigo-900 leading-snug">
                    <span class="font-semibold">{{ $tutorialShift->periodLabel() }} shift</span>
                    · Opened: {{ $tutorialShift->opened_at?->format('d M Y H:i') }}
                    · {{ (int) ($tutorialShiftStats['transactions'] ?? 0) }} rents
                </p>
                <div id="tutorialShiftFinancialSummary" class="w-full rounded-lg border border-indigo-200 bg-indigo-50/60 px-4 py-3.5 text-sm text-gray-800">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-600 mb-2.5">Payments &amp; discounts</p>
                    <ul class="space-y-2 text-sm w-full">
                        <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 text-base font-bold text-emerald-800">
                            <span>Paid</span>
                            <span class="tabular-nums">Rp {{ number_format((float) ($tutorialShiftStats['total_received'] ?? 0), 0, ',', '.') }}</span>
                        </li>
                        <li class="flex flex-wrap justify-between gap-x-4 gap-y-0.5 text-base font-bold text-green-800 pt-1">
                            <span>Net total</span>
                            <span class="tabular-nums">Rp {{ number_format((float) ($tutorialShiftStats['financial_totals']['net_incl_tax'] ?? 0), 0, ',', '.') }}</span>
                        </li>
                    </ul>
                </div>
            @elseif(! empty($tutorialLastClosedShift))
                <p class="text-sm text-indigo-900 leading-snug">
                    <span class="font-semibold">{{ $tutorialLastClosedShift->periodLabel() }} shift</span> (practice)
                    · Closed at {{ $tutorialLastClosedShift->closed_at?->format('d M Y H:i') }}
                    · {{ (int) ($tutorialLastClosedShift->total_transactions ?? 0) }} transactions
                    · Rp {{ number_format((float) ($tutorialLastClosedShift->total_revenue ?? 0), 0, ',', '.') }} received
                </p>
                <a href="{{ route('shifts.tutorial.report', $tutorialLastClosedShift) }}" id="tutorialShiftFullReportLink" class="px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer inline-block">Full report &amp; print</a>
            @else
                <p class="text-sm text-gray-600">No practice shift yet. Click Open Shift to start — 2 example transactions will appear automatically.</p>
            @endif
            @endif
        </div>
    </div>
@endif

@if(!Auth::user()->isManagement() && !$currentShift && isset($lastClosedShift) && $lastClosedShift)
    <div class="mb-4 bg-white border border-emerald-200 rounded-lg p-4 shadow-sm">
        <h3 class="text-sm font-semibold text-gray-900">Latest closing report</h3>
        <p class="text-xs text-gray-600 mt-1">
            {{ $lastClosedShift->periodLabel() }} shift
            · Closed at {{ $lastClosedShift->closed_at?->format('d M Y H:i') }}
            · {{ (int) ($lastClosedShift->total_transactions ?? 0) }} transactions
            · Rp {{ number_format((float) ($lastClosedShift->total_revenue ?? 0), 0, ',', '.') }} received
        </p>
        <p class="text-xs text-gray-500 mt-1">Download, WhatsApp, or share again anytime — stays here after refresh.</p>
        <div class="mt-3">
            @include('shifts.partials.report-share', [
                'downloadPdfUrl' => route('shifts.report.download-pdf', $lastClosedShift),
                'fullReportUrl' => route('shifts.report', $lastClosedShift),
                'idSuffix' => 'rentalsInline',
                'sharePdfUrl' => $lastClosedShift->signedPublicPdfUrl(),
                'shareTitle' => 'Demo Store One — '.$lastClosedShift->periodLabel().' shift — closing report #'.$lastClosedShift->id,
                'shareText' => 'Opened: '.($lastClosedShift->opened_at?->format('d M Y H:i') ?? '-')."\n"
                    .'Closed: '.($lastClosedShift->closed_at?->format('d M Y H:i') ?? '-')."\n"
                    .'Transactions: '.(int) ($lastClosedShift->total_transactions ?? 0)
                    .' | Received: Rp '.number_format((float) ($lastClosedShift->total_revenue ?? 0), 0, ',', '.'),
            ])
        </div>
    </div>
@endif

@if (\App\Support\DebugTools::enabled())
    <div class="mb-4 rounded-lg border border-dashed border-amber-400 bg-amber-50 p-4">
        <div class="flex flex-wrap items-center gap-3">
            <h3 class="text-sm font-semibold text-amber-800">Shift lookup (debug)</h3>
            <label class="sr-only" for="shiftDebugDate">Shift date</label>
            <input type="date" id="shiftDebugDate" class="form-input h-9 py-0 w-full sm:w-[10rem]">
            <button type="button" id="shiftDebugCheckBtn"
                class="h-9 px-3 rounded-lg border border-amber-400 bg-white text-sm font-medium text-amber-900 hover:bg-amber-100 cursor-pointer">
                Check shifts
            </button>
            <p class="text-xs text-amber-700">Lists shifts opened on that date for cross-checking daily reports.</p>
        </div>
        <div id="shiftDebugResults" class="mt-3 hidden text-sm text-gray-800"></div>
    </div>
@endif
