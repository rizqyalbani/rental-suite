@php
    /** @var \Illuminate\Support\Collection<int, \App\Models\RentalApprovalRequest> $managerApprovalQueue */
    /** @var int $pendingRentalApprovalsCount */
@endphp
<div class="mb-4" id="manager-pending-approvals-inline">
    <div class="rounded-xl border border-indigo-100 bg-indigo-50/60 px-4 py-3 shadow-sm mb-3 flex items-start gap-2.5">
        <svg class="h-5 w-5 text-amber-600 shrink-0 mt-0.5" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12v-.008z" /></svg>
        <div>
            <h3 class="sr-only">Pending cool-down approvals</h3>
            <p class="text-sm font-bold text-gray-900">{{ (int) $pendingRentalApprovalsCount === 1 ? 'One request' : (int) $pendingRentalApprovalsCount.' requests' }} waiting.</p>
            <p class="text-xs text-gray-600 mt-0.5 max-w-2xl">
                New bookings are not in the list until you approve.
                Quick edits show saved vs requested dates and highlighted items on each card.
            </p>
        </div>
    </div>
    <div class="grid gap-3 lg:grid-cols-2">
        @include('partials.pending-rental-approval-cards', [
            'approvals' => $managerApprovalQueue,
            'queueTotal' => (int) $pendingRentalApprovalsCount,
        ])
    </div>
</div>
