<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            VAT Questions for the Client
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            <a href="{{ route('help.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">&larr; Back to Help</a>

            @if(session('success'))
                <div class="mt-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative text-sm">
                    {{ session('success') }}
                </div>
            @endif

            <div class="mt-4 bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg text-sm">
                Fill this in during or after talking with the client (or their accountant/tax consultant for the ones
                marked <span class="font-medium">tax consultant needed</span>). Answers are saved, so come back any
                time to continue or to check what is still empty.
            </div>

            @php
                $answeredCount = collect($questions)->keys()->filter(fn ($key) => filled($saved->get($key)?->answer))->count();
                $totalCount = count($questions);
            @endphp
            <div class="mt-6 flex items-center gap-3">
                <div class="flex-1 h-2 bg-gray-200 rounded-full overflow-hidden">
                    <div class="h-full bg-indigo-600" style="width: {{ $totalCount > 0 ? round($answeredCount / $totalCount * 100) : 0 }}%"></div>
                </div>
                <span class="text-sm text-gray-500 whitespace-nowrap">{{ $answeredCount }} / {{ $totalCount }} answered</span>
            </div>

            <form method="POST" action="{{ route('help.ppn-briefing.save') }}" class="mt-8 space-y-6">
                @csrf
                @foreach($questions as $key => $q)
                    @php $existing = $saved->get($key); @endphp
                    <div class="bg-white border border-gray-200 rounded-lg p-5">
                        <div class="flex items-start justify-between gap-3 flex-wrap">
                            <h3 class="font-semibold text-gray-900">{{ $loop->iteration }}. {{ $q['title'] }}</h3>
                            @if($q['needsConsultant'])
                                <span class="shrink-0 inline-flex items-center gap-1 text-xs font-medium text-amber-800 bg-amber-100 rounded-full px-2.5 py-1">Tax consultant needed</span>
                            @endif
                        </div>
                        <p class="text-sm text-gray-500 mt-2 leading-relaxed">{{ $q['why'] }}</p>
                        <p class="text-sm font-medium text-gray-800 mt-3">{{ $q['question'] }}</p>

                        <textarea
                            name="answers[{{ $key }}]"
                            rows="3"
                            class="form-textarea w-full mt-3 text-sm"
                            placeholder="Client's answer / outcome of the discussion..."
                        >{{ old('answers.'.$key, $existing?->answer) }}</textarea>

                        @if($existing?->answered_at)
                            <p class="text-xs text-gray-400 mt-2">
                                Answered {{ $existing->answered_at->diffForHumans() }}
                                @if($existing->answeredBy) by {{ $existing->answeredBy->name }} @endif
                            </p>
                        @endif
                    </div>
                @endforeach

                <div class="flex justify-end">
                    <button type="submit" class="px-5 py-2.5 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">
                        Save Answers
                    </button>
                </div>
            </form>
        </div>
    </div>
</x-app-layout>
