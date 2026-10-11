<x-app-layout>
<div class="max-w-4xl mx-auto px-4 py-6">

    <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
        <div>
            <h1 class="text-xl font-bold text-gray-900">My Discount Status</h1>
            <p class="text-sm text-gray-500 mt-0.5">Discounts you applied — see which ones have been reviewed by management.</p>
        </div>
        <a href="{{ route('rentals.index') }}" class="text-sm text-indigo-600 hover:underline">← Back to Rentals</a>
    </div>

    {{-- Date picker --}}
    <form method="GET" action="{{ route('discounts.my-status') }}" class="mb-6 flex flex-wrap gap-3 items-end">
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
            No discounts applied by you on <span class="font-semibold">{{ \Carbon\Carbon::parse($day)->format('d M Y') }}</span>.
        </div>
    @else
        {{-- Summary badges --}}
        @php
            $checkedCount = $rentals->filter(fn($r) => $r->discountCheck)->count();
            $pendingCount = $rentals->count() - $checkedCount;
        @endphp
        <div class="flex flex-wrap gap-3 mb-5">
            <div class="flex items-center gap-2 rounded-lg border border-green-200 bg-green-50 px-4 py-2 text-sm">
                <span class="text-lg font-bold text-green-700">{{ $checkedCount }}</span>
                <span class="text-green-700">Cleared by management</span>
            </div>
            <div class="flex items-center gap-2 rounded-lg border border-amber-200 bg-amber-50 px-4 py-2 text-sm">
                <span class="text-lg font-bold text-amber-700">{{ $pendingCount }}</span>
                <span class="text-amber-700">Pending review</span>
            </div>
        </div>

        <div class="space-y-3">
        @foreach($rentals as $rental)
            @php
                $fin = \App\Support\RentalFinancialSummary::summarize($rental, $taxPercent);
                $discountAmt = max(0, (float)$fin['item_discount_total'] + (float)$fin['order_discount_amount']);
                $check = $rental->discountCheck;
                $isChecked = $check !== null;
            @endphp
            <div class="rounded-xl border {{ $isChecked ? 'border-green-200 bg-green-50/60' : 'border-amber-200 bg-white' }} p-4">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="flex-1 min-w-0">
                        <div class="flex flex-wrap items-center gap-2 mb-2">
                            <span class="font-semibold text-gray-900">{{ $rental->customer_name }}</span>
                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                {{ $isChecked ? 'bg-green-100 text-green-800' : 'bg-amber-100 text-amber-800' }}">
                                {{ $isChecked ? '✓ Cleared' : 'Awaiting review' }}
                            </span>
                        </div>

                        <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-gray-600 mb-3">
                            <span>Shift: <span class="font-medium text-gray-800">
                                @if($rental->shiftSession)
                                    #{{ $rental->shiftSession->id }}
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
                                <div class="text-xs rounded-md bg-white border border-amber-100 px-3 py-1.5">
                                    <span class="font-medium text-amber-900">Order discount:</span>
                                    {{ $rental->discount_type === 'percent' ? $rental->discount.'%' : 'Rp '.number_format($rental->discount, 0, ',', '.') }}
                                    @if($rental->discount_note)
                                        <span class="text-gray-500 ml-1">— {{ $rental->discount_note }}</span>
                                    @endif
                                </div>
                            @endif
                            @foreach($rental->outfits as $outfit)
                                @if((float)($outfit->pivot->discount ?? 0) > 0)
                                    <div class="text-xs rounded-md bg-white border border-amber-100 px-3 py-1.5">
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
                            <div class="mt-2.5 flex items-start gap-2 text-xs text-green-700 rounded-lg bg-green-100/60 border border-green-200 px-3 py-2">
                                <span class="font-bold text-green-600 mt-0.5">✓</span>
                                <span>
                                    Checked by <span class="font-semibold">{{ $check->checkedBy?->name }}</span>
                                    on {{ $check->checked_at->format('d M Y, H:i') }}
                                    @if($check->note)
                                        <span class="block text-green-600 mt-0.5 italic">"{{ $check->note }}"</span>
                                    @endif
                                </span>
                            </div>
                        @else
                            <div class="mt-2.5 text-xs text-amber-700 rounded-lg bg-amber-50 border border-amber-200 px-3 py-2">
                                This discount has not yet been reviewed by management. Follow up if needed.
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
        </div>

        <p class="mt-4 text-xs text-gray-400">
            {{ $rentals->count() }} discount transaction(s) on {{ \Carbon\Carbon::parse($day)->format('d M Y') }}.
        </p>
    @endif
</div>
</x-app-layout>
