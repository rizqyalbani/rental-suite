<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf
        {{-- Best-effort location, only actually enforced for Admin/Supervisor
             on a store that opted into a login geofence (App\Support\Geofence)
             — see the script at the bottom of this file. Denied/unsupported
             leaves these blank, which fails that check for the roles/stores
             it applies to; every other login is unaffected. --}}
        <input type="hidden" name="geo_lat" id="geoLat">
        <input type="hidden" name="geo_lng" id="geoLng">
        <input type="hidden" name="geo_accuracy" id="geoAccuracy">

        @php
            $loginLabel = match ($loginMethod ?? 'both') {
                'email' => 'Email',
                'username' => 'Username',
                default => 'Email or Username',
            };
        @endphp

        <!-- Email and/or Username -->
        <div>
            <x-input-label for="login" :value="__($loginLabel)" />
            <x-text-input id="login" class="block mt-1 w-full" type="text" name="login" :value="old('login')" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('login')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input id="password" class="block mt-1 w-full"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox" class="rounded border-gray-400 text-indigo-600 shadow-none focus:ring-indigo-500" name="remember">
                <span class="ms-2 text-sm text-gray-600">{{ __('Remember me') }}</span>
            </label>
        </div>

        <div class="flex items-center justify-end mt-4">
            @if (Route::has('password.request'))
                <a class="underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500" href="{{ route('password.request') }}">
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="ms-3">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>

    <script>
    (function () {
        if (!navigator.geolocation) return;
        navigator.geolocation.getCurrentPosition(function (pos) {
            var lat = document.getElementById('geoLat');
            var lng = document.getElementById('geoLng');
            var acc = document.getElementById('geoAccuracy');
            if (lat) lat.value = pos.coords.latitude;
            if (lng) lng.value = pos.coords.longitude;
            if (acc) acc.value = pos.coords.accuracy;
        }, function () {
            // Denied or failed — fields stay empty, submit proceeds anyway.
        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
    })();
    </script>
</x-guest-layout>
