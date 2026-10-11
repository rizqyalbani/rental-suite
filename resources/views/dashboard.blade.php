<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Dashboard
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            @if(($staleOpnameSessions ?? collect())->isNotEmpty())
                <div class="mb-6 bg-amber-50 border border-amber-200 rounded-lg p-4">
                    <p class="text-sm font-medium text-amber-800 mb-2">
                        {{ $staleOpnameSessions->count() }} Stock Opname sessions have been open for more than 3 days. Do not forget to finish them.
                    </p>
                    <ul class="text-sm text-amber-700 space-y-1 list-disc list-inside">
                        @foreach($staleOpnameSessions as $s)
                            <li>
                                <a href="{{ route('stock-opname.show', $s) }}" class="underline hover:text-amber-900">
                                    {{ $s->store->name ?? 'Store' }} — opened {{ $s->started_at->format('d M Y') }} by {{ $s->startedBy->name ?? 'Unknown' }} ({{ $s->started_at->diffForHumans() }})
                                </a>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-6">
                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-sm font-medium text-gray-500">Active Rents</div>
                        <div class="mt-2 text-3xl font-bold text-indigo-600">{{ $stats['active_rentals'] }}</div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-sm font-medium text-gray-500">Total Items</div>
                        <div class="mt-2 text-3xl font-bold text-green-600">{{ $stats['total_outfits'] }}</div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-sm font-medium text-gray-500">Total Rents</div>
                        <div class="mt-2 text-3xl font-bold text-blue-600">{{ $stats['total_rentals'] }}</div>
                    </div>
                </div>

                <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                    <div class="p-6">
                        <div class="text-sm font-medium text-gray-500">Overdue Returns</div>
                        <div class="mt-2 text-3xl font-bold text-red-600">{{ $stats['returned_today'] }}</div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
