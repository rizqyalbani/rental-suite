@php
    $rentals = $rentals ?? [];
@endphp

<h3 class="text-lg font-medium text-gray-900 mb-2">Multiple active rents</h3>
<div class="space-y-2 text-sm text-gray-700">
    @foreach($rentals as $r)
        @php
            $rid = (int) ($r['id'] ?? 0);
            $cust = (string) ($r['customer_name'] ?? '');
            $sl = (string) ($r['start_label'] ?? '');
            $el = (string) ($r['end_label'] ?? '');
        @endphp
        <div class="p-2 border rounded flex items-center justify-between">
            <div>
                <strong>{{ $cust }}</strong>
                <div class="text-xs text-gray-500">{{ $sl }} - {{ $el }}</div>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" onclick="openRentalFromList({{ $rid }})" class="px-2 py-1 text-xs bg-indigo-600 text-white rounded">Open</button>
                <a href="{{ route('rentals.index', ['edit' => $rid]) }}" target="_blank" onclick="event.stopPropagation()" class="text-xs text-indigo-600 underline">Transaction</a>
            </div>
        </div>
    @endforeach
</div>
