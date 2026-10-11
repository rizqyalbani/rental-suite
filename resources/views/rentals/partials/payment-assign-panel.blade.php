@php
    $paUser = Auth::user();
    $paMayRequest = \App\Support\PaymentShiftAssignment::mayRequest($paUser);
    // The accounting dashboard passes its date range; "all" shows every period.
    $paFrom = $paDateFrom ?? null;
    $paTo = $paDateTo ?? null;
    $paShowAll = ! $paFrom || request()->boolean('pa_all');
    $paUnassigned = $paMayRequest ? \App\Support\PaymentShiftAssignment::unassigned($paShowAll ? null : $paFrom, $paShowAll ? null : $paTo) : collect();
    $paTotalAll = ($paMayRequest && $paFrom) ? \App\Support\PaymentShiftAssignment::unassigned()->count() : $paUnassigned->count();
    $paIncoming = $paUser ? \App\Support\PaymentShiftAssignment::incomingFor($paUser) : collect();
    $paRp = fn ($n) => 'Rp '.number_format((float) $n, 0, ',', '.');
@endphp

@if($paIncoming->isNotEmpty())
    {{-- Shift owner: payments management asked them to take into their shift. --}}
    <div id="paymentAssignIncoming" class="mb-4 rounded-lg border border-indigo-300 bg-indigo-50 p-4 text-sm text-indigo-900">
        <p class="font-semibold">Payments sent to your shift ({{ $paIncoming->count() }})</p>
        <p class="mt-0.5 text-xs text-indigo-800">Accept if you received this money (or it should count in your shift report). Reject if not — management will pick another shift.</p>
        <ul class="mt-3 space-y-2">
            @foreach($paIncoming as $a)
                @php $p = $a->payload ?? []; @endphp
                <li class="rounded-md border border-indigo-200 bg-white p-3" data-payment-assign-request="{{ $a->id }}">
                    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
                        <div>
                            <span class="font-medium text-gray-900">{{ $p['customer_name'] ?? '' }} #{{ $p['rental_id'] ?? '' }}</span>
                            · <span class="font-semibold">{{ $paRp($p['amount'] ?? 0) }}</span> {{ strtoupper((string) ($p['payment_method'] ?? '')) }}
                            · {{ isset($p['recorded_at']) ? \Illuminate\Support\Carbon::parse($p['recorded_at'])->format('d M H:i') : '' }}
                            <span class="block text-xs text-gray-600">To shift #{{ $p['shift_session_id'] ?? '' }} · asked by {{ $a->requestedBy?->name ?? '?' }}{{ ($p['note'] ?? '') !== '' ? ' · '.$p['note'] : '' }}</span>
                        </div>
                        <div class="flex gap-2 shrink-0">
                            <form method="POST" action="{{ route('payment-assign.accept', $a) }}">
                                @csrf
                                <button type="submit" class="rounded-md bg-indigo-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-indigo-700">Accept</button>
                            </form>
                            <form method="POST" action="{{ route('payment-assign.reject', $a) }}">
                                @csrf
                                <button type="submit" class="rounded-md border border-gray-300 bg-white px-3 py-1.5 text-xs font-semibold text-gray-700 hover:bg-gray-50">Reject</button>
                            </form>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif

@if($paUnassigned->isNotEmpty() || ($paFrom && $paTotalAll > 0))
    {{-- Management/accounting: payments that no shift counts yet. --}}
    <div id="paymentAssignUnassigned" class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-900">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <p class="font-semibold">Payments not in any shift ({{ $paUnassigned->count() }})</p>
            @if($paFrom)
                <div class="flex items-center gap-1 text-xs">
                    <a href="{{ request()->fullUrlWithQuery(['pa_all' => null]) }}#paymentAssignUnassigned"
                        class="rounded-md border px-2 py-0.5 {{ $paShowAll ? 'border-amber-300 bg-white text-amber-800 hover:bg-amber-100' : 'border-amber-600 bg-amber-600 text-white' }}">
                        {{ \Illuminate\Support\Carbon::parse($paFrom)->format('d M') }} – {{ \Illuminate\Support\Carbon::parse($paTo)->format('d M Y') }}
                    </a>
                    <a href="{{ request()->fullUrlWithQuery(['pa_all' => 1]) }}#paymentAssignUnassigned"
                        class="rounded-md border px-2 py-0.5 {{ $paShowAll ? 'border-amber-600 bg-amber-600 text-white' : 'border-amber-300 bg-white text-amber-800 hover:bg-amber-100' }}">
                        All dates ({{ $paTotalAll }})
                    </a>
                </div>
            @endif
        </div>
        @if($paUnassigned->isEmpty())
            <p class="mt-2 text-xs text-amber-800">None in this date range.</p>
        @endif
        <p class="mt-0.5 text-xs text-amber-800">Entered without an open shift, so no closing report counts them. Send each to the shift that received it; the shift owner accepts it.</p>
        @error('payment_assign')
            <p class="mt-2 text-xs font-medium text-red-700">{{ $message }}</p>
        @enderror
        <ul class="mt-3 space-y-2">
            @foreach($paUnassigned as $row)
                @php
                    $pay = $row['payment'];
                    $rent = $row['rental'];
                    $pend = $row['pending'];
                @endphp
                <li class="rounded-md border border-amber-200 bg-white p-3" data-unassigned-payment="{{ $pay->id }}">
                    <div class="flex flex-col lg:flex-row lg:items-center lg:justify-between gap-2">
                        <div>
                            <span class="font-medium text-gray-900">{{ $rent->customer_name }} #{{ $rent->id }}</span>
                            · <span class="font-semibold">{{ $paRp($pay->amount) }}</span> {{ strtoupper((string) $pay->payment_method) }} @if((float) $pay->amount < 0) <span class="ml-1 inline-block rounded bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-800 align-middle">Correction</span>@endif
                            · {{ $pay->recorded_at?->format('d M Y H:i') }}
                            <span class="block text-xs text-gray-600">Entered by {{ $pay->received_by_user_id ? (\App\Models\User::withoutGlobalScopes()->find($pay->received_by_user_id)?->name ?? '?') : '—' }}</span>
                        </div>
                        @if($pend)
                            <div class="flex items-center gap-2 text-xs text-gray-700 shrink-0">
                                <span>Waiting for <b>{{ \App\Models\User::withoutGlobalScopes()->find((int) ($pend->payload['target_user_id'] ?? 0))?->name ?? '?' }}</b> (shift #{{ $pend->payload['shift_session_id'] ?? '' }})</span>
                                @if((int) $pend->requested_by_user_id === (int) $paUser->id)
                                    <form method="POST" action="{{ route('approvals.rentals.cancel', $pend) }}">
                                        @csrf
                                        <button type="submit" class="text-indigo-600 hover:text-indigo-800 underline">Cancel</button>
                                    </form>
                                @endif
                            </div>
                        @else
                            @php $paShifts = \App\Support\PaymentShiftAssignment::candidateShifts($pay, $rent, $paUser); @endphp
                            @if($paShifts->isEmpty())
                                <span class="text-xs text-gray-500">No shift was open then, and none is open now.</span>
                            @else
                                <form method="POST" action="{{ route('payments.assign-shift', $pay->id) }}" class="flex flex-wrap items-center gap-2 shrink-0">
                                    @csrf
                                    <select name="shift_session_id" required class="rounded-md border-gray-300 text-xs py-1">
                                        @foreach($paShifts as $sh)
                                            <option value="{{ $sh->id }}">{{ \App\Support\PaymentShiftAssignment::shiftLabel($sh) }}</option>
                                        @endforeach
                                    </select>
                                    <input type="text" name="note" maxlength="300" placeholder="Note (optional)" class="rounded-md border-gray-300 text-xs py-1 w-40">
                                    <button type="submit" class="rounded-md bg-amber-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-700">Send to shift</button>
                                </form>
                            @endif
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
@endif
