<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Opname History — {{ $outfit->code }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">
            <a href="{{ route('stock-opname.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to History</a>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mt-4">
                <div class="p-6">
                    <div class="flex items-center justify-between mb-1 flex-wrap gap-2">
                        <h3 class="text-lg font-medium text-gray-900">{{ $outfit->code }}</h3>
                        <span class="text-sm text-gray-500">{{ $outfit->description ?? '—' }}</span>
                    </div>
                    <p class="text-xs text-gray-500 mb-4">
                        Every mark recorded for this item, from any opname session, not just the one currently in progress.
                    </p>

                    <div class="overflow-x-auto border border-gray-200 rounded-lg">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Date</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Status</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Notes</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Photo</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Marked By</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Session</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($items as $item)
                                    @php
                                        $badgeClasses = [
                                            'ok' => 'bg-green-100 text-green-800',
                                            'missing' => 'bg-red-100 text-red-800',
                                            'damaged' => 'bg-amber-100 text-amber-800',
                                        ];
                                        $badgeLabels = ['ok' => 'Present', 'missing' => 'Missing', 'damaged' => 'Damaged'];
                                    @endphp
                                    <tr>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $item->updated_at->format('d M Y H:i') }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            <span class="px-2 py-1 text-xs font-semibold rounded-full {{ $badgeClasses[$item->status] }}">{{ $badgeLabels[$item->status] }}</span>
                                        </td>
                                        <td class="px-4 py-3 text-sm text-gray-500">{{ $item->note ?? '—' }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm">
                                            @if($item->photo_path)
                                                <a href="{{ route('stock-opname.item-photo', $item) }}" target="_blank" rel="noopener" class="text-indigo-600 hover:underline">View</a>
                                            @else
                                                <span class="text-gray-300">—</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-900">{{ $item->markedBy->name ?? 'Unknown' }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm">
                                            @if($item->stockOpname)
                                                <a href="{{ route('stock-opname.show', $item->stockOpname) }}" class="text-indigo-600 hover:text-indigo-900">
                                                    {{ $item->stockOpname->started_at->format('d M Y') }}
                                                    @if($item->stockOpname->isCompleted())
                                                        <span class="text-gray-400">(completed)</span>
                                                    @else
                                                        <span class="text-amber-600">(in progress)</span>
                                                    @endif
                                                </a>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-4 py-6 text-center text-sm text-gray-400">
                                            Never marked (Present/Missing/Damaged) in any opname session. It is always "Not Checked" in each running session.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
