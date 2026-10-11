<div id="itemEditModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4">
    <div class="bg-white rounded-2xl shadow-xl w-full max-w-lg flex flex-col max-h-[90vh]">
        {{-- Header --}}
        <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100 shrink-0">
            <h2 class="text-base font-semibold text-gray-900">Edit Item Discounts</h2>
            <button type="button" onclick="closeItemEditModal()" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>

        {{-- Loading state --}}
        <div id="itemEditModalLoading" class="hidden px-5 py-10 text-center text-sm text-gray-400">Loading…</div>

        {{-- Content --}}
        <div id="itemEditModalContent" class="px-5 py-4 space-y-3 overflow-y-auto flex-1"></div>

        {{-- Footer --}}
        <div class="px-5 py-4 border-t border-gray-100 flex items-center justify-between gap-2 shrink-0">
            <span id="itemEditModalStatus" class="text-sm text-gray-400"></span>
            <div class="flex gap-2">
                <button type="button" onclick="closeItemEditModal()" class="px-4 py-2 text-sm rounded-lg border border-gray-200 text-gray-600 hover:bg-gray-50">Cancel</button>
                <button type="button" id="itemEditModalSaveBtn" onclick="submitItemEditModal()" class="px-4 py-2 text-sm rounded-lg bg-indigo-600 text-white hover:bg-indigo-700 font-medium">Save</button>
            </div>
        </div>
    </div>
</div>
