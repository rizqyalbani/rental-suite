<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            My Requests
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">
                            My Requests
                            <span class="ml-2 px-2 py-0.5 bg-indigo-100 text-indigo-700 text-xs font-semibold rounded-full align-middle">{{ $counts['pending'] }} pending</span>
                        </h3>
                    </div>
                    <p class="text-sm text-gray-500 -mt-2 mb-4">
                        Every edit, delete, item-removal, and cool-down booking request you've submitted — and what happened to it.
                    </p>

                    <div class="border-b border-gray-200 mb-6">
                        <nav class="-mb-px flex gap-6">
                            @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label)
                                <a href="{{ route('my-requests.index', ['status' => $key]) }}"
                                    class="py-2 px-1 border-b-2 text-sm font-medium {{ $status === $key ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                                    {{ $label }} <span class="text-gray-400">({{ $counts[$key] }})</span>
                                </a>
                            @endforeach
                        </nav>
                    </div>

                    @if($approvals->isEmpty())
                        <div class="text-center py-12 text-gray-500 text-sm">
                            No {{ $status }} requests.
                        </div>
                    @else
                        <div class="space-y-4">
                            @foreach($approvals as $approval)
                                @php
                                    $payload = $approval->payload ?? [];
                                    $kind = (string) ($payload['kind'] ?? 'create');
                                    $rentalId = isset($payload['rental_id']) ? (int) $payload['rental_id'] : null;
                                    $kindLabel = match($kind) {
                                        'edit' => 'Edit rental',
                                        'delete' => 'Delete rental',
                                        'free' => 'Free rent',
                                        'payment_assign' => 'Payment to shift',
                                        'late_done' => 'Pickup/return report',
                                        'item_removal' => 'Remove item(s)',
                                        default => 'New cool-down booking',
                                    };
                                    $kindColor = match($kind) {
                                        'delete' => 'bg-red-100 text-red-800',
                                        'free' => 'bg-emerald-100 text-emerald-800',
                                        'item_removal' => 'bg-amber-100 text-amber-800',
                                        'edit' => 'bg-indigo-100 text-indigo-800',
                                        default => 'bg-blue-100 text-blue-800',
                                    };
                                    $r = $payload['rental'] ?? ['customer_name' => $payload['customer_name'] ?? null];
                                @endphp
                                <div class="border border-gray-200 rounded-lg p-5" @if($loop->first && $status === 'pending') id="myRequestsFirstPendingCard" @endif>
                                    <div class="flex items-start justify-between gap-4">
                                        <div>
                                            <div class="flex items-center gap-2 flex-wrap">
                                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full {{ $kindColor }}">{{ $kindLabel }}</span>
                                                @if($rentalId)
                                                    <a href="{{ route('rentals.index', $approval instanceof \App\Models\TutorialRentalApprovalRequest ? ['edit' => $rentalId, 'tutorial' => 1] : ['edit' => $rentalId]) }}" @if($loop->first && $status === 'pending') id="myRequestsFirstPendingRentLink" @endif class="font-medium text-indigo-600 hover:text-indigo-800 underline">Rent #{{ $rentalId }}</a>
                                                @endif
                                                @if(!empty($r['customer_name']))
                                                    <span class="text-gray-500">— {{ $r['customer_name'] }}</span>
                                                @endif
                                            </div>
                                            <div class="text-xs text-gray-500 mt-0.5">
                                                Submitted {{ $approval->created_at?->format('d M Y, H:i') }} ({{ $approval->created_at?->diffForHumans() }})
                                            </div>
                                        </div>
                                        <span class="px-2 py-1 text-xs font-semibold rounded-full shrink-0
                                            {{ $status === 'pending' ? 'bg-amber-100 text-amber-800' : ($status === 'approved' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600') }}">
                                            {{ ucfirst($status) }}
                                        </span>
                                    </div>

                                    @if($kind === 'item_removal' && !empty($payload['removed_outfit_ids']))
                                        <div class="mt-2 text-xs text-gray-500">
                                            Items: {{ implode(', ', array_map(fn($id) => '#'.$id, $payload['removed_outfit_ids'])) }}
                                        </div>
                                    @endif

                                    @if($approval->decidedBy)
                                        <div class="mt-3 text-xs text-gray-500">
                                            {{ ucfirst($status) }} by {{ $approval->decidedBy->name }} &bull; {{ $approval->decided_at?->format('d M Y, H:i') }} ({{ $approval->decided_at?->diffForHumans() }})
                                            @if($approval->decision_note)
                                                &mdash; "{{ $approval->decision_note }}"
                                            @endif
                                        </div>
                                    @elseif($status === 'pending')
                                        <div class="mt-3 text-xs text-gray-500 italic">Waiting for supervisor or management to review.</div>
                                    @endif
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- On-page help shortcut. Admin gets the real "How approvals work" walkthrough
         (approval-tour.blade.php) restarted from the top — its Admin branch only reaches
         My Requests at the END, after really creating+editing+submitting a practice
         rental first, so there's no seeded shortcut straight into a populated state; a
         full restart is what actually guarantees real data by the time it gets here.
         Supervisor/Management/Super Admin never see Admin-branch steps at all (role-
         gated inside approval-tour.blade.php itself) — for them "My Requests" only ever
         holds their own item-removal submissions, which has no spotlight walkthrough
         built yet, so this points at the closest existing explainer instead
         (my-requests-info-tour.blade.php's "If you submitted the request" step). --}}
    @php
        $myRequestsHelpUser = Auth::user();
        $myRequestsHelpIsDecider = $myRequestsHelpUser->isManagement() || $myRequestsHelpUser->isSupervisor() || $myRequestsHelpUser->isSuperAdmin();
    @endphp
    @if($myRequestsHelpUser->isAdmin() || $myRequestsHelpIsDecider)
        <button type="button" @if($myRequestsHelpUser->isAdmin()) data-open-approval-tour @else data-open-info-tour @endif
            class="fixed right-4 bottom-24 z-[90] flex h-11 w-11 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg hover:bg-indigo-700 transition cursor-pointer"
            aria-label="Help for this page" title="Help for this page">
            <span class="text-lg font-bold leading-none">?</span>
        </button>
    @endif
</x-app-layout>
