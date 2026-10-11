<x-app-layout>
<div class="max-w-5xl mx-auto px-4 py-6">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-900">Discount Review</h1>
            <p class="text-sm text-gray-500 mt-0.5">Check and clear discounts applied by admins.</p>
        </div>
        <a href="{{ route('reports.manager') }}" class="text-sm text-indigo-600 hover:underline">← Manager Report</a>
    </div>

    @if(session('success'))
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">{{ session('success') }}</div>
    @endif

    {{-- Date picker --}}
    <form method="GET" action="{{ route('discounts.index') }}" class="mb-6 flex flex-wrap gap-3 items-end">
        <div>
            <label class="block text-xs font-medium text-gray-600 mb-1">Date</label>
            <input type="date" name="day" value="{{ $day }}"
                class="form-input text-sm"
                onchange="this.form.submit()">
        </div>
        <button type="submit" class="px-3 py-2 text-sm bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 font-medium">Filter</button>
    </form>

    @if($rentals->isEmpty())
        <div class="rounded-xl border border-gray-200 bg-gray-50 px-6 py-10 text-center text-sm text-gray-500">
            No discounts applied on <span class="font-semibold">{{ \Carbon\Carbon::parse($day)->format('d M Y') }}</span>.
        </div>
    @else
        <div class="space-y-3">
        @foreach($rentals as $rental)
            @php
                $fin = \App\Support\RentalFinancialSummary::summarize($rental, $taxPercent);
                $discountAmt = max(0, (float)$fin['item_discount_total'] + (float)$fin['order_discount_amount']);
                $check = $rental->discountCheck;
                $isChecked = $check !== null;
            @endphp
            <div class="rounded-xl border {{ $isChecked ? 'border-green-200 bg-green-50/60' : 'border-amber-200 bg-amber-50/40' }} p-4">
                <div class="flex flex-col sm:flex-row sm:items-start sm:justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        {{-- Header --}}
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <span class="font-semibold text-gray-900">{{ $rental->customer_name }}</span>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                {{ $isChecked ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                                {{ $isChecked ? '✓ Checked' : 'Pending review' }}
                            </span>
                        </div>

                        {{-- Meta --}}
                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 mb-3">
                            <span>Admin: <span class="font-medium text-gray-800">{{ $rental->handledBy?->name ?? '—' }}</span></span>
                            <span>Shift: <span class="font-medium text-gray-800">
                                @if($rental->shiftSession)
                                    #{{ $rental->shiftSession->id }} — {{ $rental->shiftSession->openedBy?->name ?? '—' }}
                                @else
                                    No shift
                                @endif
                            </span></span>
                            <span>Period: <span class="font-medium text-gray-800">
                                {{ \Carbon\Carbon::parse($rental->start_date)->format('d M') }} – {{ \Carbon\Carbon::parse($rental->end_date)->format('d M Y') }}
                            </span></span>
                            <span>Total discount: <span class="font-semibold text-amber-700">Rp {{ number_format($discountAmt, 0, ',', '.') }}</span></span>
                        </div>

                        {{-- Discount details --}}
                        <div class="space-y-1">
                            @if((float)($rental->discount ?? 0) > 0)
                                <div class="text-xs rounded-md bg-white/80 border border-amber-100 px-3 py-1.5">
                                    <span class="font-medium text-amber-900">Order discount:</span>
                                    {{ $rental->discount_type === 'percent' ? $rental->discount.'%' : 'Rp '.number_format($rental->discount, 0, ',', '.') }}
                                    @if($rental->discount_note)
                                        <span class="text-gray-500 ml-1">— {{ $rental->discount_note }}</span>
                                    @endif
                                </div>
                            @endif
                            @foreach($rental->outfits as $outfit)
                                @if((float)($outfit->pivot->discount ?? 0) > 0)
                                    <div class="text-xs rounded-md bg-white/80 border border-amber-100 px-3 py-1.5">
                                        <span class="font-medium text-amber-900">{{ $outfit->code }}:</span>
                                        {{ $outfit->pivot->discount_type === 'percent' ? $outfit->pivot->discount.'%' : 'Rp '.number_format($outfit->pivot->discount, 0, ',', '.') }}
                                        @if($outfit->pivot->discount_note)
                                            <span class="text-gray-500 ml-1">— {{ $outfit->pivot->discount_note }}</span>
                                        @endif
                                    </div>
                                @endif
                            @endforeach
                        </div>

                        @if($isChecked)
                            <div class="mt-2 text-xs text-green-700">
                                Checked by <span class="font-medium">{{ $check->checkedBy?->name }}</span>
                                on {{ $check->checked_at->format('d M Y, H:i') }}
                                @if($check->note)
                                    — "{{ $check->note }}"
                                @endif
                            </div>
                        @endif
                    </div>

                    {{-- Action --}}
                    <div class="shrink-0">
                        @if($isChecked)
                            <form method="POST" action="{{ route('discounts.toggle', $rental) }}">
                                @csrf
                                <button type="submit"
                                    class="px-3 py-1.5 text-xs font-semibold rounded-lg border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 cursor-pointer">
                                    Uncheck
                                </button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('discounts.toggle', $rental) }}" x-data="{ open: false }">
                                @csrf
                                <div x-show="!open">
                                    <button type="button" @click="open = true"
                                        class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-green-600 text-white hover:bg-green-700 cursor-pointer">
                                        Mark Checked
                                    </button>
                                </div>
                                <div x-show="open" class="space-y-2 w-56">
                                    <input type="text" name="note" placeholder="Note (optional)" maxlength="500"
                                        class="form-input w-full text-xs">
                                    <div class="flex gap-2">
                                        <button type="submit"
                                            class="flex-1 px-2 py-1.5 text-xs font-semibold rounded-lg bg-green-600 text-white hover:bg-green-700 cursor-pointer">
                                            Confirm
                                        </button>
                                        <button type="button" @click="open = false"
                                            class="px-2 py-1.5 text-xs rounded-lg border border-gray-300 bg-white text-gray-600 hover:bg-gray-50 cursor-pointer">
                                            Cancel
                                        </button>
                                    </div>
                                </div>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
        </div>

        <p class="mt-4 text-xs text-gray-400">
            {{ $rentals->count() }} rental(s) with discounts on {{ \Carbon\Carbon::parse($day)->format('d M Y') }}.
            {{ $rentals->filter(fn($r) => $r->discountCheck)->count() }} checked.
        </p>
    @endif
</div>
</x-app-layout>
