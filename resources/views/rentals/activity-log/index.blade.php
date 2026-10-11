<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Rental Activity Log
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-4">
                        <div>
                            <h3 class="text-lg font-medium text-gray-900">Rental Activity Log</h3>
                            <p class="text-sm text-gray-500 mt-0.5">
                                Every rental change, approval decision, and shift open/close — who did it and when.
                                @if($filters['rental_id'])
                                    <span class="ml-1 font-medium text-indigo-700">Filtered to rental #{{ $filters['rental_id'] }}.</span>
                                @endif
                            </p>
                        </div>
                    </div>

                    <form id="activityLogFilterForm" method="GET" action="{{ route('rentals.activity-log.index') }}" class="mb-6 flex flex-wrap gap-3 items-end">
                        @if($filters['rental_id'])
                            <input type="hidden" name="rental_id" value="{{ $filters['rental_id'] }}">
                        @endif
                        <div class="w-40">
                            <label class="block text-xs font-medium text-gray-500 mb-1">Action</label>
                            <select name="action" class="form-select w-full text-sm">
                                <option value="">All actions</option>
                                @foreach($actionOptions as $value => $label)
                                    <option value="{{ $value }}" {{ $filters['action'] === $value ? 'selected' : '' }}>{{ $label }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-36">
                            <label class="block text-xs font-medium text-gray-500 mb-1">Staff</label>
                            <select name="user_id" class="form-select w-full text-sm">
                                <option value="">Everyone</option>
                                @foreach($userOptions as $u)
                                    <option value="{{ $u->id }}" {{ (int) $filters['user_id'] === $u->id ? 'selected' : '' }}>{{ $u->name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="w-36">
                            <label class="block text-xs font-medium text-gray-500 mb-1">From</label>
                            <input type="date" name="date_from" value="{{ $filters['date_from'] }}" class="form-input w-full text-sm">
                        </div>
                        <div class="w-36">
                            <label class="block text-xs font-medium text-gray-500 mb-1">To</label>
                            <input type="date" name="date_to" value="{{ $filters['date_to'] }}" class="form-input w-full text-sm">
                        </div>
                        <div class="flex gap-2">
                            <button type="submit" class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer">Filter</button>
                            <a href="{{ route('rentals.activity-log.index') }}" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">Reset</a>
                        </div>
                    </form>

                    @if($logs->isEmpty())
                        <div class="text-center py-12 text-gray-500 text-sm">
                            No activity found for these filters.
                        </div>
                    @else
                        <ol id="activityLogTimelineList" class="relative border-s border-gray-200 ml-2">
                            @foreach($logs as $log)
                                @php
                                    $actionColor = match(true) {
                                        $log->action === 'created' => 'bg-green-100 text-green-700 ring-green-200',
                                        $log->action === 'deleted' => 'bg-red-100 text-red-700 ring-red-200',
                                        $log->action === 'item_removal_requested' => 'bg-amber-100 text-amber-700 ring-amber-200',
                                        $log->action === 'item_removal_approved' => 'bg-indigo-100 text-indigo-700 ring-indigo-200',
                                        $log->action === 'item_removal_rejected' => 'bg-gray-100 text-gray-600 ring-gray-200',
                                        $log->action === 'status_changed' => 'bg-blue-100 text-blue-700 ring-blue-200',
                                        $log->action === 'shift_opened' => 'bg-teal-100 text-teal-700 ring-teal-200',
                                        $log->action === 'shift_closed' => 'bg-slate-200 text-slate-700 ring-slate-300',
                                        default => 'bg-slate-100 text-slate-700 ring-slate-200',
                                    };
                                @endphp
                                <li class="mb-6 ms-6">
                                    <span class="absolute -start-[7px] flex h-3.5 w-3.5 rounded-full ring-4 ring-white {{ $actionColor }}"></span>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="px-2 py-0.5 text-xs font-semibold rounded-full {{ $actionColor }}">{{ $log->actionLabel() }}</span>
                                        @if($log->rental_id)
                                            <a href="{{ route('rentals.activity-log.index', ['rental_id' => $log->rental_id]) }}" class="text-sm font-medium text-gray-900 hover:text-indigo-700">
                                                Rental #{{ $log->rental_id }}
                                            </a>
                                            @if($log->rental)
                                                <span class="text-sm text-gray-500">— {{ $log->rental->customer_name }}</span>
                                            @endif
                                        @endif
                                    </div>
                                    <div class="text-xs text-gray-500 mt-0.5">
                                        {{ $log->user->name ?? 'System' }} &bull; {{ $log->created_at?->format('d M Y, H:i') }} ({{ $log->created_at?->diffForHumans() }})
                                        @if($log->note)
                                            &bull; {{ $log->note }}
                                        @endif
                                    </div>

                                    @if($log->changes)
                                        <div class="mt-2 bg-gray-50 border border-gray-200 rounded-md divide-y divide-gray-100">
                                            @foreach($log->changes as $field => $pair)
                                                <div class="px-3 py-1.5 text-sm flex flex-wrap items-baseline gap-x-2">
                                                    <span class="text-[11px] uppercase tracking-wide text-gray-400 w-32 shrink-0">{{ \App\Models\RentalActivityLog::fieldLabel($field) }}</span>
                                                    <span class="text-gray-500 line-through decoration-red-300">{{ \App\Models\RentalActivityLog::formatChangeValue($field, $pair[0] ?? null) }}</span>
                                                    <span class="text-gray-400">&rarr;</span>
                                                    <span class="text-gray-900 font-medium">{{ \App\Models\RentalActivityLog::formatChangeValue($field, $pair[1] ?? null) }}</span>
                                                </div>
                                            @endforeach
                                        </div>
                                    @endif
                                </li>
                            @endforeach
                        </ol>

                        <div class="mt-4">
                            {{ $logs->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
