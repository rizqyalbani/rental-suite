<div
    x-data="{
        name: '{{ $name }}',
        value: '{{ $selected }}',
        search: '',
        filterTab: 'available',
        open: false,
        disabled: {{ $disabled ? 'true' : 'false' }},
        placeholder: '{{ $placeholder }}',
        options: {{ json_encode($options) }},
        init() {
            this.$watch('value', val => {
                this.$el.querySelector('input[type=hidden]').value = val;
                const event = new Event('change', { bubbles: true });
                this.$el.dispatchEvent(event);
            });
        },
        get display() {
            if (!this.value) return this.placeholder;
            const found = this.options.find(o => String(o.value) === String(this.value));
            if (!found) return this.placeholder;
            let label = found.label.replace(' ❌', '');
            if (found.available === false) label += ' 🔴 Rented';
            return label;
        },
        get filteredOptions() {
            const q = this.search.toLowerCase();
            return this.options.filter(o => {
                if (!o.label.toLowerCase().includes(q)) return false;
                if (this.filterTab === 'available' && o.available !== true) return false;
                if (this.filterTab === 'rented' && o.available !== false) return false;
                return true;
            });
        },
        toggle() {
            if (!this.disabled) {
                this.open = !this.open;
                if (this.open) this.$nextTick(() => this.$refs.search.focus());
            }
        },
        select(val) {
            this.value = val;
            this.open = false;
            this.search = '';
            this.filterTab = 'available';
        }
    }"
    class="relative"
    @click.away="open = false"
    id="{{ $id ?? $name }}_wrapper"
>
    <input type="hidden" name="{{ $name }}" :value="value">

    <button type="button"
        :disabled="disabled"
        @click="toggle()"
        class="form-select w-full text-left flex items-center justify-between disabled:opacity-50 disabled:cursor-not-allowed"
        :class="{ 'border-indigo-500 ring-1 ring-indigo-500': open }"
    >
        <span x-text="display" class="block truncate text-sm" :class="value ? 'text-gray-900' : 'text-gray-400'"></span>
        <svg class="h-4 w-4 text-gray-400 shrink-0 ml-1" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"></path>
        </svg>
    </button>

    <div x-show="open"
        x-cloak
        class="absolute z-50 mt-1 w-full bg-white border border-gray-300 rounded-lg shadow-lg overflow-hidden"
    >
        <div class="p-2 border-b border-gray-200 bg-white">
            <input type="text"
                x-ref="search"
                x-model="search"
                @click.stop
                placeholder="Search item..."
                class="form-input-search w-full">
        </div>
        <div class="flex gap-0.5 p-1.5 bg-gray-50 border-b border-gray-200">
            <button @click="filterTab = 'all'" type="button"
                class="px-2.5 py-1 text-xs rounded-md cursor-pointer font-medium transition border"
                :class="filterTab === 'all' ? 'bg-white border-indigo-600 text-gray-900 font-semibold' : 'border-gray-400 bg-gray-50 text-gray-500 hover:text-gray-700'">All</button>
            <button @click="filterTab = 'available'" type="button"
                class="px-2.5 py-1 text-xs rounded-md cursor-pointer font-medium transition border"
                :class="filterTab === 'available' ? 'bg-white border-indigo-600 text-green-700 font-semibold' : 'border-gray-400 bg-gray-50 text-gray-500 hover:text-gray-700'">Available</button>
            <button @click="filterTab = 'rented'" type="button"
                class="px-2.5 py-1 text-xs rounded-md cursor-pointer font-medium transition border"
                :class="filterTab === 'rented' ? 'bg-white border-indigo-600 text-red-700 font-semibold' : 'border-gray-400 bg-gray-50 text-gray-500 hover:text-gray-700'">Rented</button>
        </div>
        <div class="max-h-44 overflow-y-auto">
            <template x-for="opt in filteredOptions" :key="opt.value">
                <div @click="select(opt.value)"
                    class="px-3 py-2.5 text-sm cursor-pointer hover:bg-indigo-50 border-b border-gray-100 last:border-b-0"
                    :class="{ 'bg-indigo-100': String(value) === String(opt.value) }"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span x-text="opt.label.replace(' ❌', '')" class="font-medium truncate"></span>
                        <span x-show="opt.available === false" class="shrink-0 px-1.5 py-0.5 text-xs rounded border border-red-300 bg-red-100 text-red-700 font-medium">Rented</span>
                        <span x-show="opt.available !== false" class="shrink-0 px-1.5 py-0.5 text-xs rounded border border-green-300 bg-green-100 text-green-700 font-medium">Available</span>
                    </div>
                    <div x-show="opt.rental" class="text-xs text-red-500 mt-1" x-text="'Rented by ' + opt.rental.customer + ' from ' + opt.rental.start + ' to ' + opt.rental.end"></div>
                </div>
            </template>
            <div x-show="filteredOptions.length === 0" class="px-3 py-4 text-sm text-gray-400 text-center">
                No items match
            </div>
        </div>
    </div>
</div>

<style>
    [x-cloak] { display: none !important; }
</style>
