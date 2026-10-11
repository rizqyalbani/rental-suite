@php
    $isSuperAdmin = auth()->user()?->isSuperAdmin();
@endphp
<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            User Management
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-3xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative">
                    {{ session('success') }}
                </div>
            @endif
            @if(session('error'))
                <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded relative">
                    {{ session('error') }}
                </div>
            @endif

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Add New User</h3>
                    <form id="addUserForm" method="POST" action="{{ route('users.store') }}" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        @csrf
                        {{-- Only ever "1" while the "Store & User management" guided tour has this
                             step open (its trigger click sets it, its close resets it) — tags the
                             created row is_tutorial so it's clearly marked and auto-swept on tour
                             close. Stays "0" for every real submission. --}}
                        <input type="hidden" name="is_tutorial" id="addUserIsTutorial" value="0">
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                            <input type="text" name="name" id="addUserName" value="{{ old('name') }}" required
                                placeholder="e.g. Admin Name"
                                class="form-input w-full">
                            @error('name')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                            <input type="email" name="email" id="addUserEmail" value="{{ old('email') }}" required
                                placeholder="e.g. admin@example.com"
                                class="form-input w-full">
                            @error('email')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Password</label>
                            <input type="password" name="password" id="addUserPassword" required
                                placeholder="Min. 8 characters"
                                class="form-input w-full">
                            @error('password')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                            <select name="role" id="addUserRole" required
                                class="form-select w-full">
                                <option value="admin" {{ old('role') == 'admin' ? 'selected' : '' }}>Admin</option>
                                <option value="supervisor" {{ old('role') == 'supervisor' ? 'selected' : '' }}>Supervisor</option>
                                <option value="management" {{ old('role') == 'management' ? 'selected' : '' }}>Management</option>
                                <option value="accounting" {{ old('role') == 'accounting' ? 'selected' : '' }}>Accounting</option>
                                @if($isSuperAdmin)
                                    <option value="super_admin" {{ old('role') == 'super_admin' ? 'selected' : '' }}>Super Admin</option>
                                @endif
                            </select>
                            @error('role')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div class="md:col-span-2">
                            <button type="submit" id="addUserSubmitBtn"
                                class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer">
                                Create User
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <div id="allUsersSection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">All Users ({{ $users->count() }})</h3>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Name</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Email</th>
                                    <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">Role</th>
                                    <th class="px-4 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @foreach($users as $user)
                                    <tr class="hover:bg-gray-50">
                                        <td class="px-4 py-3 whitespace-nowrap text-sm font-medium text-gray-900">
                                            {{ $user->name }}
                                            @if($user->id === auth()->id())
                                                <span class="text-xs text-gray-400">(you)</span>
                                            @endif
                                            @if($user->is_tutorial)
                                                <span class="ml-1 px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">🎓 Practice</span>
                                            @endif
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-sm text-gray-500">{{ $user->email }}</td>
                                        <td class="px-4 py-3 whitespace-nowrap">
                                            @php
                                                $roleBadgeClass = match(true) {
                                                    $user->isSuperAdmin() => 'bg-purple-100 text-purple-800',
                                                    $user->isManagement() => 'bg-amber-100 text-amber-800',
                                                    $user->isSupervisor() => 'bg-teal-100 text-teal-800',
                                                    $user->isAccounting() => 'bg-emerald-100 text-emerald-800',
                                                    default => 'bg-blue-100 text-blue-800',
                                                };
                                                $roleLabel = match(true) {
                                                    $user->isSuperAdmin() => 'Super Admin',
                                                    $user->isManagement() => 'Management',
                                                    $user->isSupervisor() => 'Supervisor',
                                                    $user->isAccounting() => 'Accounting',
                                                    default => 'Admin',
                                                };
                                            @endphp
                                            <span class="px-2 py-1 inline-flex text-xs leading-5 font-semibold rounded-full {{ $roleBadgeClass }}">
                                                {{ $roleLabel }}
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 whitespace-nowrap text-right text-sm">
                                            <button onclick='openEdit(@json($user))'
                                                class="text-indigo-600 hover:text-indigo-900 mr-3 cursor-pointer">Edit</button>
                                            @if($user->hasStockOpnamePin())
                                                <form method="POST" action="{{ route('users.reset-opname-pin', $user) }}" class="inline mr-3"
                                                    onsubmit="return window.rentalConfirmSubmit(event, {{ \Illuminate\Support\Js::from('The opname PIN of '.$user->name.' will be deleted. They must set a new PIN themselves under Profile before they can use a PIN for opname again.') }}, { title: 'Reset opname PIN?', confirmButtonText: 'Reset', cancelButtonText: 'Cancel' })">
                                                    @csrf
                                                    <button @if($user->is_tutorial) id="tutorialResetPinBtn" @endif class="text-amber-600 hover:text-amber-900 cursor-pointer">Reset PIN</button>
                                                </form>
                                            @endif
                                            @if($user->id !== auth()->id())
                                                <form method="POST" action="{{ route('users.destroy', $user) }}" class="inline"
                                                    onsubmit="return window.rentalConfirmSubmit(event, {{ \Illuminate\Support\Js::from('Delete '.$user->name.'?') }}, { title: 'Delete user?', confirmButtonText: 'Delete', cancelButtonText: 'Cancel' })">
                                                    @csrf @method('DELETE')
                                                    <button class="text-red-600 hover:text-red-900 cursor-pointer">Delete</button>
                                                </form>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div id="editUserModal" class="hidden fixed inset-0 bg-gray-500 bg-opacity-75 flex items-center justify-center z-50 p-4">
        <div class="bg-white rounded-lg shadow-xl max-w-md w-full mx-4 p-6">
            <h3 class="text-lg font-medium text-gray-900 mb-4">Edit User</h3>
            <form id="editUserForm" method="POST">
                @csrf @method('PUT')
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                    <input type="text" name="name" id="editUserName" required
                        class="form-input w-full">
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                    <input type="email" name="email" id="editUserEmail" required
                        class="form-input w-full">
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Password (leave blank to keep current)</label>
                    <input type="password" name="password"
                        class="form-input w-full">
                </div>
                <div class="mb-3">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Role</label>
                    <select name="role" id="editUserRole" required
                        class="form-select w-full">
                        <option value="admin">Admin</option>
                        <option value="supervisor">Supervisor</option>
                        <option value="management">Management</option>
                        <option value="accounting">Accounting</option>
                        @if($isSuperAdmin)
                            <option value="super_admin">Super Admin</option>
                        @endif
                    </select>
                </div>
                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeUserModal()"
                        class="px-4 py-2 bg-gray-200 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-300 cursor-pointer">Cancel</button>
                    <button type="submit"
                        class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-medium text-white hover:bg-indigo-700 cursor-pointer">Update</button>
                </div>
            </form>
        </div>
    </div>

    <script>
        function openEdit(user) {
            document.getElementById('editUserForm').action = '/users/' + user.id;
            document.getElementById('editUserName').value = user.name;
            document.getElementById('editUserEmail').value = user.email;
            document.getElementById('editUserRole').value = user.role;
            document.getElementById('editUserModal').classList.remove('hidden');
        }
        function closeUserModal() {
            document.getElementById('editUserModal').classList.add('hidden');
        }
        document.getElementById('editUserModal').addEventListener('click', function(e) {
            if (e.target === this) closeUserModal();
        });
    </script>
</x-app-layout>
