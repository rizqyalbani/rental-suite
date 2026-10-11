<section>
    <header>
        <h2 class="text-lg font-medium text-gray-900">
            PIN Stock Opname
        </h2>

        <p class="mt-1 text-sm text-gray-600">
            This 6-digit PIN is different from your account password. It is used to mark items from the item QR page
            (for example after scanning with a phone camera), so you never have to type your account password on a phone that may not be your own.
        </p>
        <p class="mt-1 text-sm {{ $user->hasStockOpnamePin() ? 'text-green-700' : 'text-gray-500' }}">
            {{ $user->hasStockOpnamePin() ? 'PIN is set.' : 'No PIN yet.' }}
        </p>
    </header>

    <form method="post" action="{{ route('profile.opname-pin.update') }}" class="mt-6 space-y-6">
        @csrf
        @method('put')

        <div>
            <x-input-label for="stock_opname_pin" :value="'New PIN (6 digits)'" />
            <x-text-input id="stock_opname_pin" name="stock_opname_pin" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                class="mt-1 block w-full tracking-widest" autocomplete="off" />
            <x-input-error :messages="$errors->updateOpnamePin->get('stock_opname_pin')" class="mt-2" />
        </div>

        <div>
            <x-input-label for="stock_opname_pin_confirmation" :value="'Confirm PIN'" />
            <x-text-input id="stock_opname_pin_confirmation" name="stock_opname_pin_confirmation" type="text" inputmode="numeric" pattern="[0-9]*" maxlength="6"
                class="mt-1 block w-full tracking-widest" autocomplete="off" />
        </div>

        <div class="flex items-center gap-4">
            <x-primary-button>{{ __('Save') }}</x-primary-button>

            @if (session('status') === 'opname-pin-updated')
                <p
                    x-data="{ show: true }"
                    x-show="show"
                    x-transition
                    x-init="setTimeout(() => show = false, 2000)"
                    class="text-sm text-gray-600"
                >{{ __('Saved.') }}</p>
            @endif
        </div>
    </form>
</section>
