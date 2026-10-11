@php
    $row = $row ?? (is_array($pending ?? null) ? $pending : []);
    $kind = (string) ($row['approval_kind'] ?? 'create');
    $isEdit = $kind === 'edit' && ! empty($row['target_rental_id']);

    $allCodes = $row['outfit_codes'] ?? $row['outfit_list'] ?? [];
    if (! is_array($allCodes)) {
        $allCodes = [];
    }

    $focusCodes = $row['approval_focus_outfit_codes'] ?? [];
    if (! is_array($focusCodes)) {
        $focusCodes = [];
    }
    $focusCodes = array_values(array_filter(array_map('strval', $focusCodes), fn ($c) => trim($c) !== ''));

    if ($focusCodes === []) {
        if ($isEdit && count($allCodes) > 1) {
            $otherCodes = $allCodes;
        } else {
            $focusCodes = $allCodes;
            $otherCodes = [];
        }
    } else {
        $focusSet = [];
        foreach ($focusCodes as $c) {
            $focusSet[$c] = true;
        }
        $otherCodes = [];
        foreach ($allCodes as $c) {
            $sc = (string) $c;
            if (! isset($focusSet[$sc])) {
                $otherCodes[] = $sc;
            }
        }
    }

    $custName = trim((string) ($row['customer_name'] ?? ''));
    $custLine = $custName !== ''
        ? e($custName)
        : '<span class="text-amber-900 italic">Customer / contact not entered yet</span>';

    $requestedByName = trim((string) ($row['requested_by_name'] ?? ''));
    $createdLabel = trim((string) ($row['created_label'] ?? ''));
    $periodNums = '<span class="tabular-nums">' . e((string) ($row['start_label'] ?? '')) . ' → ' . e((string) ($row['end_label'] ?? '')) . '</span>';
    $conflictLines = is_array($row['conflict_lines'] ?? null) ? $row['conflict_lines'] : [];
    // "Cool-down conflict" is only accurate when there's an actual date clash to show.
    // Admin edits now also land in this same queue with no conflict at all — for those,
    // the highlighted items just mean "reviewer should look here", not a real conflict.
    $focusLabel = count($conflictLines) ? 'Needs approval (cool-down conflict)' : 'Needs approval — review requested changes';
@endphp

<div class="bg-amber-50/90 border-2 border-amber-400 rounded-lg shadow-sm border-l-4 border-l-amber-500" data-pending-approval-list-row="{{ (string) ($row['approval_id'] ?? '') }}" data-approval-kind="{{ e($kind) }}">
    <div class="p-3 sm:p-4 lg:grid lg:grid-cols-5 lg:gap-x-5">
        <div class="lg:col-span-3 min-w-0">
        <div class="flex items-start justify-between gap-2 mb-1">
            <div class="flex-1 min-w-0 flex items-center gap-2 flex-wrap">
                @if($isEdit)
                    <span class="font-semibold text-gray-900">Quick edit</span>
                    <span class="inline-flex flex-wrap items-center gap-x-1.5 gap-y-0.5 px-2 py-1 text-xs font-medium rounded-md border border-indigo-300 bg-indigo-50 text-indigo-950">
                        <span class="font-semibold">Rent #{{ e((string) ($row['target_rental_id'] ?? '')) }}</span>
                        <span class="text-indigo-800/70">·</span>
                        {!! $periodNums !!}
                    </span>
                @else
                    <span class="font-semibold text-gray-900">{!! $custLine !!}</span>
                    <span class="px-2 py-0.5 text-xs font-semibold rounded-md border border-amber-500 bg-amber-100 text-amber-950">New booking</span>
                @endif
            </div>
        </div>

        <p class="text-xs text-amber-900/90">
            @if($requestedByName !== '')
                <span class="text-gray-600">Requested by {{ e($requestedByName) }}</span>
            @endif
            @if($createdLabel !== '')
                <span> · {{ e($createdLabel) }}</span>
            @endif
        </p>

        <div class="mt-2">
            @if(count($focusCodes))
                <p class="text-xs font-semibold text-amber-900 uppercase tracking-wide mb-1">{{ $focusLabel }}</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($focusCodes as $code)
                        <span class="inline-flex items-center gap-1 rounded-md border-2 border-amber-500 bg-amber-100 px-1.5 py-0.5 text-xs font-mono font-bold text-amber-950 shadow-sm ring-1 ring-amber-300/50">
                            <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                            {{ e((string) $code) }}
                        </span>
                    @endforeach
                </div>
            @else
                <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1">Items staff requested</p>
                <span class="text-xs text-gray-500">—</span>
            @endif
            @if(count($otherCodes))
                <p class="text-xs font-medium text-emerald-800 uppercase tracking-wide{{ count($focusCodes) ? ' mt-2' : '' }} mb-1">Ready — no conflict{{ $isEdit ? ' (stays on this rent)' : '' }}</p>
                <div class="flex flex-wrap gap-1">
                    @foreach($otherCodes as $code)
                        <span class="inline-flex items-center gap-1 rounded-md border border-emerald-300 bg-emerald-50 px-1.5 py-0.5 text-xs font-mono font-semibold text-emerald-900 shadow-sm">
                            <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            {{ e((string) $code) }}
                        </span>
                    @endforeach
                </div>
            @endif
        </div>

        @if($isEdit)
            <p class="text-xs text-gray-700 mt-2">
                Requested customer: <strong>{!! $custLine !!}</strong>
                · Phone: {{ trim((string) ($row['phone'] ?? '')) !== '' ? e((string) $row['phone']) : '—' }}
            </p>
        @else
            <div class="mt-2 rounded-lg border border-amber-200 bg-white/70 px-3 py-2 text-sm text-gray-700 space-y-1">
                <div class="flex flex-wrap justify-between gap-x-3"><span class="font-medium text-gray-800">Requested period</span> {!! $periodNums !!}</div>
                <div class="flex flex-wrap justify-between gap-x-3"><span class="font-medium text-gray-800">Phone</span> <span class="tabular-nums">{{ trim((string) ($row['phone'] ?? '')) !== '' ? e((string) $row['phone']) : '—' }}</span></div>
                <div class="flex flex-wrap justify-between gap-x-3"><span class="font-medium text-gray-800">Notes</span> <span class="text-right min-w-0">{{ trim((string) ($row['notes'] ?? ($row['address'] ?? ''))) !== '' ? e((string) ($row['notes'] ?? $row['address'])) : '—' }}</span></div>
            </div>
        @endif
        </div>

        <div class="lg:col-span-2 lg:border-l lg:border-amber-200 lg:pl-5 flex flex-col mt-2 lg:mt-0">
        @if(count($conflictLines))
            <div class="rounded-lg border border-amber-300 bg-white/70 px-3 py-1.5 space-y-1.5">
                @foreach($conflictLines as $cl)
                    <div class="text-sm leading-snug text-gray-800">
                        <span class="font-mono font-semibold">{{ e((string) $cl['code']) }}</span>
                        clashes with rent <span class="font-semibold">#{{ (int) $cl['rental_id'] }}</span> — {{ e((string) $cl['customer']) }}
                        <span class="block text-gray-700">
                            Booked <span class="tabular-nums font-medium">{{ e((string) $cl['start_label']) }} → {{ e((string) $cl['end_label']) }}</span>,
                            cool-down until <span class="tabular-nums font-semibold text-amber-900">{{ e((string) $cl['cooldown_until_label']) }}</span>
                        </span>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="mt-3 lg:mt-auto flex flex-wrap items-center justify-between gap-2 border-t border-amber-200/80 pt-2.5 lg:border-t-0 lg:pt-2">
            <span class="flex items-center gap-1.5 text-xs text-amber-900/90">
                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                Waiting for manager approval — {{ $isEdit ? 'update pending' : 'not a live rent yet' }}
            </span>
            @if(! empty($row['can_cancel']) && ! empty($row['cancel_url']))
                <form method="POST" action="{{ $row['cancel_url'] }}"
                    onsubmit="return window.rentalConfirmSubmit(event, 'The manager will no longer see this request and it cannot be undone.', { title: 'Cancel this request?', icon: 'warning', confirmButtonText: 'Yes, cancel it' });">
                    @csrf
                    <button type="submit"
                        class="inline-flex items-center gap-1.5 rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-50 transition cursor-pointer">
                        <svg class="h-3.5 w-3.5 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        Cancel request
                    </button>
                </form>
            @endif
        </div>
        </div>
    </div>
</div>
