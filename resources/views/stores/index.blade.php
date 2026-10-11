<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">Store Management</h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            @if(session('success'))
                <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded relative text-sm">
                    {{ session('success') }}
                </div>
            @endif

            <div id="storeAddSection" class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Add Store</h3>
                    <form id="storeAddForm" method="POST" action="{{ route('stores.store') }}" class="grid grid-cols-1 md:grid-cols-4 gap-3 items-end">
                        @csrf
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Name</label>
                            <input type="text" name="name" required class="form-input w-full" placeholder="Store 3">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                            <input type="text" name="code" required class="form-input w-full" placeholder="store-3">
                        </div>
                        <div class="flex items-center gap-2">
                            <input id="storeIsActive" type="checkbox" name="is_active" value="1" checked>
                            <label for="storeIsActive" class="text-sm text-gray-700">Active</label>
                        </div>
                        <div>
                            <button type="submit" class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-medium text-white hover:bg-indigo-700">Create</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Stores ({{ $stores->count() }})</h3>
                    <div class="space-y-4">
                        @foreach($stores as $store)
                            <div @if($loop->first) id="storeAccessCardFirst" @endif class="border border-gray-200 rounded-lg p-4">
                                <form id="storeUpdateForm_{{ $store->id }}" method="POST" action="{{ route('stores.update', $store) }}" class="grid grid-cols-1 md:grid-cols-6 gap-3 mb-4" enctype="multipart/form-data">
                                    @csrf
                                    @method('PUT')
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Name</label>
                                        <input type="text" name="name" value="{{ $store->name }}" class="form-input w-full" required>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Code</label>
                                        <input type="text" name="code" value="{{ $store->code }}" class="form-input w-full" required>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <input id="active_{{ $store->id }}" type="checkbox" name="is_active" value="1" {{ $store->is_active ? 'checked' : '' }}>
                                        <label for="active_{{ $store->id }}" class="text-sm text-gray-700">Active</label>
                                    </div>
                                    <div>
                                        @php
                                            $adminPhones = $storeAdminPhones[(int) $store->id] ?? [];
                                            $adminPhone1 = (string) ($adminPhones['phone_1'] ?? '');
                                            $adminPhone2 = (string) ($adminPhones['phone_2'] ?? '');
                                        @endphp
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Admin phone 1</label>
                                        <input type="text" name="store_admin_phone_1" value="{{ $adminPhone1 }}" class="form-input w-full" placeholder="08xx / +62...">
                                        @error('store_admin_phone_1')
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Admin phone 2</label>
                                        <input type="text" name="store_admin_phone_2" value="{{ $adminPhone2 }}" class="form-input w-full" placeholder="08xx / +62...">
                                        @error('store_admin_phone_2')
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div class="md:col-span-2">
                                        @php
                                            $contactAddress = (string) ($storeContactAddresses[(int) $store->id] ?? '');
                                        @endphp
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Address (invoice)</label>
                                        <textarea name="store_contact_address" rows="2" class="form-input w-full" placeholder="Full address for invoice header">{{ $contactAddress }}</textarea>
                                        @error('store_contact_address')
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Theme color</label>
                                        @php
                                            $primaryColor = $storePrimaryColors[(int) $store->id] ?? '#166534';
                                        @endphp
                                        <div class="flex items-center gap-2">
                                            <input type="color" name="store_primary_color" value="{{ $primaryColor }}" class="h-10 w-12 rounded border border-gray-300 bg-white p-1">
                                            <span class="text-xs font-mono text-gray-600">{{ $primaryColor }}</span>
                                        </div>
                                        @error('store_primary_color')
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                    <div class="md:col-span-2">
                                        @php
                                            $geo = $storeGeofences[(int) $store->id] ?? [];
                                            $geoEnabled = (bool) ($geo['geofence_enabled'] ?? false);
                                            $geoLat = $geo['geofence_latitude'] ?? null;
                                            $geoLng = $geo['geofence_longitude'] ?? null;
                                            $geoRadius = $geo['geofence_radius_m'] ?? 150;
                                        @endphp
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Geofence login (Admin/Supervisor)</label>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <label class="inline-flex items-center gap-1.5 text-xs text-gray-700">
                                                <input type="checkbox" name="geofence_enabled" value="1" {{ $geoEnabled ? 'checked' : '' }}>
                                                Require login from this location
                                            </label>
                                            <button type="button" data-geofence-use-location="{{ $store->id }}"
                                                class="px-2 py-1 text-xs rounded-md border border-gray-300 hover:bg-gray-50 cursor-pointer">
                                                Use My Current Location
                                            </button>
                                            <span data-geofence-status="{{ $store->id }}" class="text-xs text-gray-400"></span>
                                        </div>
                                        <div class="grid grid-cols-3 gap-2 mt-2">
                                            <input type="number" step="any" name="geofence_latitude" data-geofence-lat="{{ $store->id }}" value="{{ $geoLat }}"
                                                placeholder="Latitude" class="form-input w-full text-xs">
                                            <input type="number" step="any" name="geofence_longitude" data-geofence-lng="{{ $store->id }}" value="{{ $geoLng }}"
                                                placeholder="Longitude" class="form-input w-full text-xs">
                                            <input type="number" name="geofence_radius_m" value="{{ $geoRadius }}"
                                                placeholder="Radius (meters)" class="form-input w-full text-xs">
                                        </div>
                                        @error('geofence_enabled')
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                        <p class="text-xs text-gray-400 mt-1">Leave empty / turn the toggle off if this store does not need a login location limit.</p>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Logo</label>
                                        @php
                                            $logoUrl = $storeLogoUrls[(int) $store->id] ?? null;
                                            $logoDataUri = $storeLogoDataUris[(int) $store->id] ?? null;
                                        @endphp
                                        @if($logoUrl || $logoDataUri)
                                            <img src="{{ $logoDataUri ?: $logoUrl }}" alt="Store logo" class="h-10 mb-2">
                                        @endif
                                        <input type="file" name="store_logo" accept="image/png,image/jpeg,image/webp" class="form-input w-full">
                                        @error('store_logo')
                                            <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                        @enderror
                                    </div>
                                </form>

                                <form id="storeAccessForm_{{ $store->id }}" method="POST" action="{{ route('stores.users.assign', $store) }}">
                                    @csrf
                                    <label class="block text-xs font-medium text-gray-700 mb-2">Users with access</label>
                                    <div @if($loop->first) id="storeAccessCheckboxesFirst" @endif class="grid grid-cols-1 md:grid-cols-3 gap-2">
                                        @foreach($admins as $user)
                                            <label class="inline-flex items-center gap-2 rounded border border-gray-200 px-2 py-1">
                                                <input type="checkbox" name="user_ids[]" value="{{ $user->id }}" {{ $store->users->contains('id', $user->id) ? 'checked' : '' }}>
                                                <span class="text-sm text-gray-700">{{ $user->name }} <span class="text-xs text-gray-500">({{ $user->role }})</span>
                                                    @if($user->is_tutorial)
                                                        <span class="ml-1 px-1 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-800">🎓 Practice</span>
                                                    @endif
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                </form>
                                <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <button type="submit" form="storeUpdateForm_{{ $store->id }}" class="w-full px-3 py-2 bg-gray-900 text-white rounded-md text-sm hover:bg-black">Save Store</button>
                                    <button type="submit" form="storeAccessForm_{{ $store->id }}" @if($loop->first) id="storeAccessSaveBtnFirst" @endif class="w-full px-3 py-2 bg-indigo-600 text-white rounded-md text-sm hover:bg-indigo-700">Save Access</button>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.querySelectorAll('[data-geofence-use-location]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var storeId = btn.getAttribute('data-geofence-use-location');
            var status = document.querySelector('[data-geofence-status="' + storeId + '"]');
            if (!navigator.geolocation) {
                if (status) status.textContent = 'This browser does not support location.';
                return;
            }
            status && (status.textContent = 'Getting location…');
            btn.disabled = true;
            navigator.geolocation.getCurrentPosition(function (pos) {
                var latEl = document.querySelector('[data-geofence-lat="' + storeId + '"]');
                var lngEl = document.querySelector('[data-geofence-lng="' + storeId + '"]');
                if (latEl) latEl.value = pos.coords.latitude;
                if (lngEl) lngEl.value = pos.coords.longitude;
                status && (status.textContent = 'Location filled in (accuracy ~' + Math.round(pos.coords.accuracy) + 'm). Click Save Store to save.');
                btn.disabled = false;
            }, function () {
                status && (status.textContent = 'Failed to get the location: check the browser location permission.');
                btn.disabled = false;
            }, { enableHighAccuracy: true, timeout: 10000 });
        });
    });
    </script>
</x-app-layout>
