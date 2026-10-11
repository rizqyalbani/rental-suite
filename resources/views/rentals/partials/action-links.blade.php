@php
    $rentalData = $rentalData ?? (is_array($rental ?? null) ? $rental : []);
    $id = (int) ($rentalData['id'] ?? 0);
    $status = (string) ($rentalData['status'] ?? '');
    $pickedUp = (bool) ($rentalData['picked_up'] ?? false);
    $restrictAdminDeletes = \App\Support\AdminDeleteRestriction::enabled();
    $user = Auth::user();
    $mayCancelRental = ! ($restrictAdminDeletes && ($user?->isAdmin() ?? false));
    $mayViewActivityLog = $mayViewActivityLog ?? false;
    // Returned / cancelled are end states — only management can reopen one.
    $mayReopenRental = $user?->isManagement() || $user?->isSuperAdmin();
    // The onboarding tour's own practice rental is exempt from the "open a shift"
    // requirement entirely (see rejectUnlessOpenShift's tutorial_practice check) —
    // without this, the tour's own "click Edit" step would show "Open a shift to
    // use actions" instead of a real Edit link for anyone who hasn't opened one.
    $effectiveMutationsLocked = $rentalMutationsLocked && ! ($rentalData['is_tutorial'] ?? false);
@endphp

@if(! $mayOperateRentTransactions)
    <button type="button" onclick='openEditModal(@json($rentalData))' class="text-indigo-600 hover:text-indigo-900 text-sm cursor-pointer">View</button>
    @if($mayViewActivityLog)
        <button type="button" onclick="openActivityLogPanel({{ $id }}, {{ json_encode((string) ($rentalData['customer_name'] ?? '')) }})" class="text-slate-600 hover:text-slate-900 ml-3 text-sm cursor-pointer">History</button>
    @endif
    @if($mayDeleteRentals)
        <button type="button" onclick="quickDeleteRental({{ $id }})" class="text-red-600 hover:text-red-900 ml-3 text-sm cursor-pointer">Delete</button>
    @endif
@elseif($effectiveMutationsLocked)
    <span class="text-gray-400 text-xs">Open a shift to use actions</span>
    @if($mayViewActivityLog)
        <button type="button" onclick="openActivityLogPanel({{ $id }}, {{ json_encode((string) ($rentalData['customer_name'] ?? '')) }})" class="text-slate-600 hover:text-slate-900 ml-3 text-sm cursor-pointer">History</button>
    @endif
@else
    <button type="button" onclick='openEditModal(@json($rentalData))' class="text-indigo-600 hover:text-indigo-900 text-sm cursor-pointer">Edit</button>
    @if($mayViewActivityLog)
        <button type="button" onclick="openActivityLogPanel({{ $id }}, {{ json_encode((string) ($rentalData['customer_name'] ?? '')) }})" class="text-slate-600 hover:text-slate-900 ml-3 text-sm cursor-pointer">History</button>
    @endif
    @if($status === 'active')
        @if($pickedUp)
            <button type="button" onclick="updatePickup({{ $id }}, false)" class="text-slate-600 hover:text-slate-900 ml-3 text-sm cursor-pointer">Undo pickup</button>
        @else
            <button type="button" onclick="updatePickup({{ $id }}, true)" class="text-blue-600 hover:text-blue-900 ml-3 text-sm cursor-pointer">Mark picked up</button>
        @endif
        @if($pickedUp)
            <button type="button" onclick="updateStatus({{ $id }}, 'returned')" class="text-green-600 hover:text-green-900 ml-3 text-sm cursor-pointer">Return</button>
        @endif
        @if($mayCancelRental)
            <button type="button" onclick="updateStatus({{ $id }}, 'cancelled')" class="text-yellow-600 hover:text-yellow-900 ml-3 text-sm cursor-pointer">Cancel</button>
        @endif
    @endif
    @if($status === 'returned')
        <button type="button" onclick="updateStatus({{ $id }}, 'active')" class="text-blue-600 hover:text-blue-900 ml-3 text-sm cursor-pointer">Cancel Return</button>
    @endif
    @if($status === 'cancelled' && $mayReopenRental)
        <button type="button" onclick="updateStatus({{ $id }}, 'active')" class="text-blue-600 hover:text-blue-900 ml-3 text-sm cursor-pointer">Reactivate</button>
    @endif
    @if($mayDeleteRentals)
        <button type="button" onclick="quickDeleteRental({{ $id }})" class="text-red-600 hover:text-red-900 ml-3 text-sm cursor-pointer">Delete</button>
    @endif
@endif
