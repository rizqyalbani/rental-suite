@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\RentalApprovalRequest> $approvals */
    /** @var int $queueTotal */
    $queueTotal = $queueTotal ?? 0;
    /** @var bool $wide Split card into details/actions columns on large screens (inline list only, not the dock). */
    $wide = $wide ?? false;
@endphp
@foreach($approvals as $approval)
    @php
        $payload = $approval->payload ?? [];
        $kind = (string) ($payload['kind'] ?? 'create');
    @endphp
    @if($kind === 'late_done')
        @php
            $ldId = (int) ($payload['rental_id'] ?? 0);
            $ldOwn = (int) $approval->requested_by_user_id === (int) auth()->id();
            $ldFmt = fn ($d) => $d ? \Illuminate\Support\Carbon::parse($d)->format('d M Y H:i') : '—';
            $ldRentalClass = $approval->rentalModelClass();
            $ldRental = $ldId ? ($ldRentalClass === \App\Models\Rental::class ? \App\Models\Rental::withoutGlobalScope('store_scope') : $ldRentalClass::query())->find($ldId) : null;
        @endphp
        <div class="rounded-xl border border-indigo-200 bg-white shadow-sm p-4{{ $wide ? ' lg:grid lg:grid-cols-5 lg:gap-x-5' : '' }}" data-approval-card="{{ $approval->id }}" data-approval-kind="late_done">
            <div id="late-done-details-{{ $approval->id }}" class="{{ $wide ? 'lg:col-span-3 min-w-0' : '' }}">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 pb-2.5 mb-3">
                    <h4 class="text-sm font-semibold text-gray-900 min-w-0">Pickup / return report</h4>
                    <span class="text-[10px] px-2.5 py-1 rounded-full bg-indigo-100 text-indigo-900 font-bold uppercase tracking-wide shrink-0">Pending</span>
                </div>
                <p class="text-xs text-gray-800 mb-1 font-medium">Rent #{{ $ldId }} — {{ $payload['customer_name'] ?? '' }}{{ $ldRental ? ' · '.$ldRental->start_date->format('d M').'–'.$ldRental->end_date->format('d M') : '' }}</p>
                @if(! empty($payload['picked_up_at']))
                    <p class="text-xs text-gray-700">Picked up: {{ $ldFmt($payload['picked_up_at']) }} · by {{ $payload['picked_up_by_name'] ?? '—' }}</p>
                @endif
                @if(($payload['type'] ?? '') === 'return')
                    <p class="text-xs text-gray-700">Returned: {{ $ldFmt($payload['returned_at'] ?? null) }} · received by {{ $payload['returned_by_name'] ?? '—' }}</p>
                @endif
                @if(($payload['note'] ?? '') !== '')
                    <p class="text-xs text-gray-600 mt-1">Note: {{ $payload['note'] }}</p>
                @endif
                @if($ldRental && (float) $ldRental->overdue_fee_amount > 0 && ($payload['type'] ?? '') === 'return' && ! empty($payload['returned_at']) && \Illuminate\Support\Carbon::parse($payload['returned_at'])->toDateString() <= $ldRental->end_date->toDateString())
                    <p class="mt-2 text-xs font-medium text-amber-800">Returned on time: the unpaid overdue fee Rp {{ number_format((float) $ldRental->overdue_fee_amount, 0, ',', '.') }} will be removed.</p>
                @endif
            </div>
            <div class="{{ $wide ? 'lg:col-span-2 lg:border-l lg:border-gray-200 lg:pl-5 lg:mt-0' : '' }} mt-3 flex flex-col gap-2">
                <form method="POST" action="{{ route('approvals.rentals.approve', ['approval' => $approval] + ($approval instanceof \App\Models\TutorialRentalApprovalRequest ? ['tutorial' => 1] : [])) }}" id="approve-form-{{ $approval->id }}"
                    data-confirm-title="Apply this pickup/return?"
                    data-confirm-message="Rent #{{ $ldId }} will be updated with these times. This is recorded with your name."
                    data-confirm-button="Yes, apply"
                    data-confirm-icon="question">
                    @csrf
                </form>
                <form method="POST" action="{{ route('approvals.rentals.reject', ['approval' => $approval] + ($approval instanceof \App\Models\TutorialRentalApprovalRequest ? ['tutorial' => 1] : [])) }}" id="reject-form-{{ $approval->id }}"
                    data-confirm-title="Reject this report?"
                    data-confirm-message="The rent stays as it is."
                    data-confirm-button="Yes, reject"
                    data-confirm-icon="question">
                    @csrf
                </form>
                <div id="late-done-actions-{{ $approval->id }}" class="flex gap-2 pt-1{{ $wide ? ' lg:mt-auto' : '' }}">
                    @if($ldOwn && ! ($approval instanceof \App\Models\TutorialRentalApprovalRequest))
                        <p class="flex-1 text-xs text-gray-500 self-center">Your own report — someone else must approve.</p>
                    @else
                        <button type="submit" form="approve-form-{{ $approval->id }}" class="inline-flex flex-1 items-center gap-1.5 rounded-lg border border-transparent bg-indigo-600 px-3 py-2.5 text-xs font-semibold text-white hover:bg-indigo-700 justify-center">
                            Approve
                        </button>
                    @endif
                    <button type="submit" form="reject-form-{{ $approval->id }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 justify-center">
                        Reject
                    </button>
                </div>
                <p class="flex items-center justify-end gap-1.5 text-xs text-gray-500 pt-1">
                    <span>Reported by {{ $approval->requestedBy?->name ?? 'Unknown' }} &bull; {{ $approval->created_at?->diffForHumans() ?? '' }}</span>
                </p>
            </div>
        </div>
        @continue
    @endif
    @if($kind === 'free')
        @php
            $freeRentalId = isset($payload['rental_id']) ? (int) $payload['rental_id'] : null;
            $freeOwn = (int) $approval->requested_by_user_id === (int) auth()->id();
            $freeRental = $freeRentalId ? \App\Models\Rental::withoutGlobalScope('store_scope')->find($freeRentalId) : null;
            $freeRefund = $freeRental ? \App\Support\RentalFreeRequest::refundDue($freeRental) : 0.0;
        @endphp
        <div class="rounded-xl border border-emerald-200 bg-white shadow-sm p-4{{ $wide ? ' lg:grid lg:grid-cols-5 lg:gap-x-5' : '' }}" data-approval-card="{{ $approval->id }}" data-approval-kind="free">
            <div class="{{ $wide ? 'lg:col-span-3 min-w-0' : '' }}">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 pb-2.5 mb-3">
                    <h4 class="text-sm font-semibold text-gray-900 min-w-0">Free rent request</h4>
                    <span class="text-[10px] px-2.5 py-1 rounded-full bg-emerald-100 text-emerald-900 font-bold uppercase tracking-wide shrink-0">Pending</span>
                </div>
                <p class="text-xs text-gray-800 mb-1 font-medium">Rent #{{ $freeRentalId }} — {{ $payload['customer_name'] ?? '' }}: Rp {{ number_format((float) ($payload['rent_total_before'] ?? 0), 0, ',', '.') }} → Rp 0</p>
                <p class="text-xs text-gray-600">Reason: {{ $payload['reason'] ?? '—' }}</p>
                @if($freeRefund > 0.004)
                    <p class="mt-2 text-xs font-medium text-amber-800">Customer already paid Rp {{ number_format($freeRefund, 0, ',', '.') }}. Approving records it as a FREE refund — hand this money back to the customer.</p>
                @endif
            </div>
            <div class="{{ $wide ? 'lg:col-span-2 lg:border-l lg:border-gray-200 lg:pl-5 lg:mt-0' : '' }} mt-3 flex flex-col gap-2">
                <form method="POST" action="{{ route('approvals.rentals.approve', ['approval' => $approval] + ($approval instanceof \App\Models\TutorialRentalApprovalRequest ? ['tutorial' => 1] : [])) }}" id="approve-form-{{ $approval->id }}"
                    data-confirm-title="Make this rent free?"
                    data-confirm-message="Rent #{{ $freeRentalId }} will be billed Rp 0{{ $freeRefund > 0.004 ? ' and Rp '.number_format($freeRefund, 0, ',', '.').' is refunded to the customer' : '' }}. This is recorded with your name."
                    data-confirm-button="Yes, make free"
                    data-confirm-icon="warning">
                    @csrf
                </form>
                <form method="POST" action="{{ route('approvals.rentals.reject', ['approval' => $approval] + ($approval instanceof \App\Models\TutorialRentalApprovalRequest ? ['tutorial' => 1] : [])) }}" id="reject-form-{{ $approval->id }}"
                    data-confirm-title="Reject this free request?"
                    data-confirm-message="The rent keeps its normal price."
                    data-confirm-button="Yes, reject"
                    data-confirm-icon="question">
                    @csrf
                </form>
                <div class="flex gap-2 pt-1{{ $wide ? ' lg:mt-auto' : '' }}">
                    @if($freeOwn)
                        <p class="flex-1 text-xs text-gray-500 self-center">Your own request — another supervisor/management must approve.</p>
                    @else
                        <button type="submit" form="approve-form-{{ $approval->id }}" class="inline-flex flex-1 items-center gap-1.5 rounded-lg border border-transparent bg-green-600 px-3 py-2.5 text-xs font-semibold text-white hover:bg-green-700 justify-center">
                            Approve free
                        </button>
                    @endif
                    <button type="submit" form="reject-form-{{ $approval->id }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 justify-center">
                        Reject
                    </button>
                </div>
                <p class="flex items-center justify-end gap-1.5 text-xs text-gray-500 pt-1">
                    <span>Requested by {{ $approval->requestedBy?->name ?? 'Unknown' }} &bull; {{ $approval->created_at?->diffForHumans() ?? '' }}</span>
                </p>
            </div>
        </div>
        @continue
    @endif
    @if($kind === 'delete')
        @php
            $deleteRentalId = isset($payload['rental_id']) ? (int) $payload['rental_id'] : null;
            $deleteRental = $deleteRentalId ? \App\Models\Rental::withoutGlobalScope('store_scope')->find($deleteRentalId) : null;
        @endphp
        <div class="rounded-xl border border-red-200 bg-white shadow-sm p-4{{ $wide ? ' lg:grid lg:grid-cols-5 lg:gap-x-5' : '' }}" data-approval-card="{{ $approval->id }}" data-approval-kind="delete">
            <div class="{{ $wide ? 'lg:col-span-3 min-w-0' : '' }}">
                <div class="flex items-center justify-between gap-2 border-b border-gray-100 pb-2.5 mb-3">
                    <h4 class="text-sm font-semibold text-gray-900 min-w-0">Delete request</h4>
                    <span class="text-[10px] px-2.5 py-1 rounded-full bg-red-100 text-red-900 font-bold uppercase tracking-wide shrink-0">Pending</span>
                </div>
                <p class="text-xs text-red-900/90 mb-3 font-medium">Staff asked to delete rental #{{ $deleteRentalId }}{{ $deleteRental ? ' — '.$deleteRental->customer_name : '' }}. The rental stays in the list until you approve.</p>
                @if(! empty($payload['wrong_input']))
                    <p class="text-xs text-gray-800 mb-1"><span class="font-semibold">Wrong input</span> — already paid Rp {{ number_format((float) ($payload['paid'] ?? 0), 0, ',', '.') }}; those payments are voided (removed from the shift and cash).</p>
                    <p class="text-xs text-gray-600 mb-2">Reason: {{ $payload['reason'] ?? '—' }}</p>
                @endif
                @if(! $deleteRental)
                    <p class="text-xs text-amber-800">Rental not found — it may have already been deleted.</p>
                @endif
            </div>
            <div class="{{ $wide ? 'lg:col-span-2 lg:border-l lg:border-gray-200 lg:pl-5 lg:mt-0' : '' }} mt-3 flex flex-col gap-2">
                <form method="POST" action="{{ route('approvals.rentals.approve', ['approval' => $approval] + ($approval instanceof \App\Models\TutorialRentalApprovalRequest ? ['tutorial' => 1] : [])) }}" id="approve-form-{{ $approval->id }}"
                    data-confirm-title="Approve this deletion?"
                    data-confirm-message="Rental #{{ $deleteRentalId }} will be permanently deleted. This cannot be undone."
                    data-confirm-button="Yes, delete"
                    data-confirm-icon="warning">
                    @csrf
                </form>
                <form method="POST" action="{{ route('approvals.rentals.reject', ['approval' => $approval] + ($approval instanceof \App\Models\TutorialRentalApprovalRequest ? ['tutorial' => 1] : [])) }}" id="reject-form-{{ $approval->id }}"
                    data-confirm-title="Reject this deletion?"
                    data-confirm-message="The rental will not be deleted."
                    data-confirm-button="Yes, reject"
                    data-confirm-icon="question">
                    @csrf
                </form>
                <div class="flex gap-2 pt-1{{ $wide ? ' lg:mt-auto' : '' }}">
                    @if(! empty($payload['wrong_input']) && ! (auth()->user()?->isManagement() || auth()->user()?->isSuperAdmin()))
                        <p class="flex-1 text-xs text-gray-500 self-center">Needs management to approve.</p>
                    @elseif(! empty($payload['wrong_input']) && (int) $approval->requested_by_user_id === (int) auth()->id())
                        <p class="flex-1 text-xs text-gray-500 self-center">Your own request — another manager must approve.</p>
                    @else
                    <button type="submit" form="approve-form-{{ $approval->id }}" class="inline-flex flex-1 items-center gap-1.5 rounded-lg border border-red-700 bg-red-600 px-3 py-2.5 text-xs font-semibold text-white hover:bg-red-700 justify-center">
                        Approve &amp; delete
                    </button>
                    @endif
                    <button type="submit" form="reject-form-{{ $approval->id }}" class="inline-flex items-center gap-1.5 rounded-lg border border-gray-300 bg-white px-4 py-2.5 text-xs font-semibold text-gray-700 hover:bg-gray-50 justify-center">
                        Reject
                    </button>
                </div>
                <p class="flex items-center justify-end gap-1.5 text-xs text-gray-500 pt-1">
                    <span>Requested by {{ $approval->requestedBy?->name ?? 'Unknown' }} &bull; {{ $approval->created_at?->diffForHumans() ?? '' }}</span>
                </p>
            </div>
        </div>
        @continue
    @endif
    @php
        $r = $payload['rental'] ?? [];
        $editRentalId = isset($payload['rental_id']) ? (int) $payload['rental_id'] : null;
        $isEdit = $kind === 'edit' && $editRentalId > 0;
        $savedRental = $isEdit ? \App\Models\Rental::withoutGlobalScope('tutorial_scope')->find($editRentalId) : null;
        $savedOk = $savedRental && $savedRental->status === 'active';
        $outfitCount = count($payload['outfit_ids'] ?? []);
        $codes = $approval->approval_outfit_codes ?? [];
        $outfitIdsOrdered = array_values(array_map('intval', $payload['outfit_ids'] ?? []));
        $idToCode = [];
        foreach ($outfitIdsOrdered as $idx => $sid) {
            if ($sid <= 0) {
                continue;
            }
            $idToCode[$sid] = $codes[$idx] ?? '#'.$sid;
        }
        $focusIds = array_values(array_filter(
            $approval->resolvedApprovalFocusOutfitIds(),
            fn (int $id) => isset($idToCode[$id])
        ));
        if ($focusIds === []) {
            if ($isEdit && count($idToCode) > 1) {
                $highlightCodes = [];
                $otherCodes = [];
                $seenO = [];
                foreach ($outfitIdsOrdered as $sid) {
                    if ($sid > 0 && isset($idToCode[$sid]) && ! isset($seenO[$sid])) {
                        $seenO[$sid] = true;
                        $otherCodes[] = $idToCode[$sid];
                    }
                }
            } else {
                $highlightCodes = $codes;
                $otherCodes = [];
            }
        } else {
            $fset = array_flip($focusIds);
            $highlightCodes = [];
            foreach ($focusIds as $fid) {
                $highlightCodes[] = $idToCode[$fid];
            }
            $otherCodes = [];
            $seenO = [];
            foreach ($outfitIdsOrdered as $sid) {
                if ($sid > 0 && ! isset($fset[$sid]) && isset($idToCode[$sid]) && ! isset($seenO[$sid])) {
                    $seenO[$sid] = true;
                    $otherCodes[] = $idToCode[$sid];
                }
            }
        }
        $itemsSecondaryLabel = count($highlightCodes) > 0
            ? ($isEdit ? 'Also on this rent after approval' : 'Also on this booking')
            : 'Items in this approval';
        // "Cool-down conflict" is only accurate when there's an actual date clash to
        // show. Admin edits also land in this same queue now (any edit, not just
        // cool-down cases) — for those, highlighted items just mean "review this",
        // not a real conflict.
        $conflictLinesForLabel = $approval->cooldownConflictLines();
        $highlightLabel = count($conflictLinesForLabel) ? 'Needs approval (cool-down conflict)' : 'Needs approval — review requested changes';
        $custPending = ! empty($r['customer_pending']) || trim((string) ($r['customer_name'] ?? '')) === '';
        $fmt = function (?string $d): string {
            if ($d === null || $d === '') {
                return '—';
            }
            try {
                return \Carbon\Carbon::parse($d)->format('d M Y');
            } catch (\Throwable) {
                return $d;
            }
        };
        $reqStart = $fmt($r['start_date'] ?? null);
        $reqEnd = $fmt($r['end_date'] ?? null);
        $fieldDiff = [];
        if ($isEdit && $savedRental) {
            $diff = $approval->diffAgainstSavedRental($savedRental, $codes);
            $fieldDiff = $diff['fields'];
            if ($diff['items']) {
                $fieldDiff[] = $diff['items'];
            }
            $fieldDiff = array_merge($fieldDiff, $diff['item_discounts']);
        }
        $fieldDiffChangedCount = count(array_filter($fieldDiff, fn ($d) => $d['changed']));
    @endphp
    <div class="rounded-xl border border-gray-200 bg-white shadow-sm p-4{{ $wide ? ' lg:grid lg:grid-cols-5 lg:gap-x-5' : '' }}" data-approval-card="{{ $approval->id }}" data-approval-kind="{{ $kind }}">
        <div class="{{ $wide ? 'lg:col-span-3 min-w-0' : '' }}">
        <div class="flex items-center justify-between gap-2 border-b border-gray-100 pb-2.5 mb-3">
            <h4 class="text-sm font-semibold text-gray-900 min-w-0">{{ $isEdit ? 'Quick edit' : 'New cool-down booking' }}</h4>
            <span class="text-[10px] px-2.5 py-1 rounded-full bg-amber-100 text-amber-900 font-bold uppercase tracking-wide shrink-0">Pending</span>
        </div>
        @if($isEdit)
            <p class="text-xs text-amber-900/90 mb-3 font-medium">Staff asked to change an <strong>existing</strong> booking{{ count($conflictLinesForLabel) ? ' (cool-down)' : '' }}. The rent stays in the list with old details until you approve.</p>
        @else
            <p class="text-xs text-gray-700 mb-3">Approving adds a <strong>new</strong> rent to the list.</p>
        @endif

        @if($isEdit)
            <div class="mb-3 rounded-md border border-amber-300 bg-amber-50/90 px-3 py-2 text-xs text-amber-950">
                <p class="font-semibold text-amber-950">
                    Rent #{{ $editRentalId }}
                    <span class="font-normal text-amber-900/85">·</span>
                    <span class="tabular-nums font-medium">{{ $reqStart }} → {{ $reqEnd }}</span>
                </p>
                @if(!$savedOk)
                    <p class="text-amber-800 mt-1">Rent #{{ $editRentalId }} not found or not active — verify before approving.</p>
                @endif
            </div>
        @else
            <div class="rounded-lg border border-amber-400 bg-indigo-50/70 px-3 py-2.5 mb-3 shadow-sm">
                <p class="text-[11px] font-bold text-indigo-950 uppercase tracking-wide">Requested booking period</p>
                <p class="mt-1.5 flex items-center gap-2 text-sm font-semibold tabular-nums text-gray-900">
                    <svg class="h-4 w-4 text-indigo-700 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6.75 3v2.25M17.25 3v2.25M3.75 7.5h16.5M4.5 21h15a.75.75 0 00.75-.75V6a.75.75 0 00-.75-.75h-15A.75.75 0 003.75 6v14.25c0 .414.336.75.75.75z" /></svg>
                    <span>{{ $reqStart }}</span>
                    <svg class="h-3.5 w-3.5 text-gray-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                    <span>{{ $reqEnd }}</span>
                </p>
            </div>
        @endif

        @unless($custPending)
            <div class="mb-2.5 flex items-center gap-2 rounded-md bg-gray-50 border border-gray-200 px-3 py-1.5">
                <svg class="h-4 w-4 text-gray-500 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                <p class="text-sm text-gray-900 min-w-0">
                    <span class="font-bold">{{ $r['customer_name'] ?? '—' }}</span>
                    <span class="text-gray-500">·</span>
                    <span class="tabular-nums text-gray-700">{{ $r['phone'] ?? '—' }}</span>
                </p>
            </div>
        @endunless

        <div class="mb-2">
            @if(count($highlightCodes))
                <p class="text-xs font-semibold text-amber-900 uppercase tracking-wide mb-1.5">{{ $highlightLabel }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($highlightCodes as $code)
                        <span class="inline-flex items-center gap-1 rounded-md border-2 border-amber-500 bg-amber-100 px-2 py-1 text-xs font-mono font-bold text-amber-950 shadow-sm ring-1 ring-amber-300/50">
                            <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                            {{ $code }}
                        </span>
                    @endforeach
                    @if($outfitCount > count($codes))
                        <span class="text-xs text-gray-500 self-center">+{{ $outfitCount - count($codes) }} more</span>
                    @endif
                </div>
                @if(count($conflictLinesForLabel))
                    <div class="mt-2 rounded-lg border border-amber-200 bg-amber-50/70 px-3 py-1.5 space-y-1.5">
                        @foreach($conflictLinesForLabel as $cl)
                            <div class="text-sm leading-snug text-gray-800">
                                <span class="font-mono font-semibold">{{ $cl['code'] }}</span>
                                clashes with rent <span class="font-semibold">#{{ $cl['rental_id'] }}</span> — {{ $cl['customer'] }}
                                <span class="block text-gray-700">
                                    Booked <span class="tabular-nums font-medium">{{ $cl['start_label'] }} → {{ $cl['end_label'] }}</span>,
                                    cool-down until <span class="tabular-nums font-semibold text-amber-900">{{ $cl['cooldown_until_label'] }}</span>
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            @endif
            @if(count($otherCodes))
                <p class="text-xs font-medium text-emerald-800 uppercase tracking-wide {{ count($highlightCodes) ? 'mt-2' : '' }} mb-1">Ready — no conflict{{ $isEdit ? ' (stays on this rent)' : '' }}</p>
                <div class="flex flex-wrap gap-1.5">
                    @foreach($otherCodes as $code)
                        <span class="inline-flex items-center gap-1 rounded-md border border-emerald-300 bg-emerald-50 px-2 py-1 text-xs font-mono font-semibold text-emerald-900 shadow-sm">
                            <svg class="h-3 w-3 shrink-0" fill="none" stroke="currentColor" stroke-width="2.2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5" /></svg>
                            {{ $code }}
                        </span>
                    @endforeach
                </div>
            @endif
            @if(! count($highlightCodes) && ! count($otherCodes))
                <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide mb-1.5">Items staff requested</p>
                <span class="text-xs text-gray-500">—</span>
            @endif
        </div>

        @if($isEdit && count($fieldDiff))
            <details class="mt-2.5 group" id="view-details-{{ $approval->id }}">
                <summary class="cursor-pointer select-none text-xs font-semibold text-indigo-700 hover:text-indigo-900 inline-flex items-center gap-1">
                    <svg class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                    View details
                    @if($fieldDiffChangedCount)
                        <span class="font-normal text-emerald-700">({{ $fieldDiffChangedCount }} changed)</span>
                    @endif
                </summary>
                <div class="mt-1.5 rounded-lg border border-gray-200 divide-y divide-gray-100 overflow-hidden">
                    @foreach($fieldDiff as $d)
                        <div class="flex items-center gap-2 px-2.5 py-1.5 text-xs {{ $d['changed'] ? 'bg-emerald-50' : 'bg-white' }}">
                            <span class="w-28 shrink-0 text-gray-500">{{ $d['label'] }}</span>
                            @if($d['changed'])
                                <span class="text-gray-400 line-through">{{ $d['old'] }}</span>
                                <svg class="h-3 w-3 text-emerald-600 shrink-0" fill="none" stroke="currentColor" stroke-width="2.5" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5L21 12m0 0l-7.5 7.5M21 12H3" /></svg>
                                <span class="font-semibold text-emerald-800">{{ $d['new'] }}</span>
                            @else
                                <span class="text-gray-700">{{ $d['new'] }}</span>
                            @endif
                        </div>
                    @endforeach
                </div>
            </details>
        @endif

        @if($isEdit)
            <p class="text-xs italic text-gray-600 mt-2.5 leading-snug">
                Approving applies the requested dates, items, and customer/payment snapshot to rent #{{ $editRentalId }}. Rejecting leaves the saved rent unchanged.
            </p>
        @elseif($custPending)
            <p class="text-xs italic text-gray-600 mt-2.5 leading-snug">
                Approving creates the active rent with placeholder customer/contact — staff completes details in Edit.
            </p>
        @endif
        </div>
        <div class="{{ $wide ? 'lg:col-span-2 lg:border-l lg:border-gray-200 lg:pl-5 lg:mt-0' : '' }} mt-3 flex flex-col gap-2">
            <form method="POST" action="{{ route('approvals.rentals.approve', ['approval' => $approval] + ($approval instanceof \App\Models\TutorialRentalApprovalRequest ? ['tutorial' => 1] : [])) }}" id="approve-form-{{ $approval->id }}"
                data-confirm-title="Approve this request?"
                data-confirm-message="{{ $isEdit ? 'The requested changes will be applied to rent #'.$editRentalId.'.' : 'This will create the active rent immediately.' }}"
                data-confirm-button="Yes, approve"
                data-confirm-icon="question">
                @csrf
                <details class="group">
                    <summary class="cursor-pointer select-none text-xs font-semibold text-gray-700 hover:text-gray-900 inline-flex items-center gap-1">
                        <svg class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                        Add note <span class="font-normal text-gray-500">(optional, e.g. buffer OK)</span>
                    </summary>
                    <textarea id="approve-note-{{ $approval->id }}" name="decision_note" rows="2" placeholder="Enter note…" aria-label="Optional note" class="form-textarea w-full text-xs py-1.5 rounded-lg bg-indigo-50/40 mt-1.5"></textarea>
                </details>
            </form>
            <form method="POST" action="{{ route('approvals.rentals.reject', ['approval' => $approval] + ($approval instanceof \App\Models\TutorialRentalApprovalRequest ? ['tutorial' => 1] : [])) }}" id="reject-form-{{ $approval->id }}"
                data-confirm-title="Reject this request?"
                data-confirm-message="The staff request will be rejected{{ $isEdit ? ' and the saved rent stays unchanged' : ' and no rent will be created' }}."
                data-confirm-button="Yes, reject"
                data-confirm-icon="warning">
                @csrf
                <details class="group">
                    <summary class="cursor-pointer select-none text-xs font-semibold text-gray-700 hover:text-gray-900 inline-flex items-center gap-1">
                        <svg class="h-3.5 w-3.5 shrink-0 transition-transform group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                        Add reason for staff <span class="font-normal text-gray-500">(optional, shown on reject)</span>
                    </summary>
                    <textarea id="reject-note-{{ $approval->id }}" name="decision_note" rows="2" placeholder="Enter reason…" aria-label="Optional reason for staff" class="form-textarea w-full text-xs py-1.5 rounded-lg bg-indigo-50/40 mt-1.5"></textarea>
                </details>
            </form>
            <div id="approval-actions-{{ $approval->id }}" class="rental-approval-actions-row flex gap-2 pt-1{{ $wide ? ' lg:mt-auto' : '' }}">
                <button type="submit" form="approve-form-{{ $approval->id }}" class="inline-flex flex-1 items-center gap-1.5 rounded-lg border border-green-700 bg-green-600 px-3 py-2.5 text-xs font-semibold text-white hover:bg-green-700 justify-center">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    @if($isEdit)
                        Approve &amp; update rent
                    @else
                        Approve &amp; create rent
                    @endif
                </button>
                <button type="submit" form="reject-form-{{ $approval->id }}" class="inline-flex items-center gap-1.5 rounded-lg border border-red-700 bg-red-600 px-4 py-2.5 text-xs font-semibold text-white hover:bg-red-700 justify-center">
                    <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    Reject
                </button>
            </div>
            <p class="flex items-center justify-end gap-1.5 text-xs text-gray-500 pt-1">
                <svg class="h-4 w-4 shrink-0" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                <span>Requested by {{ $approval->requestedBy?->name ?? 'Unknown' }} • {{ $approval->created_at?->diffForHumans() ?? '' }}</span>
            </p>
        </div>
    </div>
@endforeach
@if($queueTotal > $approvals->count())
    <p data-approval-queue-footer class="text-center text-xs text-gray-600 bg-white/90 rounded border border-gray-200 px-2 py-1.5 lg:col-span-2">
        +{{ $queueTotal - $approvals->count() }} more in queue — refresh after you clear these
    </p>
@endif
