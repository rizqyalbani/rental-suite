@php
    $currentPage = (int) ($currentPage ?? 1);
    $lastPage = (int) ($lastPage ?? 1);

    // Window: current page ± $delta, plus first/last page, with "..." filling the gaps.
    $delta = 2;
    $range = [];
    for ($i = max(1, $currentPage - $delta); $i <= min($lastPage, $currentPage + $delta); $i++) {
        $range[] = $i;
    }
    if (! in_array(1, $range, true)) {
        array_unshift($range, 1);
    }
    if (! in_array($lastPage, $range, true)) {
        $range[] = $lastPage;
    }

    $pages = [];
    $prevPage = null;
    foreach ($range as $page) {
        if ($prevPage !== null && $page - $prevPage > 1) {
            $pages[] = null;
        }
        $pages[] = $page;
        $prevPage = $page;
    }
@endphp

@if($lastPage > 1)
    <div class="flex gap-1 flex-wrap mt-4">
        <button onclick="goToPage({{ $currentPage - 1 }})" class="px-3 py-1 text-sm border rounded cursor-pointer hover:bg-gray-50{{ $currentPage <= 1 ? ' opacity-50 cursor-not-allowed' : '' }}" {{ $currentPage <= 1 ? 'disabled' : '' }}>&laquo;</button>
        @foreach($pages as $page)
            @if($page === null)
                <span class="px-3 py-1 text-sm text-gray-400">&hellip;</span>
            @elseif($page === $currentPage)
                <span class="px-3 py-1 text-sm border rounded bg-indigo-600 text-white">{{ $page }}</span>
            @else
                <button onclick="goToPage({{ $page }})" class="px-3 py-1 text-sm border rounded cursor-pointer hover:bg-gray-50">{{ $page }}</button>
            @endif
        @endforeach
        <button onclick="goToPage({{ $currentPage + 1 }})" class="px-3 py-1 text-sm border rounded cursor-pointer hover:bg-gray-50{{ $currentPage >= $lastPage ? ' opacity-50 cursor-not-allowed' : '' }}" {{ $currentPage >= $lastPage ? 'disabled' : '' }}>&raquo;</button>
    </div>
@endif
