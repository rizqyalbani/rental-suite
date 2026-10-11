<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Edit Requests
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                    {{ session('success') }}
                </div>
            @endif
            @if($errors->any())
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                    {{ $errors->first() }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">
                            Edit Requests
                            <span id="editRequestsPendingHeaderCount" class="ml-2 px-2 py-0.5 bg-indigo-100 text-indigo-700 text-xs font-semibold rounded-full align-middle">{{ $counts['pending'] }} pending</span>
                        </h3>
                    </div>
                    <p class="text-sm text-gray-500 -mt-2 mb-4">
                        New cool-down bookings and rental edits (including plain admin edits, which now all require approval) waiting on a decision.
                    </p>

                    <div class="border-b border-gray-200 mb-6">
                        <nav class="-mb-px flex gap-6">
                            @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label)
                                <a href="{{ route('approvals.edit-requests.index', ['status' => $key]) }}"
                                    class="py-2 px-1 border-b-2 text-sm font-medium {{ $status === $key ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                                    {{ $label }} <span @if($key === 'pending') id="editRequestsPendingTabCount" @endif class="text-gray-400">({{ $counts[$key] }})</span>
                                </a>
                            @endforeach
                        </nav>
                    </div>

                    @if($approvals->isEmpty())
                        <div class="text-center py-12 text-gray-500 text-sm">
                            No {{ $status }} edit requests.
                        </div>
                    @elseif($status === 'pending')
                        {{-- wide:true makes each card split into its own 3/5 + 2/5 columns
                             internally — needs the full row width to do that, so this list
                             must stay single-column (unlike the compact 2-per-row inline
                             dock, which uses wide:false and lets cards stack normally). --}}
                        <div id="editRequestsPendingList" class="space-y-3">
                            @include('partials.pending-rental-approval-cards', [
                                'approvals' => $approvals,
                                'queueTotal' => $approvals->count(),
                                'wide' => true,
                            ])
                        </div>
                    @else
                        <div class="space-y-4">
                            @foreach($approvals as $approval)
                                @php
                                    $payload = $approval->payload ?? [];
                                    $kind = (string) ($payload['kind'] ?? 'create');
                                    $editRentalId = isset($payload['rental_id']) ? (int) $payload['rental_id'] : null;
                                    $isEdit = $kind === 'edit' && $editRentalId > 0;
                                    $r = $payload['rental'] ?? ['customer_name' => $payload['customer_name'] ?? null];
                                @endphp
                                <div class="border border-gray-200 rounded-lg p-5">
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <div class="font-medium text-gray-900">
                                                {{ $kind === 'delete' ? 'Delete request' : ($kind === 'free' ? 'Free rent' : ($kind === 'late_done' ? 'Pickup/return report' : ($isEdit ? 'Quick edit' : 'New cool-down booking'))) }}
                                                @if($isEdit || in_array($kind, ['delete', 'free', 'late_done'], true))
                                                    — Rent #{{ $editRentalId }}
                                                @endif
                                                @if(!empty($r['customer_name']))
                                                    — {{ $r['customer_name'] }}
                                                @endif
                                            </div>
                                            @if($kind === 'free' && !empty($payload['reason']))
                                                <div class="text-sm text-gray-700 mt-0.5">Reason: {{ $payload['reason'] }} · was Rp {{ number_format((float) ($payload['rent_total_before'] ?? 0), 0, ',', '.') }}</div>
                                            @endif
                                            <div class="text-xs text-gray-500 mt-0.5">
                                                Requested by {{ $approval->requestedBy->name ?? 'Unknown' }} &bull; {{ $approval->created_at?->format('d M Y, H:i') }} ({{ $approval->created_at?->diffForHumans() }})
                                            </div>
                                        </div>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full shrink-0
                                            {{ $status === 'approved' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600' }}">
                                            {{ ucfirst($status) }}
                                        </span>
                                    </div>

                                    @if($approval->decidedBy)
                                        <div class="mt-3 text-xs text-gray-500">
                                            {{ ucfirst($status) }} by {{ $approval->decidedBy->name }} &bull; {{ $approval->decided_at?->format('d M Y, H:i') }} ({{ $approval->decided_at?->diffForHumans() }})
                                            @if($approval->decision_note)
                                                &mdash; "{{ $approval->decision_note }}"
                                            @endif
                                        </div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- On-page shortcut into "How approvals work" (approval-tour.blade.php). Only
         Supervisor/Management/Super Admin ever reach this page (route gate), and for
         those roles the tour's own "decider" branch is entirely about Edit Requests —
         no unrelated Admin-only steps (new rent, edit, My Requests) exist in that
         branch — so a plain restart button lands squarely on this page's own content,
         real seeded practice data included, same as the "Removal Requests" shortcut. --}}
    <button type="button" data-open-approval-tour
        class="fixed right-4 bottom-24 z-[90] flex h-11 w-11 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg hover:bg-indigo-700 transition cursor-pointer"
        aria-label="Help for this page" title="Help for this page">
        <span class="text-lg font-bold leading-none">?</span>
    </button>
</x-app-layout>
