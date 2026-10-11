<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Release Notes
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8">

            <a href="{{ route('help.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to Help</a>

            <p class="text-sm text-gray-500 leading-relaxed mt-4 max-w-2xl">
                A note each time there is a new feature or a fix important enough to know about. The top one is the
                latest release; older ones are kept below as an archive.
            </p>

            <div class="mt-10">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Latest Release</h3>
                <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-indigo-200 ring-1 ring-indigo-100 p-5">
                    <div class="flex items-center justify-between gap-3 mb-2">
                        <h4 class="font-semibold text-gray-900">{{ $current['title'] }}</h4>
                        <span class="shrink-0 text-xs font-medium text-gray-400 tabular-nums">{{ \Illuminate\Support\Carbon::parse($current['date'])->translatedFormat('d M Y') }}</span>
                    </div>
                    <ul class="text-sm text-gray-600 leading-relaxed list-disc pl-6 marker:text-gray-300 space-y-2.5">
                        @foreach($current['highlights'] as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>

            <div class="mt-10">
                <h3 class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-3">Release History (Archive)</h3>
                <div class="space-y-4">
                    @foreach($archived as $release)
                        <div class="bg-white overflow-hidden shadow-sm rounded-lg border border-gray-200 p-5 opacity-90">
                            <div class="flex items-center justify-between gap-3 mb-2">
                                <h4 class="font-semibold text-gray-700">{{ $release['title'] }}</h4>
                                <span class="shrink-0 text-xs font-medium text-gray-400 tabular-nums">{{ \Illuminate\Support\Carbon::parse($release['date'])->translatedFormat('d M Y') }}</span>
                            </div>
                            <ul class="text-sm text-gray-500 leading-relaxed list-disc pl-6 marker:text-gray-300 space-y-2.5">
                                @foreach($release['highlights'] as $point)
                                    <li>{{ $point }}</li>
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
