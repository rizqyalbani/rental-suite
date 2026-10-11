<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Stock Opname
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

            @if($activeSession)
                <div class="bg-amber-50 border border-amber-300 rounded-lg p-5 mb-6 flex items-center justify-between gap-4 flex-wrap">
                    <div>
                        <p class="font-semibold text-amber-900">An opname session is in progress</p>
                        <p class="text-sm text-amber-800 mt-0.5">
                            Started by {{ $activeSession->startedBy->name ?? 'Unknown' }} &bull;
                            {{ $activeSession->started_at->diffForHumans() }}
                        </p>
                    </div>
                    <a href="{{ route('stock-opname.show', $activeSession) }}"
                        class="px-4 py-2 bg-amber-600 hover:bg-amber-700 text-white text-sm font-semibold rounded-md">
                        Continue &rarr;
                    </a>
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg" id="stockOpnameIndexCard">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-1">
                        <h3 class="text-lg font-medium text-gray-900">Opname History ({{ $storeLabel ?? '' }})</h3>
                        @if($mayManage)
                            <form method="POST" action="{{ route('stock-opname.store') }}">
                                @csrf
                                <button type="submit" id="stockOpnameStartBtn" {{ $activeSession ? 'disabled' : '' }}
                                    class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer disabled:opacity-50 disabled:cursor-not-allowed disabled:hover:bg-indigo-600">
                                    Start New Opname
                                </button>
                            </form>
                        @endif
                    </div>
                    <p class="text-xs text-gray-500 mb-4">
                        Count the physical items in the store against system data. Admins can help mark the items
                        that were checked; starting and finishing a session is for Supervisor and above only.
                    </p>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Started</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Started By</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Status</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Differences</th>
                                    <th class="px-4 py-3"></th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($sessions as $session)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $session->started_at->format('d M Y H:i') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $session->startedBy->name ?? 'Unknown' }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @if($session->isCompleted())
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-green-100 text-green-800">Completed</span>
                                            @else
                                                <span class="px-2 py-1 text-xs font-semibold rounded-full bg-amber-100 text-amber-800">In progress</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-right tabular-nums">
                                            @if($session->exceptions_count > 0)
                                                <span class="text-red-700 font-medium">{{ $session->exceptions_count }} items</span>
                                            @else
                                                <span class="text-gray-400">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                                            <a href="{{ route('stock-opname.show', $session) }}" class="text-indigo-600 hover:text-indigo-900">View &rarr;</a>
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="5" class="px-4 py-6 text-center text-sm text-gray-400">No opname sessions yet.</td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        {{ $sessions->links() }}
                    </div>
                </div>
            </div>
        </div>
    </div>

    <button type="button" data-open-stock-opname-tour
        class="fixed right-4 bottom-24 z-[90] flex h-11 w-11 items-center justify-center rounded-full bg-indigo-600 text-white shadow-lg hover:bg-indigo-700 transition cursor-pointer"
        aria-label="Help for this page" title="Help for this page">
        <span class="text-lg font-bold leading-none">?</span>
    </button>
</x-app-layout>
