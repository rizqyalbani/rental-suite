<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            HR
            <span class="ml-1.5 align-middle inline-block rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Coming soon</span>
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-4xl mx-auto sm:px-6 lg:px-8 space-y-6">
            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg p-6">
                <p class="text-lg font-semibold text-gray-900">HR and attendance is the next module.</p>
                <p class="mt-2 text-sm text-gray-600 leading-relaxed">
                    It will build on what already exists: users and roles, per-store assignment and cashier shifts.
                    Nothing on this page is active yet; it only shows what is planned.
                </p>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-100">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Planned first</p>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <p class="font-semibold text-gray-900">Attendance per store</p>
                        <p class="text-sm text-gray-600 mt-1">Clock in and clock out for each employee at their store.</p>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900">Attendance recap</p>
                        <p class="text-sm text-gray-600 mt-1">A monthly recap per store and per employee, with filters and a printable PDF.</p>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900">Shift scheduling</p>
                        <p class="text-sm text-gray-600 mt-1">A weekly roster per store (morning and afternoon) that assigns staff ahead of time.</p>
                    </div>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="px-6 py-4 border-b border-gray-100">
                    <p class="text-xs font-semibold uppercase tracking-wide text-gray-500">Possible later</p>
                </div>
                <div class="p-6 space-y-4">
                    <div>
                        <p class="font-semibold text-gray-900">Overtime tracking</p>
                        <p class="text-sm text-gray-600 mt-1">Hours beyond the rostered shift, summarised per employee.</p>
                    </div>
                    <div>
                        <p class="font-semibold text-gray-900">Employee documents</p>
                        <p class="text-sm text-gray-600 mt-1">Store contracts and IDs against each employee record.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
