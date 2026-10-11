@php
    $rental = $rental ?? [];
    $id = (int) ($rental['id'] ?? 0);
    $cust = (string) ($rental['customer_name'] ?? '');
    $sl = (string) ($rental['start_label'] ?? '');
    $el = (string) ($rental['end_label'] ?? '');
    $phone = (string) ($rental['phone'] ?? '');
    $ig = $rental['instagram'] ?? null;
    $items = is_array($rental['outfit_list'] ?? null) ? $rental['outfit_list'] : [];
    $itemsHtml = collect($items)->map(fn ($x) => e((string) $x))->implode('<br>');
@endphp

<h3 class="text-lg font-medium text-gray-900 mb-2">{{ $cust }} <span class="text-sm text-gray-500">({{ $sl }} - {{ $el }})</span></h3>
<div class="text-sm text-gray-700">
    <div><strong>Items:</strong><br>{!! $itemsHtml !!}</div>
    <div class="mt-2"><strong>Phone:</strong> {{ $phone }}</div>
    <div><strong>Instagram:</strong> {{ $ig ? '@'.(string) $ig : '-' }}</div>
    <div class="mt-2">
        <button type="button" onclick="event.stopPropagation(); selectRentalIntoCart({{ $id }})" class="underline text-indigo-600 hover:text-indigo-900">Select rent</button>
    </div>
</div>
