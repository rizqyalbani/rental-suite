@php
    $available = (bool) ($available ?? false);
    $code = (string) ($code ?? '');
@endphp

@if($available)
    <span class="px-2 py-0.5 text-xs font-semibold rounded-full border border-green-300 bg-green-100 text-green-800">Available</span>
@else
    <button type="button" class="outfit-act outfit-act-rental px-2 py-0.5 text-xs font-semibold rounded-full border border-amber-300 bg-yellow-100 text-yellow-800 hover:underline cursor-pointer" data-rental-code="{{ e($code) }}">1 active</button>
@endif
