<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Item Removal Requests
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
                            Item Removal Requests
                            <span id="editRequestsPendingHeaderCount" class="ml-2 px-2 py-0.5 bg-indigo-100 text-indigo-700 text-xs font-semibold rounded-full align-middle">{{ $counts['pending'] }} pending</span>
                        </h3>
                    </div>

                    <div class="border-b border-gray-200 mb-6">
                        <nav class="-mb-px flex gap-6">
                            @foreach(['pending' => 'Pending', 'approved' => 'Approved', 'rejected' => 'Rejected'] as $key => $label)
                                <a href="{{ route('approvals.item-removal.index', ['status' => $key]) }}"
                                    class="py-2 px-1 border-b-2 text-sm font-medium {{ $status === $key ? 'border-indigo-600 text-indigo-600' : 'border-transparent text-gray-500 hover:text-gray-700' }}">
                                    {{ $label }} <span @if($key === 'pending') id="editRequestsPendingTabCount" @endif class="text-gray-400">({{ $counts[$key] }})</span>
                                </a>
                            @endforeach
                        </nav>
                    </div>

                    @if($rows->isEmpty())
                        <div class="text-center py-12 text-gray-500 text-sm">
                            No {{ $status }} item removal requests.
                        </div>
                    @endif

                    <div class="space-y-4" id="itemRemovalPendingList">
                        @foreach($rows as $row)
                            @php
                                $approval = $row['approval'];
                                $rental = $row['rental'];
                            @endphp
                            <div class="border border-gray-200 rounded-lg p-5 {{ $row['overpayment'] ? 'border-l-4 border-l-red-500' : '' }}" data-approval-card="{{ $approval->id }}">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <div class="font-medium text-gray-900">
                                            Rental #{{ $approval->payload['rental_id'] ?? '?' }}
                                            @if($rental)
                                                — {{ $rental->customer_name }}
                                            @endif
                                        </div>
                                        <div class="text-xs text-gray-500 mt-0.5">
                                            Requested by {{ $approval->requestedBy->name ?? 'Unknown' }} &bull; {{ $approval->created_at?->diffForHumans() }}
                                        </div>
                                    </div>
                                    <span class="px-2 py-1 text-xs font-semibold rounded-full shrink-0
                                        {{ $status === 'pending' ? 'bg-amber-100 text-amber-800' : ($status === 'approved' ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-600') }}">
                                        {{ ucfirst($status) }}
                                    </span>
                                </div>

                                <div class="mt-3 bg-amber-50 border border-amber-200 rounded-md p-3">
                                    <div class="text-[11px] font-semibold uppercase tracking-wide text-amber-700 mb-1.5">Requested for removal</div>
                                    @if($row['removed_outfits']->isEmpty())
                                        <div class="text-sm text-gray-500 italic">Item data unavailable</div>
                                    @else
                                        <div class="flex flex-wrap gap-2">
                                            @foreach($row['removed_outfits'] as $outfit)
                                                <span class="inline-flex items-center gap-1 bg-white border border-amber-300 rounded px-2 py-1 text-sm text-gray-800">
                                                    <strong>{{ $outfit->code }}</strong>
                                                    @if($outfit->description) &bull; {{ $outfit->description }} @endif
                                                    &bull; Rp {{ number_format((float) $outfit->price, 0, ',', '.') }}
                                                </span>
                                            @endforeach
                                        </div>
                                    @endif
                                </div>

                                @if($rental)
                                    <div class="mt-3 grid grid-cols-3 gap-4 text-sm" id="item-removal-financial-{{ $approval->id }}">
                                        <div>
                                            <div class="text-[11px] uppercase tracking-wide text-gray-400">Current total</div>
                                            <div class="font-medium text-gray-900">Rp {{ number_format($row['current_total'], 0, ',', '.') }}</div>
                                        </div>
                                        <div>
                                            <div class="text-[11px] uppercase tracking-wide text-gray-400">Amount paid</div>
                                            <div class="font-medium text-green-700">Rp {{ number_format($row['amount_paid'], 0, ',', '.') }}</div>
                                        </div>
                                        <div>
                                            <div class="text-[11px] uppercase tracking-wide text-gray-400">New total (estimated, if approved)</div>
                                            <div class="font-bold text-indigo-700">Rp {{ number_format($row['new_total_estimate'], 0, ',', '.') }}</div>
                                        </div>
                                    </div>

                                    @if($row['overpayment'])
                                        <div class="mt-2 flex items-start gap-1.5 text-xs text-red-700 bg-red-50 border border-red-200 rounded px-2 py-1.5" id="item-removal-overpayment-{{ $approval->id }}">
                                            <span>&#9888;</span>
                                            <span><strong>Potential overpayment.</strong> Amount already paid exceeds the estimated new total — check with the customer about a refund before approving.</span>
                                        </div>
                                    @endif
                                @else
                                    <div class="mt-3 text-sm text-gray-500 italic">Rental no longer exists.</div>
                                @endif

                                @if($status === 'pending')
                                    <div class="mt-4 flex items-end justify-end gap-3 flex-wrap" id="item-removal-actions-{{ $approval->id }}">
                                        @if($row['overpayment'])
                                            <div class="mr-auto">
                                                <label for="refund-account-{{ $approval->id }}" class="block text-[11px] uppercase tracking-wide text-gray-500 mb-1">Refund from account</label>
                                                <select id="refund-account-{{ $approval->id }}" form="item-removal-approve-form-{{ $approval->id }}" name="refund_payment_account_id" class="form-select text-sm" data-searchable-select data-searchable-placeholder="Search cash/bank account...">
                                                    <option value="">Automatic — follows how the customer paid</option>
                                                    @foreach($paymentAccounts as $account)
                                                        <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                        @endif
                                        <form method="POST" action="{{ route('approvals.item-removal.reject', ['approval' => $approval] + ($approval instanceof \App\Models\TutorialRentalApprovalRequest ? ['tutorial' => 1] : [])) }}"
                                            data-confirm-title="Reject request?"
                                            data-confirm-message="Reject this item removal request?"
                                            data-confirm-button="Reject"
                                            data-confirm-icon="warning">
                                            @csrf
                                            <button type="submit" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 cursor-pointer">Reject</button>
                                        </form>
                                        <form id="item-removal-approve-form-{{ $approval->id }}" method="POST" action="{{ route('approvals.item-removal.approve', ['approval' => $approval] + ($approval instanceof \App\Models\TutorialRentalApprovalRequest ? ['tutorial' => 1] : [])) }}"
                                            data-confirm-title="Approve removal?"
                                            data-confirm-message="Approve this item removal? This cannot be undone."
                                            data-confirm-button="Approve"
                                            data-confirm-icon="question">
                                            @csrf
                                            <button type="submit" class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer">Approve</button>
                                        </form>
                                    </div>
                                @elseif($approval->decidedBy)
                                    <div class="mt-3 text-xs text-gray-500">
                                        {{ ucfirst($status) }} by {{ $approval->decidedBy->name }} &bull; {{ $approval->decided_at?->diffForHumans() }}
                                        @if($approval->decision_note)
                                            &mdash; "{{ $approval->decision_note }}"
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('js/searchable-select.js') }}?v={{ filemtime(public_path('js/searchable-select.js')) }}"></script>

    {{-- On-page shortcut into "How item removal works" (removal-tour.blade.php) — that
         tour is already scoped entirely to this page (intro + nav-in + this page's own
         steps, real seeded practice data), so a plain restart button is enough; no
         step/endStep scoping needed like accounting-help-fab uses. --}}
    <button type="button" data-open-removal-tour
        class="fixed right-4 bottom-24 z-[90] flex h-11 w-11 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg hover:bg-indigo-700 transition cursor-pointer"
        aria-label="Help for this page" title="Help for this page">
        <span class="text-lg font-bold leading-none">?</span>
    </button>
</x-app-layout>
