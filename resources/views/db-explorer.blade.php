<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Database Explorer</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-4 sm:p-6">
                    @if(empty($tables))
                        <p class="text-gray-500">No tables found.</p>
                    @else
                        <div class="flex flex-wrap gap-2 mb-4">
                            <a href="{{ route('db.explorer') }}" class="px-3 py-1.5 text-sm rounded-md {{ !$selectedTable ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">All Tables</a>
                            @foreach($tables as $table)
                                <a href="{{ route('db.explorer', ['table' => $table]) }}"
                                    class="px-3 py-1.5 text-sm rounded-md {{ $selectedTable === $table ? 'bg-indigo-600 text-white' : 'bg-gray-100 text-gray-700 hover:bg-gray-200' }}">
                                    {{ $table }}
                                </a>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            @if($selectedTable)
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-4 sm:p-6">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-lg font-medium text-gray-900">{{ $selectedTable }} ({{ $count }} rows)</h3>
                        <div class="flex gap-2 text-sm">
                            <span class="px-2 py-1 bg-gray-100 rounded text-gray-600">{{ $driver }}</span>
                        </div>
                    </div>

                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-xs">
                            <thead class="bg-gray-50">
                                <tr>
                                    @foreach($columns as $col)
                                        <th class="px-3 py-2 text-left font-medium text-gray-600 uppercase tracking-wider whitespace-nowrap">{{ $col }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($rows as $row)
                                    <tr class="hover:bg-gray-50">
                                        @foreach($columns as $col)
                                            <td class="px-3 py-2 whitespace-nowrap max-w-[250px] truncate" title="{{ $row->$col ?? '' }}">
                                                @if(is_null($row->$col))
                                                    <span class="text-gray-400">NULL</span>
                                                @elseif(strlen($row->$col) > 100)
                                                    <span class="text-gray-400" title="{{ $row->$col }}">{{ substr($row->$col, 0, 100) }}...</span>
                                                @else
                                                    {{ $row->$col }}
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ count($columns) }}" class="px-4 py-8 text-center text-gray-500">No data.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    <div class="mt-4">
                        {{ $rows->appends(request()->query())->links() }}
                    </div>
                </div>
            </div>
            @endif
        </div>
    </div>
</x-app-layout>
