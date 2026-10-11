<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $outfit->code }} - Item Status</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
</head>
<body class="min-h-screen bg-gray-100 text-gray-900">
    <div class="max-w-2xl mx-auto px-4 py-8">
        <div class="bg-white rounded-xl shadow border border-gray-200 p-6">
            {{-- This whole page — including basic availability, previously
                 visible to anyone who scanned the tag — now requires a real
                 staff identity (login or opname PIN) before showing anything
                 about the item at all. Deliberate: a scanned tag alone is no
                 longer enough to see stock/rental info, only to reach this
                 gate. See OutfitController::renderItemStatus() for how
                 $opnameUser is resolved. --}}
            @if(! $opnameUser)
                <div class="text-center mb-5">
                    <p class="text-xs uppercase tracking-wide text-gray-500">Item Info</p>
                    <p class="text-sm text-gray-600 mt-2">Log in first to see the info for this item.</p>
                </div>
                <div>
                    <div class="flex items-center justify-center gap-3 mb-2">
                        <div class="flex text-xs border border-gray-300 rounded-md overflow-hidden shrink-0">
                            <button type="button" id="opnameTabPin" class="px-2.5 py-1 cursor-pointer">PIN</button>
                            <button type="button" id="opnameTabPassword" class="px-2.5 py-1 cursor-pointer border-l border-gray-300">Username &amp; Password</button>
                        </div>
                    </div>

                    <div id="opnameFormPin">
                        <form method="POST" action="{{ route('outfits.opname-pin', $outfit) }}" class="flex gap-2 justify-center">
                            @csrf
                            {{-- Best-effort location — see App\Support\Geofence. Only
                                 actually enforced for Admin/Supervisor on a store that
                                 opted into a login geofence; filled by the shared
                                 script at the bottom of this file. --}}
                            <input type="hidden" name="geo_lat" class="js-geo-lat">
                            <input type="hidden" name="geo_lng" class="js-geo-lng">
                            <input type="hidden" name="geo_accuracy" class="js-geo-accuracy">
                            <input type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6" name="pin" placeholder="6-digit PIN"
                                class="w-32 px-3 py-2 text-sm border border-gray-300 rounded-md text-center tracking-widest">
                            <button type="submit" class="px-3 py-2 text-sm rounded-md bg-gray-800 hover:bg-gray-900 text-white cursor-pointer">Log in</button>
                        </form>
                        @error('pin')
                            <p class="text-xs text-red-600 mt-1 text-center">{{ $message }}</p>
                        @enderror
                    </div>

                    {{-- A real account login, not scoped like the PIN — same
                         /login this app always uses, just submitted inline and
                         bounced back here instead of the dashboard (see
                         AuthenticatedSessionController::rememberRedirectIntent). --}}
                    <div id="opnameFormPassword" class="hidden">
                        <form method="POST" action="{{ route('login') }}" class="flex flex-col sm:flex-row gap-2 justify-center">
                            @csrf
                            <input type="hidden" name="redirect" value="{{ url()->full() }}">
                            <input type="hidden" name="geo_lat" class="js-geo-lat">
                            <input type="hidden" name="geo_lng" class="js-geo-lng">
                            <input type="hidden" name="geo_accuracy" class="js-geo-accuracy">
                            <input type="text" name="login" placeholder="Username / email" value="{{ old('login') }}" autocomplete="username"
                                class="sm:w-36 px-3 py-2 text-sm border border-gray-300 rounded-md">
                            <input type="password" name="password" placeholder="Password" autocomplete="current-password"
                                class="sm:w-36 px-3 py-2 text-sm border border-gray-300 rounded-md">
                            <button type="submit" class="px-3 py-2 text-sm rounded-md bg-gray-800 hover:bg-gray-900 text-white cursor-pointer whitespace-nowrap">Log in</button>
                        </form>
                        @error('login')
                            <p class="text-xs text-red-600 mt-1 text-center">{{ $message }}</p>
                        @enderror
                    </div>

                    <script>
                    (function () {
                        if (!navigator.geolocation) return;
                        navigator.geolocation.getCurrentPosition(function (pos) {
                            document.querySelectorAll('.js-geo-lat').forEach(function (el) { el.value = pos.coords.latitude; });
                            document.querySelectorAll('.js-geo-lng').forEach(function (el) { el.value = pos.coords.longitude; });
                            document.querySelectorAll('.js-geo-accuracy').forEach(function (el) { el.value = pos.coords.accuracy; });
                        }, function () {
                            // Denied or failed — fields stay empty, submit proceeds anyway.
                        }, { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 });
                    })();
                    </script>
                </div>

                <script>
                (function () {
                    var tabPin = document.getElementById('opnameTabPin');
                    var tabPw = document.getElementById('opnameTabPassword');
                    var formPin = document.getElementById('opnameFormPin');
                    var formPw = document.getElementById('opnameFormPassword');
                    var activeClass = ' bg-gray-800 text-white';
                    var inactiveClass = ' bg-white text-gray-600';

                    function show(which) {
                        var pinActive = which === 'pin';
                        formPin.classList.toggle('hidden', !pinActive);
                        formPw.classList.toggle('hidden', pinActive);
                        tabPin.className = 'px-2.5 py-1 cursor-pointer' + (pinActive ? activeClass : inactiveClass);
                        tabPw.className = 'px-2.5 py-1 cursor-pointer border-l border-gray-300' + (!pinActive ? activeClass : inactiveClass);
                    }

                    tabPin.addEventListener('click', function () { show('pin'); });
                    tabPw.addEventListener('click', function () { show('password'); });

                    // Land back on whichever tab actually has the error, instead
                    // of silently hiding it behind the other one. A quoted Blade
                    // echo here would HTML-escape the quote marks into entities —
                    // invalid inside a script tag, which isn't entity-decoded — so
                    // this outputs a bare boolean instead of a quoted string.
                    show({{ $errors->has('login') || $errors->has('password') ? 'true' : 'false' }} ? 'password' : 'pin');
                })();
                </script>
            @else
            {{-- Only for a real login — a PIN-verified identity has no
                 access to any other app route (StockOpnamePin is
                 deliberately not a real Auth::login()), so there is
                 nowhere real for "Back" to go for that case. --}}
            @unless($opnameViaPin)
                <div class="mb-4">
                    <a href="{{ $activeOpnameSession ? route('stock-opname.show', $activeOpnameSession) : route('dashboard') }}"
                        class="text-xs text-gray-500 hover:text-gray-700">&larr; Back</a>
                </div>
            @endunless
            <div class="flex items-start justify-between gap-3">
                <div>
                    <p class="text-xs uppercase tracking-wide text-gray-500">Item Availability</p>
                    <h1 class="text-2xl font-bold mt-1">{{ $outfit->code }}</h1>
                    @if($outfit->new_code)
                        <p class="text-sm text-gray-600 mt-1">New Code: {{ $outfit->new_code }}</p>
                    @endif
                    @if($outfit->description)
                        <p class="text-sm text-gray-600 mt-1">{{ $outfit->description }}</p>
                    @endif
                </div>
                <span class="px-3 py-1 rounded-full text-xs font-semibold {{ $availableStock > 0 ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }}">
                    {{ $availableStock > 0 ? 'Available' : 'Fully Rented' }}
                </span>
            </div>

            <div class="grid grid-cols-3 gap-3 mt-6">
                <div class="rounded-lg border border-gray-200 p-3 bg-gray-50">
                    <p class="text-xs text-gray-500 uppercase">Stock</p>
                    <p class="text-xl font-semibold mt-1">{{ $stock }}</p>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 bg-gray-50">
                    <p class="text-xs text-gray-500 uppercase">Active Rent</p>
                    <p class="text-xl font-semibold mt-1">{{ $activeCount }}</p>
                </div>
                <div class="rounded-lg border border-gray-200 p-3 bg-gray-50">
                    <p class="text-xs text-gray-500 uppercase">Available Now</p>
                    <p class="text-xl font-semibold mt-1">{{ $availableStock }}</p>
                </div>
            </div>

            <div class="mt-6">
                <h2 class="text-sm font-semibold text-gray-800 mb-2">Current Rentals</h2>
                @if($activeRentals->isEmpty())
                    <p class="text-sm text-gray-600">No active rental currently using this item.</p>
                @else
                    <div class="space-y-2">
                        @foreach($activeRentals as $rental)
                            <div class="border border-gray-200 rounded-lg p-3">
                                <p class="font-medium text-sm">{{ $rental->customer_name }}</p>
                                <p class="text-xs text-gray-600 mt-1">
                                    {{ $rental->start_date->format('d M Y') }} - {{ $rental->end_date->format('d M Y') }}
                                </p>
                                <p class="text-xs text-gray-600 mt-1">{{ $rental->phone }}</p>
                                @if($rental->handledBy)
                                    <p class="text-xs text-gray-700 mt-2">
                                        <span class="font-medium text-gray-600">Handled by:</span>
                                        {{ $rental->handledBy->name }}
                                        @if($rental->handledBy->role)
                                            @php
                                                $_hbRoleLabel = \Illuminate\Support\Str::headline($rental->handledBy->role);
                                                $_hbShowRole = strcasecmp(trim($rental->handledBy->name), $_hbRoleLabel) !== 0;
                                            @endphp
                                            @if($_hbShowRole)
                                                <span class="text-gray-500">({{ $_hbRoleLabel }})</span>
                                            @endif
                                        @endif
                                    </p>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>

            @if($activeOpnameSession)
                @php
                    $opnameStatus = $opnameItem->status ?? 'unchecked';
                    $opnameLabels = ['ok' => 'Present', 'missing' => 'Missing', 'damaged' => 'Damaged', 'unchecked' => 'Not Checked'];
                    $opnameClasses = [
                        'ok' => 'bg-green-100 text-green-800',
                        'missing' => 'bg-red-100 text-red-800',
                        'damaged' => 'bg-amber-100 text-amber-800',
                        'unchecked' => 'bg-gray-100 text-gray-500',
                    ];
                @endphp
                <div class="mt-6 border-t border-gray-200 pt-4">
                    <div class="flex items-center justify-between gap-2 mb-2">
                        <h2 class="text-sm font-semibold text-gray-800">Opname Session in Progress</h2>
                        <span class="px-2 py-1 text-xs font-semibold rounded-full {{ $opnameClasses[$opnameStatus] }}">{{ $opnameLabels[$opnameStatus] }}</span>
                    </div>
                    <p class="text-xs text-gray-500">
                        Session opened by <strong>{{ $activeOpnameSession->startedBy->name ?? 'Unknown' }}</strong>,
                        {{ $activeOpnameSession->started_at->diffForHumans() }}.
                    </p>
                    <p class="text-xs text-gray-500 mb-3">
                        You are marking as <strong>{{ $opnameUser->name }}</strong>.
                    </p>

                    @if($activeRentals->isNotEmpty())
                        <div class="mb-3 space-y-2">
                            @foreach($activeRentals as $rental)
                                <div class="border border-blue-200 bg-blue-50 rounded-lg p-3">
                                    <p class="text-xs font-semibold text-blue-800">This item is currently rented, which does not mean it is missing</p>
                                    <p class="text-sm font-medium text-gray-900 mt-1">{{ $rental->customer_name }}</p>
                                    <p class="text-xs text-gray-600 mt-0.5">
                                        {{ $rental->start_date->format('d M Y') }} – {{ $rental->end_date->format('d M Y') }}
                                    </p>
                                </div>
                            @endforeach
                        </div>
                    @endif

                    {{-- No note field by design — this page is a fast scan-then-tap
                         round trip; a note can still be added later from the full
                         Stock Opname page (same outfit, same session, just updates
                         this row, nothing is lost). --}}
                    @error('status')
                        <p id="opnameMarkError" class="mb-2 text-xs text-red-600">{{ $message }}</p>
                    @enderror
                    <div class="flex gap-2">
                        @foreach(['ok' => 'Present', 'missing' => 'Missing', 'damaged' => 'Damaged'] as $statusValue => $statusLabel)
                            <form method="POST" action="{{ route('outfits.opname-mark', $outfit) }}" class="flex-1">
                                @csrf
                                <input type="hidden" name="status" value="{{ $statusValue }}">
                                <button type="submit" class="w-full px-3 py-2 text-sm rounded-md border border-gray-300 hover:bg-{{ $statusValue === 'missing' ? 'red' : ($statusValue === 'damaged' ? 'amber' : 'gray') }}-50 cursor-pointer">{{ $statusLabel }}</button>
                            </form>
                        @endforeach
                    </div>
                    @if($opnameViaPin)
                        <form method="POST" action="{{ route('outfits.opname-pin.logout', $outfit) }}" class="mt-3 text-right">
                            @csrf
                            <button type="submit" class="text-xs text-gray-400 hover:text-gray-600 cursor-pointer">Not {{ $opnameUser->name }}? Log out</button>
                        </form>
                    @endif
                </div>
            @else
                {{-- Identified (login or PIN) but this outfit's store has no
                     opname running right now — say so instead of silently
                     showing nothing, which otherwise looks like the PIN/login
                     did nothing at all. --}}
                <div class="mt-6 border-t border-gray-200 pt-4 flex items-center justify-between gap-2">
                    <p class="text-xs text-gray-500">
                        There is no opname session running for this store right now. You are logged in as <strong>{{ $opnameUser->name }}</strong>.
                    </p>
                    @if($opnameViaPin)
                        <form method="POST" action="{{ route('outfits.opname-pin.logout', $outfit) }}">
                            @csrf
                            <button type="submit" class="text-xs text-gray-400 hover:text-gray-600 cursor-pointer whitespace-nowrap">Log out</button>
                        </form>
                    @endif
                </div>
            @endif
            @endif
        </div>
    </div>
</body>
</html>
