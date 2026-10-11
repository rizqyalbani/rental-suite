<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-gray-800 leading-tight">
            Item Inventory
        </h2>
    </x-slot>

    @php
        $pricing = \App\Support\StoreCache::get('pricing_enabled', true);
        $pricingTaxPercent = (float) \App\Support\StoreCache::get('pricing_tax_percent', 0);
        $pricingPricesIncludeTax = (bool) \App\Support\StoreCache::get('pricing_prices_include_tax', false);
        $mayMutateItems = auth()->user()?->mayMutateItems() ?? false;

        $bulkNewCodeOldRepublish = '';
        $bulkNewCodeNewRepublish = '';
        if (old('pairs')) {
            $oldLinesSplit = preg_split("/\r\n|\n|\r/", (string) old('pairs'));
            $oCol = [];
            $nCol = [];
            foreach ($oldLinesSplit as $_ln) {
                $_parts = explode("\t", $_ln, 2);
                $oCol[] = $_parts[0] ?? '';
                $nCol[] = $_parts[1] ?? '';
            }
            $bulkNewCodeOldRepublish = implode("\n", $oCol);
            $bulkNewCodeNewRepublish = implode("\n", $nCol);
        }

        $outfitsFlashSegments = [];
        $outfitsFlashSeen = [];
        $pushOutfitsFlash = static function (string $kind, string $raw) use (&$outfitsFlashSegments, &$outfitsFlashSeen): void {
            $text = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '') {
                return;
            }
            $key = $kind.'|'.$text;
            if (isset($outfitsFlashSeen[$key])) {
                return;
            }
            $outfitsFlashSeen[$key] = true;
            $outfitsFlashSegments[] = ['kind' => $kind, 'text' => $text];
        };
        if (session()->has('success')) {
            $pushOutfitsFlash('success', (string) session('success'));
        }
        if (session()->has('error')) {
            $pushOutfitsFlash('error', (string) session('error'));
        }
        foreach ($errors->all() as $msg) {
            $pushOutfitsFlash('error', (string) $msg);
        }
    @endphp

    <div class="py-12">
        <div class="max-w-5xl mx-auto sm:px-6 lg:px-8">
            <noscript>
                @if(session('success'))
                    <div class="mb-4 bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded text-sm">{{ session('success') }}</div>
                @endif
                @if(session('error'))
                    <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-sm">{{ session('error') }}</div>
                @endif
                @foreach($errors->all() as $errorMsg)
                    <div class="mb-4 bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded text-sm">{{ $errorMsg }}</div>
                @endforeach
            </noscript>
            <div id="outfitsAjaxFlash" class="hidden mb-4 px-4 py-3 rounded relative text-sm" role="status"></div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg mb-6">
                <div class="p-6">
                    <h3 class="text-lg font-medium text-gray-900 mb-4">Add New Item</h3>
                    <form id="createOutfitForm" method="POST" action="{{ route('outfits.store') }}" class="flex gap-4 items-end flex-wrap {{ $mayMutateItems ? '' : 'opacity-60' }}" novalidate>
                        @csrf
                        <input type="hidden" name="form_source" value="create">
                        <div class="min-w-[130px] flex-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Code</label>
                            <input type="text" name="code" value="{{ old('form_source') === 'create' ? old('code') : '' }}" class="form-input w-full" placeholder="e.g. OUTFIT-001" required {{ $mayMutateItems ? '' : 'disabled' }}>
                            @error('code')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div class="min-w-[130px] flex-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1">New Code</label>
                            <input type="text" name="new_code" value="{{ old('form_source') === 'create' ? old('new_code') : '' }}" class="form-input w-full" placeholder="e.g. ALT-001" {{ $mayMutateItems ? '' : 'disabled' }}>
                            @error('new_code')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <div class="min-w-[130px] flex-[2]">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Description</label>
                            <input type="text" name="description" value="{{ old('form_source') === 'create' ? old('description') : '' }}" class="form-input w-full" placeholder="e.g. Black Tuxedo" {{ $mayMutateItems ? '' : 'disabled' }}>
                        </div>
                        @if($pricing)
                            <div class="min-w-[100px] flex-1">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Price</label>
                                <input type="text" name="price" id="createPrice" value="{{ old('form_source') === 'create' ? old('price') : '' }}" inputmode="decimal" autocomplete="off" class="form-input w-full" placeholder="e.g. 50.000" {{ $mayMutateItems ? '' : 'disabled' }}>
                                @error('price')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                            </div>
                        @endif
                        <div class="min-w-[100px] flex-1">
                            <label class="block text-sm font-medium text-gray-700 mb-1">Stock</label>
                            <input type="number" name="stock" value="{{ old('form_source') === 'create' ? old('stock', 1) : 1 }}" step="1" min="0" class="form-input w-full" placeholder="1" {{ $mayMutateItems ? '' : 'disabled' }}>
                            @error('stock')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                        </div>
                        <button type="submit" class="inline-flex items-center px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer" {{ $mayMutateItems ? '' : 'disabled' }}>Add Item</button>
                    </form>
                    @if(! $mayMutateItems)
                        <p class="text-xs text-gray-600 mt-2">View only. Ask management to create or update items.</p>
                    @endif

                    <fieldset class="mt-4 pt-4 border-t space-y-2 {{ $mayMutateItems ? '' : 'opacity-60' }}" {{ $mayMutateItems ? '' : 'disabled' }}>
                        <button type="button" onclick="toggleSection('bulkForm')" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium cursor-pointer">+ Bulk Import</button>
                        <div id="bulkForm" class="hidden mt-2">
                            <form method="POST" action="{{ route('outfits.bulk') }}">
                                @csrf
                                <label class="block text-sm font-medium text-gray-700 mb-1">Paste item codes (one per line)</label>
                                <textarea name="codes" rows="4" class="form-textarea w-full font-mono" placeholder="SPBL1&#10;SPBL2&#10;SPBL3">{{ old('codes') }}</textarea>
                                @error('codes')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                                <button type="submit" class="mt-2 px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer">Import All</button>
                            </form>
                        </div>

                        <button type="button" onclick="toggleSection('bulkNewCodeForm')" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium cursor-pointer">+ Bulk Edit Codes</button>
                        <div id="bulkNewCodeForm" class="hidden mt-2">
                            <form id="bulkNewCodeFormEl" method="POST" action="{{ route('outfits.bulk-new-code') }}" class="space-y-3">
                                @csrf
                                <p class="text-xs text-gray-600 leading-relaxed">Edit <strong>current code</strong> and <strong>new code</strong> side by side (one row per line). Lines match by row number — same as one tab-separated line per row.</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Current code (primary)</label>
                                        <textarea id="bulkNewCodeOldCol" rows="7" class="form-textarea w-full font-mono text-sm" placeholder="B01&#10;B02" autocomplete="off">{{ $bulkNewCodeOldRepublish }}</textarea>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">New code (alternative)</label>
                                        <textarea id="bulkNewCodeNewCol" rows="7" class="form-textarea w-full font-mono text-sm" placeholder="DK01&#10;DK02" autocomplete="off">{{ $bulkNewCodeNewRepublish }}</textarea>
                                    </div>
                                </div>
                                <button type="submit" class="mt-1 px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer">Update Codes</button>
                            </form>
                        </div>

                        @if($pricing)
                        <button type="button" onclick="toggleSection('bulkPriceForm')" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium cursor-pointer">+ Bulk Set Prices</button>
                        <div id="bulkPriceForm" class="hidden mt-2">
                            <form method="POST" action="{{ route('outfits.bulk-price') }}">
                                @csrf
                                <label class="block text-sm font-medium text-gray-700 mb-1">Paste code tab price (one per line)</label>
                                <textarea name="pairs" rows="4" class="form-textarea w-full font-mono" placeholder="SPBL1&#9;150000&#10;SPBL2&#9;175000&#10;SPBL3&#9;200000">{{ old('pairs') }}</textarea>
                                @error('pairs')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                                <button type="submit" class="mt-2 px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer">Set Prices</button>
                            </form>
                        </div>

                        <button type="button" onclick="toggleSection('bulkDiscountForm')" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium cursor-pointer">+ Bulk Discount</button>
                        <div id="bulkDiscountForm" class="hidden mt-2">
                            <form method="POST" action="{{ route('outfits.bulk-discount') }}">
                                @csrf
                                <label class="block text-sm font-medium text-gray-700 mb-1">Paste item codes (one per line)</label>
                                <textarea name="codes" rows="4" class="form-textarea w-full font-mono" placeholder="SPBL1&#10;SPBL2&#10;SPBL3">{{ old('codes') }}</textarea>
                                @error('codes')<p class="text-red-500 text-xs mt-1">{{ $message }}</p>@enderror
                                <div class="flex gap-3 items-end mt-2">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Type</label>
                                        <select name="type" class="form-select">
                                            <option value="percent">Percent (%)</option>
                                            <option value="fixed">Fixed (Rp)</option>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-600 mb-1">Amount</label>
                                        <input type="number" name="amount" step="0.01" min="0" required class="form-input w-28" placeholder="10">
                                    </div>
                                    <button type="submit" class="px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer">Apply</button>
                                </div>
                            </form>
                        </div>
                        @endif

                        @if((Auth::user()->isManagement() || Auth::user()->isSuperAdmin()) && isset($availableStores) && $availableStores->count() > 1)
                            <button type="button" onclick="toggleSection('stockTransferForm')" class="text-sm text-indigo-600 hover:text-indigo-800 font-medium cursor-pointer">+ Transfer Stock to Other Store</button>
                            <div id="stockTransferForm" class="hidden mt-2 rounded-md border border-indigo-100 bg-indigo-50 p-3">
                                <form method="POST" action="{{ route('outfits.transfer-stock') }}" class="grid grid-cols-1 md:grid-cols-4 gap-2 items-end">
                                    @csrf
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Item code</label>
                                        <input type="text" name="outfit_code" class="form-input w-full" required>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Target store</label>
                                        <select name="target_store_id" class="form-select w-full" required>
                                            @foreach($availableStores as $store)
                                                @if((int) $store->id !== (int) ($currentStoreId ?? 0))
                                                    <option value="{{ $store->id }}">{{ $store->name }}</option>
                                                @endif
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-gray-700 mb-1">Quantity</label>
                                        <input type="number" name="quantity" min="1" value="1" class="form-input w-full" required>
                                    </div>
                                    <div>
                                        <button type="submit" class="w-full px-4 py-2 bg-indigo-600 border border-transparent rounded-md font-semibold text-xs text-white uppercase tracking-widest hover:bg-indigo-700 transition cursor-pointer">Transfer</button>
                                    </div>
                                </form>
                                <p class="mt-2 text-xs text-gray-600">Transfers stock by item code. If target item code does not exist, it is auto-created with copied details.</p>
                            </div>
                        @endif
                    </fieldset>
                </div>
            </div>

            <div class="bg-white overflow-hidden shadow-sm sm:rounded-lg">
                <div class="p-6">
                    @php
                        $outfitsTotalCountValue = method_exists($outfits, 'total') ? (int) $outfits->total() : (int) $outfits->count();
                        $outfitsInsightsEnabled = request()->boolean('insights', false);
                    @endphp
                    <div class="flex flex-wrap items-center justify-between gap-3 mb-4">
                        <h3 class="text-lg font-medium text-gray-900">All Items (<span id="outfitsTotalCount">{{ $outfitsTotalCountValue }}</span>)</h3>
                        <a
                            href="{{ $outfitsInsightsEnabled ? route('outfits.index') : route('outfits.index', ['insights' => 1]) }}"
                            class="text-xs font-semibold rounded-full px-3 py-1 border border-gray-300 bg-white text-gray-800 hover:bg-gray-50"
                        >
                            {{ $outfitsInsightsEnabled ? 'Hide diagnostics' : 'Load diagnostics' }}
                        </a>
                    </div>

                    @if(isset($duplicateCodePanels) && count($duplicateCodePanels) > 0)
                        <div id="outfitDuplicatePanel" class="relative mb-5 rounded-lg border border-amber-300 bg-amber-50/90 p-4 shadow-sm">
                            <div class="flex flex-wrap items-start justify-between gap-2 mb-2">
                                <div>
                                    <h4 class="text-sm font-semibold text-amber-950">Possible duplicate codes</h4>
                                    <p class="text-xs text-amber-900/90 mt-0.5 max-w-3xl leading-relaxed">
                                        Same item spelled differently (spaces, leading zeros). Each line shows the stored code and whether it is Available or has active rentals (N active). Refresh the page after edits to refresh labels.
                                    </p>
                                </div>
                                <button type="button" onclick="toggleOutfitDupPanelCollapse()" class="shrink-0 text-xs font-medium text-amber-900 underline decoration-amber-600/70 hover:text-amber-950 cursor-pointer" title="Show or hide stored codes and availability only — actions stay usable below">
                                    Toggle code details
                                </button>
                            </div>
                            <div class="space-y-3">
                                @foreach($duplicateCodePanels as $panel)
                                    <div class="rounded-md border border-amber-200 bg-white p-3 shadow-sm">
                                        <div class="outfit-dup-panel-collapsible space-y-3">
                                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                                <span class="text-xs font-semibold uppercase tracking-wide text-gray-500">Group</span>
                                                <span class="text-sm font-semibold text-gray-900">{{ $panel['label'] }}</span>
                                            </div>
                                            <ul class="text-sm mb-0 border border-gray-100 rounded bg-gray-50/80 px-3 py-2 space-y-2">
                                                @foreach($panel['availability_rows'] ?? [] as $ar)
                                                    <li class="flex flex-wrap items-center justify-between gap-2 font-mono">
                                                        <span class="text-gray-900">{{ $ar['code'] }}</span>
                                                        @if(($ar['active_rentals'] ?? 0) > 0)
                                                            <button
                                                                type="button"
                                                                class="outfit-act outfit-act-rental shrink-0 px-2 py-0.5 text-xs font-semibold rounded-full border border-amber-300 bg-yellow-100 text-yellow-800 hover:underline cursor-pointer"
                                                                data-rental-code="{{ e($ar['code']) }}"
                                                                onclick="openRentalForOutfit({!! \Illuminate\Support\Js::from($ar['code']) !!})"
                                                                title="Open rental detail (same as table)"
                                                            >{{ $ar['active_rentals'] }} active</button>
                                                        @else
                                                            <span class="shrink-0 text-xs font-semibold rounded-full px-2 py-0.5 border border-green-300 bg-green-100 text-green-800">Available</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        </div>
                                        <div class="relative flex flex-wrap gap-2 mt-3 pt-3 border-t border-amber-100">
                                            <button type="button" class="outfit-dup-act-highlight-group inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold bg-amber-600 text-white hover:bg-amber-700 cursor-pointer" data-dup-group-slug="{{ $panel['slug'] }}">
                                                Highlight in table
                                            </button>
                                            <button type="button" class="outfit-dup-act-edit-group inline-flex items-center px-3 py-1.5 rounded-md text-xs font-semibold border border-amber-700 bg-white text-amber-950 hover:bg-amber-100 cursor-pointer disabled:opacity-40 disabled:cursor-not-allowed" data-dup-group-slug="{{ $panel['slug'] }}" {{ $mayMutateItems ? '' : 'disabled' }}>
                                                Edit group
                                            </button>
                                            <button type="button" class="outfit-dup-act-clear-highlights inline-flex items-center px-3 py-1.5 rounded-md text-xs font-medium border border-gray-300 bg-white text-gray-800 hover:bg-gray-50 cursor-pointer">
                                                Clear highlight
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    @if(isset($skippedNumberPanels) && count($skippedNumberPanels) > 0)
                        <div id="outfitSkippedNumbersPanel" class="mb-5 rounded-lg border border-sky-300 bg-sky-50/90 p-4 shadow-sm">
                            <div class="mb-2">
                                <h4 class="text-sm font-semibold text-sky-950">Skipped numbers in sequences</h4>
                                <p class="text-xs text-sky-900/90 mt-0.5 max-w-3xl leading-relaxed">
                                    One row per letter prefix. All spellings share the same numeric slot (<span class="font-mono">BR 3</span> = 3, same as <span class="font-mono">BR 03</span>). <span class="font-medium">Missing</span> lists gaps between your smallest and largest slot, and—if you mix digit widths—any extra holes in the padded line (e.g. no <span class="font-mono">BR 03</span> while <span class="font-mono">BR 01</span>, <span class="font-mono">BR 02</span>, <span class="font-mono">BR 04</span> exist) in the same list. Nothing is inferred after your highest number.
                                </p>
                            </div>
                            <ul class="space-y-2">
                                @foreach($skippedNumberPanels as $sk)
                                    <li class="rounded-md border border-sky-200 bg-white px-3 py-2 shadow-sm">
                                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                            <span class="text-sm font-semibold text-gray-900">{{ $sk['prefix_display'] }}</span>
                                            <span class="text-xs font-medium text-gray-500">{{ $sk['span_label'] }}</span>
                                        </div>
                                        <p class="text-sm font-mono text-sky-950 mt-1">Missing: {{ $sk['skipped_text'] }}</p>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    @if(isset($formatOutlierPanels) && count($formatOutlierPanels) > 0)
                        <div id="outfitFormatOutlierPanel" class="mb-5 rounded-lg border border-violet-300 bg-violet-50/90 p-4 shadow-sm">
                            <div class="mb-2">
                                <h4 class="text-sm font-semibold text-violet-950">Different spelling / spacing (same prefix)</h4>
                                <p class="text-xs text-violet-900/90 mt-0.5 max-w-3xl leading-relaxed">
                                    For each letter prefix, most item codes follow one pattern (e.g. <span class="font-mono">KBL 1</span> with a space). Codes below are written differently from that majority pattern (e.g. <span class="font-mono">KBL2</span> with no space).
                                </p>
                            </div>
                            <ul class="space-y-2">
                                @foreach($formatOutlierPanels as $fmt)
                                    <li class="rounded-md border border-violet-200 bg-white px-3 py-2 shadow-sm">
                                        <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                            <span class="text-sm font-semibold text-gray-900">{{ $fmt['prefix_display'] }}</span>
                                            <span class="text-xs text-gray-500">Most codes look like</span>
                                            <span class="text-sm font-mono text-gray-800">{{ $fmt['majority_example_code'] }}</span>
                                        </div>
                                        <p class="text-xs text-gray-600 mt-1">
                                            Different format (not matching that pattern):
                                            <span class="font-mono font-semibold text-violet-950">{{ $fmt['outlier_codes_text'] }}</span>
                                        </p>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endif

                    <div class="flex flex-col gap-3 mb-4">
                        <div class="flex flex-wrap items-center gap-3">
                            <div class="relative flex-1 min-w-[16rem]">
                                <span class="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" aria-hidden="true">
                                    <svg class="h-4 w-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z"/></svg>
                                </span>
                                <input type="text" id="outfitSearch" placeholder="Search code, new code, description…" class="form-input-search h-10 py-0 w-full pl-9 pr-9" autocomplete="off">
                                <button type="button" id="outfitSearchClear"
                                    class="hidden absolute right-1.5 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-gray-500 hover:text-gray-900 hover:bg-gray-100 cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500/25"
                                    aria-label="Clear search" title="Clear search">
                                    <svg class="h-4 w-4 block" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="flex items-center gap-2 shrink-0">
                                <input type="date" id="outfitStart" class="form-input-compact" title="Start date">
                                <input type="date" id="outfitEnd" class="form-input-compact" title="End date">
                                <button type="button" id="clearOutfitDates" onclick="clearOutfitDates()" class="px-3 py-1 text-sm text-gray-600 hover:text-gray-900">Clear dates</button>
                            </div>
                        </div>
                        <div class="flex flex-wrap items-center gap-2">
                            <button
                                type="button"
                                id="bulkEditOpenBtn"
                                class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md border border-indigo-300 bg-indigo-50 text-indigo-900 hover:bg-indigo-100 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                                disabled
                            >
                                Bulk edit selected
                            </button>
                            @if($mayDeleteRecords ?? true)
                            <button
                                type="button"
                                id="bulkDeleteOpenBtn"
                                class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md border border-red-300 bg-red-50 text-red-800 hover:bg-red-100 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                                disabled
                            >
                                Bulk delete selected
                            </button>
                            @endif
                            <span id="bulkSelectionCount" class="text-xs text-gray-500 tabular-nums min-w-[8rem]" aria-live="polite"></span>
                            <a
                                href="{{ route('outfits.qr-export-zip') }}"
                                download
                                class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md border border-gray-300 bg-white text-gray-800 hover:bg-gray-50"
                                title="800×800 PNG per item, filenames from item codes"
                            >
                                Export all QR (ZIP)
                            </a>
                            @if($pricing && $mayMutateItems)
                                <form id="syncRentalOutfitTaxForm" method="POST" action="{{ route('outfits.sync-rental-outfit-tax') }}" class="hidden">
                                    @csrf
                                </form>
                                <button
                                    type="button"
                                    id="syncRentalOutfitTaxBtn"
                                    onclick="submitSyncRentalOutfitTax()"
                                    class="inline-flex items-center px-3 py-1.5 text-sm font-medium rounded-md border border-gray-300 bg-white text-gray-800 hover:bg-gray-50 disabled:opacity-40 disabled:cursor-not-allowed cursor-pointer"
                                    title="Fill rental_outfit tax fields (price incl / excl / tax amount) using the Tax (%) setting"
                                >
                                    Sync rental tax breakdown
                                </button>
                            @endif
                            <div class="flex gap-1 bg-gray-100 rounded-lg p-0.5">
                                <button onclick="filterOutfits('all')" id="filterAll" type="button" class="px-3 py-1.5 text-sm rounded-md cursor-pointer bg-white shadow-sm text-gray-900 font-semibold">All</button>
                                <button onclick="filterOutfits('available')" id="filterAvailable" type="button" class="px-3 py-1.5 text-sm rounded-md cursor-pointer text-gray-600 hover:text-gray-900">Available</button>
                                <button onclick="filterOutfits('rented')" id="filterRented" type="button" class="px-3 py-1.5 text-sm rounded-md cursor-pointer text-gray-600 hover:text-gray-900">Rented</button>
                            </div>
                        </div>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-gray-200 text-sm" id="outfitsTable" data-empty-colspan="{{ $pricing ? 8 : 7 }}">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th class="px-2 py-3 w-10 text-left align-middle">
                                        <input type="checkbox" id="outfitBulkSelectAll" title="Select visible rows" class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer" aria-label="Select all visible items" {{ $mayMutateItems ? '' : 'disabled' }}>
                                    </th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Code</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase hidden sm:table-cell">New Code</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Description</th>
                                    @if($pricing)
                                        <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Price</th>
                                    @endif
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Stock</th>
                                    <th class="px-3 py-3 text-left text-xs font-medium text-gray-500 uppercase">Status</th>
                                    <th class="px-3 py-3 text-right text-xs font-medium text-gray-500 uppercase">Actions</th>
                                </tr>
                            </thead>
                            <tbody class="bg-white divide-y divide-gray-200">
                                @forelse($outfits as $outfit)
                                    @php
                                        $activeCount = (int) ($outfit->active_rentals_count ?? 0);
                                    @endphp
                                    <tr class="hover:bg-gray-50" data-id="{{ $outfit->id }}" data-status="{{ $activeCount > 0 ? 'rented' : 'available' }}" data-dup-slugs="{{ e($outfitDuplicateSlugById[$outfit->id] ?? '') }}">
                                        <td class="px-2 py-3 align-middle">
                                            <input type="checkbox" class="outfit-bulk-check rounded border-gray-300 text-indigo-600 focus:ring-indigo-500 cursor-pointer" value="{{ $outfit->id }}" aria-label="Select {{ $outfit->code }}" {{ $mayMutateItems ? '' : 'disabled' }}>
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap font-medium text-gray-900">{{ $outfit->code }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-500 hidden sm:table-cell">{{ $outfit->new_code ?? '-' }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-500 max-w-[200px] truncate">{{ $outfit->description ?? '-' }}</td>
                                        @if($pricing)
                                            <td class="px-3 py-3 whitespace-nowrap text-gray-900">{{ $outfit->price !== null && $outfit->price !== '' ? 'Rp ' . number_format((float) $outfit->price, 0, ',', '.') : '—' }}</td>
                                        @endif
                                        <td class="px-3 py-3 whitespace-nowrap text-gray-900">{{ $outfit->stock ?? 1 }}</td>
                                        <td class="px-3 py-3 whitespace-nowrap outfit-status">
                                            @if($activeCount > 0)
                                                <button type="button"
                                                    class="outfit-act outfit-act-rental px-2 py-0.5 text-xs font-semibold rounded-full border border-amber-300 bg-yellow-100 text-yellow-800 hover:underline cursor-pointer"
                                                    data-rental-code="{{ e($outfit->code ?? '') }}"
                                                >{{ $activeCount }} active</button>
                                            @else
                                                <span class="px-2 py-0.5 text-xs font-semibold rounded-full border border-green-300 bg-green-100 text-green-800">Available</span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-3 whitespace-nowrap text-right">
                                            <button type="button"
                                                class="outfit-act outfit-act-qr text-gray-700 hover:text-gray-900 mr-2 cursor-pointer"
                                                data-qr-id="{{ $outfit->id }}"
                                                data-qr-code="{{ e($outfit->code ?? '') }}"
                                                data-qr-status-url="{{ e($outfit->status_url ?? '') }}"
                                                data-qr-image-url="{{ e($outfit->qr_image_url ?? '') }}"
                                                data-qr-download-url="{{ e(route('outfits.qr-download', $outfit)) }}"
                                            >QR</button>
                                            @if($mayMutateItems)
                                                <button type="button"
                                                    class="outfit-act outfit-act-edit text-indigo-600 hover:text-indigo-900 mr-2 cursor-pointer"
                                                    data-outfit-id="{{ $outfit->id }}"
                                                    data-outfit-code="{{ e($outfit->code ?? '') }}"
                                                    data-outfit-new-code="{{ e($outfit->new_code ?? '') }}"
                                                    data-outfit-description="{{ e($outfit->description ?? '') }}"
                                                    data-outfit-stock="{{ (int) ($outfit->stock ?? 1) }}"
                                                    data-outfit-qr-url="{{ e($outfit->qr_redirect_url ?? '') }}"
                                                    @if($pricing)
                                                    data-outfit-price="{{ $outfit->price !== null ? e((string) $outfit->price) : '' }}"
                                                    @endif
                                                >Edit</button>
                                            @endif
                                            @if($mayMutateItems && ($mayDeleteRecords ?? true))
                                            <button type="button"
                                                class="outfit-act outfit-act-delete px-2 py-1 text-xs font-semibold rounded border border-red-200 text-red-700 hover:bg-red-50 cursor-pointer"
                                                title="Remove from inventory without leaving the page"
                                                data-delete-id="{{ $outfit->id }}"
                                                data-delete-code="{{ e($outfit->code ?? '') }}"
                                            >Quick delete</button>
                                            @endif
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td colspan="{{ $pricing ? 8 : 7 }}" class="px-4 py-8 text-center text-gray-500">No items yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                    @if(method_exists($outfits, 'links'))
                        <div class="mt-4">
                            {{ $outfits->links() }}
                        </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <div id="bulkEditModal" class="hidden fixed inset-0 bg-gray-900/50 flex items-center justify-center z-[56] p-3 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="bulkEditModalTitle">
        <div class="bg-white rounded-2xl shadow-xl border border-gray-200 max-w-5xl w-full mx-auto p-4 sm:p-6 max-h-[90vh] flex flex-col">
            <div class="flex items-start justify-between gap-3 mb-3 shrink-0">
                <div>
                    <h3 id="bulkEditModalTitle" class="text-lg font-semibold text-gray-900">Bulk edit selected items</h3>
                    <p class="text-sm text-gray-600 mt-1">Edit each selected row below. Primary <span class="font-mono">Code</span> and <span class="font-mono">New code</span> must stay unique in this store.</p>
                </div>
                <button type="button" onclick="closeBulkEditModal()" class="text-gray-400 hover:text-gray-600 shrink-0" aria-label="Close">✕</button>
            </div>
            <p id="bulkEditSelectedSummary" class="text-sm font-medium text-indigo-800 bg-indigo-50 border border-indigo-100 rounded-lg px-3 py-2 mb-4 shrink-0" aria-live="polite"></p>
            <form id="bulkEditForm" method="POST" action="{{ route('outfits.bulk-update') }}" class="flex flex-col flex-1 min-h-0 space-y-4" onsubmit="return prepareBulkEditSubmit(event)">
                @csrf
                <div class="overflow-x-auto overflow-y-auto border border-gray-200 rounded-lg flex-1 min-h-0 max-h-[55vh]">
                    <table class="min-w-full divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 sticky top-0 z-10">
                            <tr>
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap min-w-[7rem]">Code</th>
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">New code</th>
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap min-w-[9rem]">Description</th>
                                @if($pricing)
                                    <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Price</th>
                                @endif
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase whitespace-nowrap">Stock</th>
                                <th class="px-2 py-2 text-left text-xs font-medium text-gray-500 uppercase min-w-[10rem]">QR redirect</th>
                            </tr>
                        </thead>
                        <tbody id="bulkEditRowsBody" class="bg-white divide-y divide-gray-100"></tbody>
                    </table>
                </div>
                @error('items')
                    <p class="text-red-600 text-sm">{{ $message }}</p>
                @enderror
                <div class="flex justify-end gap-2 pt-2 shrink-0">
                    <button type="button" onclick="closeBulkEditModal()" class="px-4 py-2 bg-white border border-gray-300 rounded-lg text-sm font-medium text-gray-700 hover:bg-gray-50 cursor-pointer">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 cursor-pointer">Save all rows</button>
                </div>
            </form>
        </div>
    </div>

    <div id="outfitsValidationAlertModal" class="hidden fixed inset-0 bg-gray-900/60 flex items-center justify-center z-[101] p-4" role="alertdialog" aria-modal="true" aria-labelledby="outfitsValidationAlertModalTitle">
        <div class="bg-white rounded-2xl shadow-xl border border-gray-200 max-w-lg w-full p-6 max-h-[90vh] flex flex-col">
            <h3 id="outfitsValidationAlertModalTitle" class="text-lg font-semibold text-gray-900 mb-3 shrink-0">Validation</h3>
            <div id="outfitsValidationAlertModalBody" class="text-sm text-red-900 bg-red-50 border border-red-200 rounded-lg px-4 py-3 whitespace-pre-wrap break-words overflow-y-auto flex-1 min-h-0"></div>
            <div class="mt-6 flex justify-end shrink-0">
                <button type="button" id="outfitsValidationAlertModalOk" class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 cursor-pointer">OK</button>
            </div>
        </div>
    </div>

    <div id="dupGroupEditModal" class="hidden fixed inset-0 bg-gray-900/50 flex items-center justify-center z-[58] p-3 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="dupGroupEditTitle">
        <div class="bg-white rounded-2xl shadow-xl border border-gray-200 max-w-lg w-full mx-auto p-4 sm:p-6 max-h-[90vh] flex flex-col">
            <div class="flex items-start justify-between gap-3 mb-3 shrink-0">
                <div>
                    <h3 id="dupGroupEditTitle" class="text-lg font-semibold text-gray-900">Duplicate group</h3>
                    <p class="text-sm text-gray-600 mt-1">Pick an item to open the edit form and align codes or remove extras.</p>
                </div>
                <button type="button" onclick="closeDupGroupEditModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600 shrink-0">✕</button>
            </div>
            <ul id="dupGroupEditList" class="space-y-0 overflow-y-auto flex-1 min-h-0 divide-y divide-gray-100 border border-gray-100 rounded-lg"></ul>
        </div>
    </div>

    <div id="editModal" class="hidden fixed inset-0 bg-gray-900/50 flex items-center justify-center z-[62] p-3 sm:p-4">
        <div class="bg-white rounded-2xl shadow-xl border border-gray-200 max-w-xl w-full mx-4 p-4 sm:p-6">
            <div class="flex items-start justify-between gap-4 mb-2">
                <div>
                    <h3 class="text-xl font-semibold text-gray-900">Edit Item</h3>
                    <p class="text-sm text-gray-600">Make changes below and press Update. Use Cancel to dismiss without saving.</p>
                </div>
                <button type="button" onclick="closeModal()" aria-label="Close" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>

            <form id="editForm" method="POST" class="space-y-4" novalidate>
                @csrf @method('PUT')
                <input type="hidden" name="form_source" value="update">
                <div id="editValidationBox" class="hidden text-sm text-red-900 bg-red-50 border border-red-200 rounded-lg px-3 py-2 whitespace-pre-wrap" role="alert"></div>

                <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                    <div>
                        <label class="block text-sm font-medium text-gray-800 mb-1">Code</label>
                        <input type="text" name="code" id="editCode" class="form-input w-full" placeholder="e.g. AC-03" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-800 mb-1">New Code</label>
                        <input type="text" name="new_code" id="editNewCode" class="form-input w-full" placeholder="alternative code">
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-800 mb-1">Description</label>
                    <input type="text" name="description" id="editDesc" class="form-input w-full" placeholder="Short description (optional)">
                </div>

                @if($pricing)
                    <div>
                        <label class="block text-sm font-medium text-gray-800 mb-1">Price</label>
                        <input type="text" name="price" id="editPrice" inputmode="decimal" autocomplete="off" class="form-input w-full" placeholder="e.g. 50.000">
                        <p class="text-xs text-gray-500 mt-1">Leave blank for no price. Thousands: dot (.), decimals: comma (,).</p>
                        <div class="mt-3 grid grid-cols-1 sm:grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Tax (%)</label>
                                <input type="text" id="editTaxPercent" readonly class="form-input w-full bg-gray-50" placeholder="—">
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Before tax</label>
                                <input type="text" id="editPriceBeforeTax" readonly class="form-input w-full bg-gray-50" placeholder="—">
                            </div>
                        </div>
                    </div>
                @endif

                <div>
                    <label class="block text-sm font-medium text-gray-800 mb-1">Stock</label>
                    <input type="number" name="stock" id="editStock" step="1" min="0" class="form-input w-full" placeholder="1">
                    <p class="text-xs text-gray-500 mt-1">Set available quantity for this item.</p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-800 mb-1">QR scan redirect <span class="font-normal text-gray-500">(optional)</span></label>
                    <input type="url" name="qr_redirect_url" id="editQrRedirect" inputmode="url" autocomplete="off" class="form-input w-full" placeholder="https://…">
                    <p class="text-xs text-gray-500 mt-1">Leave empty to show the built-in item status page. If set, scans redirect here — the printed QR image stays the same.</p>
                </div>

                <div class="bg-gray-50 border border-gray-100 rounded-md p-3">
                    <h4 class="text-sm font-medium text-gray-800 mb-2">Quick actions</h4>
                    <div class="flex flex-wrap gap-2">
                        <button type="button" onclick="document.getElementById('editForm').reset();" class="px-3 py-1.5 bg-white border rounded text-sm text-gray-700 hover:bg-gray-50">Reset</button>
                        @if($mayMutateItems && ($mayDeleteRecords ?? true))
                        <button type="button" onclick="deleteEditingOutfit()" class="px-3 py-1.5 bg-red-50 border border-red-200 rounded text-sm font-medium text-red-700 hover:bg-red-100 cursor-pointer">Quick delete item</button>
                        @endif
                    </div>
                </div>

                <div class="flex justify-end gap-3">
                    <button type="button" onclick="closeModal()" class="px-4 py-2 bg-white border rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50">Cancel</button>
                    <button type="submit" class="px-4 py-2 bg-indigo-600 rounded-md text-sm font-medium text-white hover:bg-indigo-700" {{ $mayMutateItems ? '' : 'disabled' }}>Update</button>
                </div>
            </form>
        </div>
    </div>

    <div id="qrModal" class="hidden fixed inset-0 bg-gray-900/50 flex items-center justify-center z-[62] p-3 sm:p-4">
        <div class="bg-white rounded-2xl shadow-xl border border-gray-200 max-w-sm w-full p-4 sm:p-5">
            <div class="flex items-center justify-between">
                <h3 class="text-lg font-semibold text-gray-900">Item QR Code</h3>
                <button type="button" onclick="closeQrModal()" class="text-gray-400 hover:text-gray-600">✕</button>
            </div>
            <p id="qrCodeLabel" class="text-sm text-gray-600 mt-1"></p>
            <img id="qrImage" src="" alt="QR Code" class="w-64 h-64 mx-auto mt-4 border border-gray-200 rounded-xl">
            <a id="qrStatusLink" href="#" target="_blank" class="mt-4 block text-sm text-indigo-600 hover:text-indigo-900 break-all"></a>
            <p class="text-xs text-gray-500 mt-3 leading-relaxed">The QR image encodes a permanent link on this site. To change where scans go, use <strong>Edit → QR scan redirect</strong> — no need to reprint.</p>
            <a id="qrDownloadLink" href="#" class="mt-3 inline-flex w-full items-center justify-center px-4 py-2 bg-indigo-600 text-white rounded-md text-sm font-medium hover:bg-indigo-700 cursor-pointer">Download PNG for print</a>
            <p class="text-xs text-gray-500 mt-1.5 text-center">Saved as <strong id="qrDownloadFilenameHint">your-code.png</strong> (800×800 px, ready to print).</p>
            <div class="mt-4 flex justify-end">
                <button type="button" onclick="closeQrModal()" class="px-4 py-2 bg-white border border-gray-300 rounded-md text-sm font-medium text-gray-700 hover:bg-gray-50 cursor-pointer">Close</button>
            </div>
        </div>
    </div>

    @include('outfits.partials.rental-detail-modal')

    {{-- Duplicate panel map: textarea + hex-escaped JSON avoids HTML/script truncation (e.g. "</script>" in data). --}}
    <textarea id="rentalDupPanelBySlugJson" class="hidden" readonly aria-hidden="true">{!! $duplicatePanelsJsonEncoded ?? '{}' !!}</textarea>

    <script>
        let currentFilter = 'all';
        let editingOutfitId = null;
        var mayDeleteRecords = @json($mayDeleteRecords ?? true);
        var rentalPricingEnabled = @json((bool) $pricing);
        var rentalOutfitsIndexUrl = @json(route('outfits.index'));
        var rentalBulkNewCodeUrl = @json(route('outfits.bulk-new-code'));
        var rentalOutfitsBulkDeleteUrl = @json(route('outfits.bulk-delete'));
        var rentalOutfitsAvailableUrl = @json(route('outfits.available'));
        var rentalOutfitsUniqueCheckUrl = @json(route('outfits.unique-check'));
        var rentalRentalsSearchUrl = @json(route('rentals.search'));
        var rentalRentalsIndexUrl = @json(route('rentals.index'));
        var rentalRentalDetailFragmentUrl = @json(route('rentals.detail-fragment'));
        var rentalPricingTaxPercent = @json((float) $pricingTaxPercent);
        var rentalPricingPricesIncludeTax = @json((bool) $pricingPricesIncludeTax);
        /** Duplicate-group slug to restore when closing rental detail via "Back to group". */
        var rentalDetailDupGroupReturnSlug = null;
        function submitSyncRentalOutfitTax() {
            if (!rentalPricingEnabled) return;
            var form = document.getElementById('syncRentalOutfitTaxForm');
            if (!form) return;
            var pct = Number(rentalPricingTaxPercent) || 0;
            var ok = window.confirm('This will fill tax fields for ALL rental items (rental_outfit) using ' + pct + '%. It assumes outfit prices already include tax (tax is extracted). Product prices will not be changed. Continue?');
            if (!ok) return;
            form.submit();
        }
        /** Parse duplicate-group payload from the hidden textarea (fresh read so Edit group always matches server output). */
        function getDupPanelMap() {
            var el = document.getElementById('rentalDupPanelBySlugJson');
            if (!el) {
                return {};
            }
            try {
                var raw = JSON.parse((el.value || el.textContent || '').trim() || '{}');
                if (raw == null || Array.isArray(raw)) {
                    return {};
                }
                return typeof raw === 'object' ? raw : {};
            } catch (e) {
                console.warn('getDupPanelMap: invalid JSON', e);
                return {};
            }
        }

        var rentalDupPanelBySlug = getDupPanelMap();

        @if($pricing)
        var outfitPriceFormatter = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
        function parseOutfitPriceDisplay(str) {
            if (str == null || String(str).trim() === '') return NaN;
            var s = String(str).trim().replace(/\./g, '').replace(',', '.');
            var n = parseFloat(s);
            return Number.isFinite(n) ? n : NaN;
        }
        function formatOutfitPriceDisplay(num) {
            if (!Number.isFinite(num)) return '';
            return outfitPriceFormatter.format(num);
        }
        /** Normalize server/attribute/JSON price to a number (DB decimals, or Indonesian-style with dots/commas). */
        function outfitCoercePriceNumericForEdit(raw) {
            if (raw === null || raw === undefined) return NaN;
            if (typeof raw === 'number') {
                return Number.isFinite(raw) ? raw : NaN;
            }
            var s = String(raw).trim();
            if (s === '') return NaN;
            if (s.indexOf(',') !== -1) {
                return parseOutfitPriceDisplay(s);
            }
            var dotCount = (s.match(/\./g) || []).length;
            if (dotCount > 1) {
                return parseOutfitPriceDisplay(s);
            }
            var n = Number(s);
            if (Number.isFinite(n)) return n;
            return parseOutfitPriceDisplay(s);
        }
        function outfitPriceDigitCountBefore(val, caret) {
            var n = 0;
            var lim = Math.min(caret | 0, val.length);
            for (var i = 0; i < lim; i++) {
                if (/\d/.test(val.charAt(i))) n++;
            }
            return n;
        }
        function outfitFormatIntGrouping(digits) {
            if (!digits) return '';
            var out = '';
            var c = 0;
            for (var i = digits.length - 1; i >= 0; i--) {
                if (c > 0 && c % 3 === 0) out = '.' + out;
                out = digits.charAt(i) + out;
                c++;
            }
            return out;
        }
        /**
         * Places caret after intDigitsBefore integer digits (dots skipped), then fracDigitsBefore fractional digits.
         * If caretRightAfterComma, caret sits immediately after ',' when the fractional part is still empty.
         */
        function outfitPriceCaretPos(newVal, intDigitsBefore, fracDigitsBefore, caretRightAfterComma) {
            var i = 0;
            var intSeen = 0;
            for (; i < newVal.length; i++) {
                var ch = newVal.charAt(i);
                if (ch === ',') break;
                if (/\d/.test(ch)) {
                    intSeen++;
                    if (intDigitsBefore > 0 && intSeen >= intDigitsBefore) {
                        i++;
                        break;
                    }
                    if (intDigitsBefore === 0) {
                        break;
                    }
                }
            }
            if (fracDigitsBefore <= 0 && !caretRightAfterComma) {
                return Math.min(i, newVal.length);
            }
            while (i < newVal.length && newVal.charAt(i) !== ',') i++;
            if (caretRightAfterComma && fracDigitsBefore <= 0) {
                return Math.min(i + 1, newVal.length);
            }
            if (i < newVal.length && newVal.charAt(i) === ',') i++;
            var fracSeen = 0;
            for (; i < newVal.length; i++) {
                if (/\d/.test(newVal.charAt(i))) {
                    fracSeen++;
                    if (fracSeen >= fracDigitsBefore) return i + 1;
                }
            }
            return newVal.length;
        }
        function outfitApplyLivePriceFormatting(el) {
            var raw = el.value;
            var caret = el.selectionStart != null ? el.selectionStart : raw.length;
            var commaIdx = raw.indexOf(',');
            var intEnd = commaIdx === -1 ? raw.length : commaIdx;
            var intDigitsBefore = outfitPriceDigitCountBefore(raw.slice(0, intEnd), Math.min(caret, intEnd));
            var fracDigitsBefore = 0;
            var caretRightAfterComma = false;
            if (commaIdx !== -1 && caret > commaIdx) {
                fracDigitsBefore = outfitPriceDigitCountBefore(raw.slice(commaIdx + 1), caret - commaIdx - 1);
            }
            if (commaIdx !== -1 && caret === commaIdx + 1) {
                caretRightAfterComma = true;
            }

            var intRaw = commaIdx === -1 ? raw : raw.slice(0, commaIdx);
            var fracRaw = commaIdx === -1 ? null : raw.slice(commaIdx + 1);
            intRaw = intRaw.replace(/\./g, '').replace(/\D/g, '');
            if (fracRaw !== null) {
                fracRaw = fracRaw.replace(/\D/g, '').slice(0, 2);
            }
            var hasComma = commaIdx !== -1;
            var intFmt = outfitFormatIntGrouping(intRaw);
            if (hasComma && intRaw === '') {
                intFmt = '0';
            }
            var newVal = hasComma ? intFmt + ',' + (fracRaw !== null ? fracRaw : '') : intFmt;

            var newCaret = outfitPriceCaretPos(newVal, intDigitsBefore, fracDigitsBefore, caretRightAfterComma);
            el.value = newVal;
            el.setSelectionRange(newCaret, newCaret);
        }
        function bindOutfitPriceThousandsInput(el) {
            if (!el) return;
            el.addEventListener('input', function() {
                outfitApplyLivePriceFormatting(el);
            });
            el.addEventListener('blur', function() {
                var v = el.value.trim();
                if (v === '') return;
                var n = parseOutfitPriceDisplay(v);
                if (!Number.isFinite(n)) return;
                var formatted = formatOutfitPriceDisplay(n);
                el.value = formatted;
            });
        }
        @endif

        function getOutfitsCsrfToken() {
            var m = document.querySelector('meta[name="csrf-token"]');
            return m ? m.getAttribute('content') : '';
        }

        /** Uses named route so the path matches .htaccess / subfolder / APP_URL (not a naive `/outfits/` prefix). */
        function outfitsDestroyUrl(id) {
            var idNum = parseInt(id, 10);
            if (!Number.isFinite(idNum) || idNum <= 0) {
                return @json(route('outfits.index'));
            }
            var tpl = @json(route('outfits.destroy', ['outfit' => 2147483647]));
            return tpl.replace('2147483647', String(idNum));
        }

        function outfitsUpdateUrl(id) {
            var idNum = parseInt(id, 10);
            if (!Number.isFinite(idNum) || idNum <= 0) {
                return @json(route('outfits.index'));
            }
            var tpl = @json(route('outfits.update', ['outfit' => 2147483647]));
            return tpl.replace('2147483647', String(idNum));
        }

        function outfitHtmlAttrEncode(text) {
            return String(text)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;')
                .replace(/</g, '&lt;');
        }

        function quickDeleteOutfitExecute(id, onSuccess) {
            function finish(res) {
                if (res.status === 419) {
                    showOutfitsAjaxFlash('Session expired — refresh the page and try again.', 'error');
                    return;
                }
                if (res.ok && res.d && res.d.ok) {
                    if (typeof onSuccess === 'function') {
                        onSuccess();
                    }
                    window.location.reload();
                    return;
                } else {
                    var msg = (res.d && res.d.message) ? res.d.message : 'Could not delete item.';
                    showOutfitsAjaxFlash(msg, 'error');
                }
            }

            function parseResponse(r, text) {
                var d = {};
                if (text) {
                    try {
                        d = JSON.parse(text);
                    } catch (e) {
                        d = { message: text.length > 280 ? 'Could not delete item (server returned non-JSON).' : text };
                    }
                }
                return { ok: r.ok, status: r.status, d: d };
            }

            var headers = {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': getOutfitsCsrfToken(),
            };

            fetch(outfitsDestroyUrl(id), {
                method: 'DELETE',
                headers: headers,
                credentials: 'same-origin',
            })
                .then(function(r) {
                    return r.text().then(function(text) {
                        if (r.status === 405) {
                            return fetch(outfitsDestroyUrl(id), {
                                method: 'POST',
                                headers: Object.assign({}, headers, {
                                    'Content-Type': 'application/x-www-form-urlencoded',
                                }),
                                credentials: 'same-origin',
                                body: new URLSearchParams({
                                    _token: getOutfitsCsrfToken(),
                                    _method: 'DELETE',
                                }),
                            }).then(function(r2) {
                                return r2.text().then(function(t2) {
                                    return parseResponse(r2, t2);
                                });
                            });
                        }
                        return parseResponse(r, text);
                    });
                })
                .then(function(res) {
                    finish(res);
                })
                .catch(function() {
                    showOutfitsAjaxFlash('Request failed. Please try again.', 'error');
                });
        }

        /** Laravel validation JSON: { message, errors: { field: ["msg"] } } — used after AJAX edit save. */
        function showEditValidationPopup(payload) {
            var lines = [];
            if (payload && payload.errors && typeof payload.errors === 'object') {
                Object.keys(payload.errors).forEach(function(key) {
                    var arr = payload.errors[key];
                    if (Array.isArray(arr)) {
                        arr.forEach(function(msg) {
                            if (msg) lines.push(msg);
                        });
                    }
                });
            }
            var text = lines.length ? lines.join('\n') : (payload && payload.message ? String(payload.message) : 'Could not save changes.');
            showOutfitsAjaxFlash(text, 'error');
        }

        function clearEditValidationUi() {
            var box = document.getElementById('editValidationBox');
            if (box) {
                box.textContent = '';
                box.classList.add('hidden');
            }
            ['editCode', 'editNewCode', 'editDesc', 'editPrice', 'editStock', 'editQrRedirect'].forEach(function(id) {
                var el = document.getElementById(id);
                if (!el) return;
                el.classList.remove('border-red-500', 'ring-2', 'ring-red-300', 'bg-red-50');
                el.removeAttribute('aria-invalid');
            });
        }

        function showEditValidationInModal(payload) {
            clearEditValidationUi();
            var lines = [];
            var fieldMap = {
                code: 'editCode',
                new_code: 'editNewCode',
                description: 'editDesc',
                price: 'editPrice',
                stock: 'editStock',
                qr_redirect_url: 'editQrRedirect',
            };
            if (payload && payload.errors && typeof payload.errors === 'object') {
                Object.keys(payload.errors).forEach(function(key) {
                    var arr = payload.errors[key];
                    var inputId = fieldMap[key];
                    if (inputId) {
                        var inputEl = document.getElementById(inputId);
                        if (inputEl) {
                            inputEl.classList.add('border-red-500', 'ring-2', 'ring-red-300', 'bg-red-50');
                            inputEl.setAttribute('aria-invalid', 'true');
                        }
                    }
                    if (Array.isArray(arr)) {
                        arr.forEach(function(msg) {
                            if (msg) lines.push(String(msg));
                        });
                    }
                });
            }
            var text = lines.length ? lines.join('\n') : (payload && payload.message ? String(payload.message) : 'Could not save changes.');
            var box = document.getElementById('editValidationBox');
            if (box) {
                box.textContent = text;
                box.classList.remove('hidden');
                box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
            showOutfitsAjaxFlash('Please fix the highlighted fields, then save again.', 'error', { skipPopup: true });
        }

        function buildBulkNewCodePairsString() {
            var oEl = document.getElementById('bulkNewCodeOldCol');
            var nEl = document.getElementById('bulkNewCodeNewCol');
            var oldLines = (oEl && oEl.value) ? oEl.value.split(/\r?\n/) : [];
            var newLines = (nEl && nEl.value) ? nEl.value.split(/\r?\n/) : [];
            var max = Math.max(oldLines.length, newLines.length);
            var out = [];
            for (var i = 0; i < max; i++) {
                var o = (oldLines[i] != null ? String(oldLines[i]) : '').trim();
                var n = (newLines[i] != null ? String(newLines[i]) : '').trim();
                if (o === '' && n === '') {
                    continue;
                }
                out.push(o + '\t' + n);
            }
            return out.join('\n');
        }

        function showOutfitsValidationAlertModal(title, bodyText) {
            var m = document.getElementById('outfitsValidationAlertModal');
            var t = document.getElementById('outfitsValidationAlertModalTitle');
            var b = document.getElementById('outfitsValidationAlertModalBody');
            if (!m || !t || !b) {
                if (window.rentalAlert) {
                    window.rentalAlert(bodyText || title || 'Validation error', { icon: 'error', title: 'Validation' });
                }
                return;
            }
            t.textContent = title || 'Validation';
            b.textContent = bodyText != null ? String(bodyText) : '';
            m.classList.remove('hidden');
            document.body.style.overflow = 'hidden';
            var ok = document.getElementById('outfitsValidationAlertModalOk');
            if (ok) ok.focus();
        }

        function closeOutfitsValidationAlertModal() {
            var m = document.getElementById('outfitsValidationAlertModal');
            if (m) m.classList.add('hidden');
            var bulkOpen = document.getElementById('bulkEditModal');
            var editOpen = document.getElementById('editModal');
            var qrOpen = document.getElementById('qrModal');
            if (bulkOpen && !bulkOpen.classList.contains('hidden')) {
                document.body.style.overflow = 'hidden';
            } else if (editOpen && !editOpen.classList.contains('hidden')) {
                document.body.style.overflow = 'hidden';
            } else if (qrOpen && !qrOpen.classList.contains('hidden')) {
                document.body.style.overflow = 'hidden';
            } else {
                document.body.style.overflow = '';
            }
        }

        /**
         * Errors always use a popup so staff notice; banner duplicates the text for reference.
         * opts.skipPopup: only update banner (rare).
         */
        function showOutfitsAjaxFlash(message, type, opts) {
            opts = opts || {};
            var el = document.getElementById('outfitsAjaxFlash');
            if (!el) return;
            var msg = message != null ? String(message) : '';
            el.textContent = msg;
            el.style.whiteSpace = (type === 'error' && msg.indexOf('\n') !== -1) ? 'pre-wrap' : 'normal';
            el.className = 'mb-4 px-4 py-3 rounded relative text-sm ' + (type === 'error'
                ? 'bg-red-100 border border-red-400 text-red-700'
                : 'bg-green-100 border border-green-400 text-green-700');
            el.classList.remove('hidden');
            el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            if (type === 'error' && msg && !opts.skipPopup) {
                if (window.rentalAlert) {
                    window.rentalAlert(msg, { icon: 'error', title: 'Error' });
                }
            }
            var hideMs = type === 'error' ? 20000 : 4500;
            setTimeout(function() { el.classList.add('hidden'); }, hideMs);
        }

        function quickDeleteOutfit(id, code, onSuccess) {
            if (!mayDeleteRecords) {
                showOutfitsAjaxFlash('Deleting items is disabled for your account. Ask management.', 'error');
                return;
            }
            var label = code || ('#' + id);
            window.rentalConfirm('Delete item ' + label + '? This cannot be undone.', {
                title: 'Delete item?',
                confirmButtonText: 'Delete',
                cancelButtonText: 'Cancel',
            }).then(function(ok) {
                if (!ok) {
                    return;
                }
                quickDeleteOutfitExecute(id, onSuccess);
            });
        }

        function deleteEditingOutfit() {
            if (!editingOutfitId) return;
            var code = document.getElementById('editCode').value;
            quickDeleteOutfit(editingOutfitId, code, function() {
                closeModal();
            });
        }

        function toggleSection(id) { document.getElementById(id).classList.toggle('hidden'); }

        function filterOutfits(filter) {
            currentFilter = filter;
            document.querySelectorAll('#filterAll, #filterAvailable, #filterRented').forEach(b => {
                b.classList.remove('bg-white', 'shadow-sm', 'text-gray-900', 'font-semibold');
                b.classList.add('text-gray-600');
            });
            const btn = document.getElementById('filter' + filter.charAt(0).toUpperCase() + filter.slice(1));
            if (btn) { btn.classList.remove('text-gray-600'); btn.classList.add('bg-white', 'shadow-sm', 'text-gray-900', 'font-semibold'); }
            applyFilters();
        }

        function applyFilters() {
            const q = document.getElementById('outfitSearch').value.toLowerCase();
            document.querySelectorAll('#outfitsTable tbody tr').forEach(tr => {
                if (!tr.dataset.status) return;
                const matchSearch = tr.textContent.toLowerCase().includes(q);
                const matchFilter = currentFilter === 'all' || tr.dataset.status === currentFilter;
                tr.style.display = matchSearch && matchFilter ? '' : 'none';
            });
            updateBulkSelectionUi();
        }

        function bulkEditCheckedCount() {
            return document.querySelectorAll('#outfitsTable tbody .outfit-bulk-check:checked').length;
        }

        function updateBulkSelectionUi() {
            var nChecked = bulkEditCheckedCount();
            var btn = document.getElementById('bulkEditOpenBtn');
            var delBtn = document.getElementById('bulkDeleteOpenBtn');
            var lbl = document.getElementById('bulkSelectionCount');
            if (lbl) {
                lbl.textContent = nChecked ? (nChecked + ' selected') : '';
            }
            if (btn) {
                btn.disabled = nChecked === 0;
            }
            if (delBtn) {
                delBtn.disabled = nChecked === 0;
            }
            var selAll = document.getElementById('outfitBulkSelectAll');
            var visChecks = Array.from(document.querySelectorAll('#outfitsTable tbody tr[data-id]')).filter(function(tr) {
                return tr.style.display !== 'none';
            }).map(function(tr) {
                return tr.querySelector('.outfit-bulk-check');
            }).filter(Boolean);
            if (selAll && visChecks.length > 0) {
                var allOn = visChecks.every(function(c) { return c.checked; });
                var someOn = visChecks.some(function(c) { return c.checked; });
                selAll.checked = allOn;
                selAll.indeterminate = !allOn && someOn;
            }
        }

        function bulkEditStockFromDataAttr(raw) {
            if (raw === null || raw === undefined || raw === '') {
                return 1;
            }
            var n = parseInt(String(raw), 10);
            return Number.isFinite(n) ? n : 1;
        }

        /** Checkbox selection count when bulk modal was opened (guards against changing selection while dialog is open). */
        var bulkEditOpenSelectionCount = 0;

        function normalizeBulkEditCode(val) {
            return String(val == null ? '' : val).trim();
        }

        function bulkEditDuplicateValues(arr) {
            var counts = {};
            arr.forEach(function(v) {
                if (v === '') {
                    return;
                }
                counts[v] = (counts[v] || 0) + 1;
            });
            var out = [];
            Object.keys(counts).forEach(function(k) {
                if (counts[k] > 1) {
                    out.push(k);
                }
            });
            return out.sort();
        }

        /** Short message under an input when this row’s value clashes with others (row numbers are 1-based within the bulk form). */
        function bulkEditDuplicateHintForRow(rowIndex0, values, labelShort) {
            var v = values[rowIndex0];
            if (v === '') {
                return '';
            }
            var sameRows = [];
            for (var i = 0; i < values.length; i++) {
                if (values[i] === v) {
                    sameRows.push(i + 1);
                }
            }
            if (sameRows.length < 2) {
                return '';
            }
            var others = sameRows.filter(function(r) {
                return r !== rowIndex0 + 1;
            });
            return labelShort + ' duplicated in this batch — same value in row(s) ' + others.join(', ') + '.';
        }

        function collectBulkEditCodeArrays() {
            var tbody = document.getElementById('bulkEditRowsBody');
            if (!tbody) {
                return { primaryVals: [], newVals: [] };
            }
            var rows = tbody.querySelectorAll('tr');
            var primaryVals = [];
            var newVals = [];
            rows.forEach(function(tr) {
                var cEl = tr.querySelector('input[name$="[code]"]');
                var nEl = tr.querySelector('input[name$="[new_code]"]');
                primaryVals.push(cEl ? normalizeBulkEditCode(cEl.value) : '');
                newVals.push(nEl ? normalizeBulkEditCode(nEl.value) : '');
            });
            return { primaryVals: primaryVals, newVals: newVals };
        }

        /** Multi-line message for the validation modal: which code repeats and which form rows (1-based). */
        function getBulkEditDuplicatePopupMessage() {
            var bags = collectBulkEditCodeArrays();
            var dupPrimary = bulkEditDuplicateValues(bags.primaryVals);
            var dupNew = bulkEditDuplicateValues(bags.newVals);
            if (!dupPrimary.length && !dupNew.length) {
                return '';
            }
            var lines = [];
            dupPrimary.forEach(function(code) {
                var rowNums = [];
                for (var i = 0; i < bags.primaryVals.length; i++) {
                    if (bags.primaryVals[i] === code) {
                        rowNums.push(i + 1);
                    }
                }
                lines.push('Primary code "' + code + '" is used in rows: ' + rowNums.join(', ') + '.');
            });
            dupNew.forEach(function(code) {
                var rowNums = [];
                for (var i = 0; i < bags.newVals.length; i++) {
                    if (bags.newVals[i] === code) {
                        rowNums.push(i + 1);
                    }
                }
                lines.push('New code "' + code + '" is used in rows: ' + rowNums.join(', ') + '.');
            });
            lines.push('');
            lines.push('Rows are numbered from top to bottom in this bulk form. Change or clear duplicates, then save again.');
            return lines.join('\n');
        }

        /** Highlights duplicate primary / new codes within the bulk table (call on input + after building rows). */
        function refreshBulkEditDuplicateUi() {
            var tbody = document.getElementById('bulkEditRowsBody');
            if (!tbody) {
                return;
            }

            var bags = collectBulkEditCodeArrays();
            var dupPrimary = bulkEditDuplicateValues(bags.primaryVals);
            var dupNew = bulkEditDuplicateValues(bags.newVals);
            var dupPSet = {};
            dupPrimary.forEach(function(k) {
                dupPSet[k] = true;
            });
            var dupNSet = {};
            dupNew.forEach(function(k) {
                dupNSet[k] = true;
            });

            var rows = tbody.querySelectorAll('tr');
            rows.forEach(function(tr, rowIdx) {
                var cEl = tr.querySelector('input[name$="[code]"]');
                var nEl = tr.querySelector('input[name$="[new_code]"]');
                var hintC = tr.querySelector('.bulk-edit-dup-hint--code');
                var hintN = tr.querySelector('.bulk-edit-dup-hint--new');
                [cEl, nEl].forEach(function(el) {
                    if (!el) {
                        return;
                    }
                    el.classList.remove('border-red-500', 'ring-2', 'ring-red-400', 'bg-red-50');
                    el.removeAttribute('aria-invalid');
                });
                if (hintC) {
                    hintC.textContent = '';
                    hintC.classList.add('hidden');
                }
                if (hintN) {
                    hintN.textContent = '';
                    hintN.classList.add('hidden');
                }
                if (cEl) {
                    var cv = normalizeBulkEditCode(cEl.value);
                    if (cv !== '' && dupPSet[cv]) {
                        cEl.classList.add('border-red-500', 'ring-2', 'ring-red-400', 'bg-red-50');
                        cEl.setAttribute('aria-invalid', 'true');
                        if (hintC) {
                            hintC.textContent = bulkEditDuplicateHintForRow(rowIdx, bags.primaryVals, 'Primary code');
                            hintC.classList.remove('hidden');
                        }
                    }
                }
                if (nEl) {
                    var nv = normalizeBulkEditCode(nEl.value);
                    if (nv !== '' && dupNSet[nv]) {
                        nEl.classList.add('border-red-500', 'ring-2', 'ring-red-400', 'bg-red-50');
                        nEl.setAttribute('aria-invalid', 'true');
                        if (hintN) {
                            hintN.textContent = bulkEditDuplicateHintForRow(rowIdx, bags.newVals, 'New code');
                            hintN.classList.remove('hidden');
                        }
                    }
                }
            });
        }

        function buildBulkEditRows() {
            var tbody = document.getElementById('bulkEditRowsBody');
            if (!tbody) return;
            tbody.innerHTML = '';
            var checked = document.querySelectorAll('#outfitsTable tbody .outfit-bulk-check:checked');
            var idx = 0;
            checked.forEach(function(cb) {
                var tr = cb.closest('tr[data-id]');
                var editBtn = tr ? tr.querySelector('.outfit-act-edit') : null;
                if (!editBtn) return;
                var id = String(editBtn.getAttribute('data-outfit-id') || cb.value || '').trim();
                var code = editBtn.getAttribute('data-outfit-code') || '';
                var newCode = editBtn.getAttribute('data-outfit-new-code') || '';
                var desc = editBtn.getAttribute('data-outfit-description') || '';
                var rawStock = editBtn.getAttribute('data-outfit-stock');
                var stock = rawStock !== null && rawStock !== '' ? rawStock : '1';
                var qrUrl = editBtn.getAttribute('data-outfit-qr-url') || '';

                var row = document.createElement('tr');
                row.className = 'align-top';

                var tdCode = document.createElement('td');
                tdCode.className = 'px-2 py-2 align-top';
                var hid = document.createElement('input');
                hid.type = 'hidden';
                hid.name = 'items[' + idx + '][id]';
                hid.value = id;
                tdCode.appendChild(hid);
                var inpCode = document.createElement('input');
                inpCode.type = 'text';
                inpCode.name = 'items[' + idx + '][code]';
                inpCode.value = code;
                inpCode.className = 'form-input w-full min-w-[7rem] text-sm font-mono';
                inpCode.autocomplete = 'off';
                inpCode.setAttribute('aria-label', 'Primary code');
                tdCode.appendChild(inpCode);
                var hintCode = document.createElement('p');
                hintCode.className = 'bulk-edit-dup-hint bulk-edit-dup-hint--code mt-1 text-xs text-red-600 leading-snug hidden';
                hintCode.setAttribute('role', 'status');
                tdCode.appendChild(hintCode);
                var takenCode = document.createElement('p');
                takenCode.className = 'bulk-edit-taken-hint bulk-edit-taken-hint--code mt-0.5 text-xs text-red-700 leading-snug hidden';
                takenCode.setAttribute('role', 'alert');
                tdCode.appendChild(takenCode);
                row.appendChild(tdCode);

                var tdNew = document.createElement('td');
                tdNew.className = 'px-2 py-2 align-top';
                var inpNew = document.createElement('input');
                inpNew.type = 'text';
                inpNew.name = 'items[' + idx + '][new_code]';
                inpNew.value = newCode;
                inpNew.className = 'form-input w-full min-w-[6rem] text-sm';
                inpNew.autocomplete = 'off';
                inpNew.setAttribute('aria-label', 'New code');
                tdNew.appendChild(inpNew);
                var hintNew = document.createElement('p');
                hintNew.className = 'bulk-edit-dup-hint bulk-edit-dup-hint--new mt-1 text-xs text-red-600 leading-snug hidden';
                hintNew.setAttribute('role', 'status');
                tdNew.appendChild(hintNew);
                var takenNew = document.createElement('p');
                takenNew.className = 'bulk-edit-taken-hint bulk-edit-taken-hint--new mt-0.5 text-xs text-red-700 leading-snug hidden';
                takenNew.setAttribute('role', 'alert');
                tdNew.appendChild(takenNew);
                row.appendChild(tdNew);

                var tdDesc = document.createElement('td');
                tdDesc.className = 'px-2 py-2';
                var inpDesc = document.createElement('input');
                inpDesc.type = 'text';
                inpDesc.name = 'items[' + idx + '][description]';
                inpDesc.value = desc;
                inpDesc.className = 'form-input w-full min-w-[8rem] text-sm';
                inpDesc.autocomplete = 'off';
                tdDesc.appendChild(inpDesc);
                row.appendChild(tdDesc);

                if (rentalPricingEnabled) {
                    var tdPrice = document.createElement('td');
                    tdPrice.className = 'px-2 py-2';
                    var inpPrice = document.createElement('input');
                    inpPrice.type = 'text';
                    inpPrice.name = 'items[' + idx + '][price]';
                    inpPrice.inputMode = 'decimal';
                    inpPrice.autocomplete = 'off';
                    inpPrice.className = 'form-input w-full min-w-[6rem] text-sm';
                    var pr = editBtn.getAttribute('data-outfit-price');
                    if (pr != null && String(pr).trim() !== '' && typeof outfitCoercePriceNumericForEdit === 'function' && typeof formatOutfitPriceDisplay === 'function') {
                        var pn = outfitCoercePriceNumericForEdit(pr);
                        inpPrice.value = Number.isFinite(pn) ? formatOutfitPriceDisplay(pn) : '';
                    }
                    if (typeof bindOutfitPriceThousandsInput === 'function') {
                        bindOutfitPriceThousandsInput(inpPrice);
                    }
                    tdPrice.appendChild(inpPrice);
                    row.appendChild(tdPrice);
                }

                var tdStock = document.createElement('td');
                tdStock.className = 'px-2 py-2';
                var inpStock = document.createElement('input');
                inpStock.type = 'number';
                inpStock.name = 'items[' + idx + '][stock]';
                inpStock.min = '0';
                inpStock.step = '1';
                inpStock.value = String(bulkEditStockFromDataAttr(stock));
                inpStock.className = 'form-input w-20 text-sm';
                tdStock.appendChild(inpStock);
                row.appendChild(tdStock);

                var tdQr = document.createElement('td');
                tdQr.className = 'px-2 py-2';
                var inpQr = document.createElement('input');
                inpQr.type = 'url';
                inpQr.name = 'items[' + idx + '][qr_redirect_url]';
                inpQr.value = qrUrl;
                inpQr.className = 'form-input w-full min-w-[10rem] text-sm';
                inpQr.placeholder = 'https://…';
                tdQr.appendChild(inpQr);
                row.appendChild(tdQr);

                tbody.appendChild(row);
                idx++;
            });
            var nChecked = checked.length;
            if (idx > 0 && idx < nChecked) {
                window.rentalAlert('Warning: ' + (nChecked - idx) + ' selected row(s) could not be loaded into this form (missing edit row data). Close and refresh if totals look wrong.', { icon: 'warning', title: 'Partial load' });
            }
            refreshBulkEditDuplicateUi();
        }

        var bulkEditUniqueReqId = 0;
        var bulkEditUniqueCheckAbort = null;

        function scheduleBulkEditStoreUniqueCheck(inputEl) {
            if (!inputEl || !rentalOutfitsUniqueCheckUrl) {
                return;
            }
            var nm = inputEl.name || '';
            if (nm.indexOf('[code]') === -1 && nm.indexOf('[new_code]') === -1) {
                return;
            }
            var tr = inputEl.closest('tr');
            var tbody = document.getElementById('bulkEditRowsBody');
            if (!tr || !tbody || !tbody.contains(tr)) {
                return;
            }
            var idInput = tr.querySelector('input[name$="[id]"]');
            var outfitId = idInput ? String(idInput.value || '').trim() : '';
            if (!outfitId) {
                return;
            }
            var field = nm.indexOf('[new_code]') !== -1 ? 'new_code' : 'code';
            var hint = tr.querySelector(field === 'code' ? '.bulk-edit-taken-hint--code' : '.bulk-edit-taken-hint--new');
            clearTimeout(scheduleBulkEditStoreUniqueCheck._timer);
            scheduleBulkEditStoreUniqueCheck._timer = setTimeout(function() {
                if (bulkEditUniqueCheckAbort) {
                    bulkEditUniqueCheckAbort.abort();
                }
                bulkEditUniqueCheckAbort = new AbortController();
                var reqId = ++bulkEditUniqueReqId;
                var params = new URLSearchParams({
                    outfit_id: outfitId,
                    field: field,
                    value: inputEl.value != null ? String(inputEl.value) : ''
                });
                fetch(rentalOutfitsUniqueCheckUrl + '?' + params.toString(), {
                    signal: bulkEditUniqueCheckAbort.signal,
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin'
                })
                    .then(function(r) {
                        return r.json().then(function(data) {
                            return { ok: r.ok, status: r.status, data: data };
                        });
                    })
                    .then(function(res) {
                        if (reqId !== bulkEditUniqueReqId) {
                            return;
                        }
                        if (!hint || !tbody.contains(tr)) {
                            return;
                        }
                        var data = res.data || {};
                        if (res.ok && data.available) {
                            hint.textContent = '';
                            hint.classList.add('hidden');
                            return;
                        }
                        hint.textContent = data.message ? String(data.message) : 'This code is already taken in this store.';
                        hint.classList.remove('hidden');
                    })
                    .catch(function(err) {
                        if (err.name === 'AbortError') {
                            return;
                        }
                    });
            }, 420);
        }

        function prepareBulkEditSubmit(e) {
            var tbody = document.getElementById('bulkEditRowsBody');
            if (!tbody || !tbody.querySelector('tr')) {
                e.preventDefault();
                window.rentalAlert('No rows to save. Close the dialog and select items again.', { icon: 'warning', title: 'Nothing to save' });
                return false;
            }
            refreshBulkEditDuplicateUi();
            var dupMsg = getBulkEditDuplicatePopupMessage();
            if (dupMsg) {
                e.preventDefault();
                showOutfitsValidationAlertModal('Duplicate codes in this batch', dupMsg);
                return false;
            }
            var takenMsgs = [];
            tbody.querySelectorAll('.bulk-edit-taken-hint--code:not(.hidden), .bulk-edit-taken-hint--new:not(.hidden)').forEach(function(el) {
                var t = el.textContent ? el.textContent.trim() : '';
                if (t && takenMsgs.indexOf(t) === -1) {
                    takenMsgs.push(t);
                }
            });
            if (takenMsgs.length) {
                e.preventDefault();
                showOutfitsValidationAlertModal('Code already taken in this store', takenMsgs.join('\n'));
                return false;
            }
            if (bulkEditOpenSelectionCount > 0 && bulkEditCheckedCount() !== bulkEditOpenSelectionCount) {
                e.preventDefault();
                window.rentalAlert('Your selection changed after this dialog opened. Close it and click “Bulk edit selected” again.', { icon: 'warning', title: 'Selection changed' });
                return false;
            }
            return true;
        }

        function closeBulkEditModal() {
            bulkEditOpenSelectionCount = 0;
            bulkEditUniqueReqId++;
            if (bulkEditUniqueCheckAbort) {
                bulkEditUniqueCheckAbort.abort();
            }
            clearTimeout(scheduleBulkEditStoreUniqueCheck._timer);
            var tbody = document.getElementById('bulkEditRowsBody');
            if (tbody) tbody.innerHTML = '';
            var m = document.getElementById('bulkEditModal');
            if (m) m.classList.add('hidden');
            document.body.style.overflow = '';
        }

        function openBulkEditModal() {
            var n = bulkEditCheckedCount();
            if (!n) {
                window.rentalAlert('Select at least one item using the checkboxes.', { icon: 'warning', title: 'No selection' });
                return;
            }
            buildBulkEditRows();
            var tbody = document.getElementById('bulkEditRowsBody');
            if (!tbody || !tbody.querySelector('tr')) {
                window.rentalAlert('Could not load selected rows. Refresh the page and try again.', { icon: 'error', title: 'Load failed' });
                return;
            }
            var sum = document.getElementById('bulkEditSelectedSummary');
            if (sum) {
                sum.textContent = n + ' row(s) — edit fields below, then save.';
            }
            bulkEditOpenSelectionCount = n;
            var m = document.getElementById('bulkEditModal');
            if (m) {
                m.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        }

        function bulkDeleteSelected() {
            var checked = Array.from(document.querySelectorAll('#outfitsTable tbody .outfit-bulk-check:checked'))
                .map(function(cb) { return parseInt(cb.value, 10); })
                .filter(function(id) { return Number.isFinite(id) && id > 0; });
            if (!checked.length) {
                window.rentalAlert('Select at least one item using the checkboxes.', { icon: 'warning', title: 'No selection' });
                return;
            }
            window.rentalConfirm('Delete ' + checked.length + ' selected item(s)? This cannot be undone.', {
                title: 'Bulk delete items?',
                confirmButtonText: 'Delete selected',
                cancelButtonText: 'Cancel',
                icon: 'warning',
            }).then(function(confirmed) {
                if (!confirmed) return;
                var form = document.createElement('form');
                form.method = 'POST';
                form.action = rentalOutfitsBulkDeleteUrl;
                var csrf = document.createElement('input');
                csrf.type = 'hidden';
                csrf.name = '_token';
                csrf.value = getOutfitsCsrfToken();
                form.appendChild(csrf);
                checked.forEach(function(id) {
                    var inp = document.createElement('input');
                    inp.type = 'hidden';
                    inp.name = 'ids[]';
                    inp.value = String(id);
                    form.appendChild(inp);
                });
                document.body.appendChild(form);
                form.submit();
            });
        }

        function toggleOutfitDupPanelCollapse() {
            document.querySelectorAll('.outfit-dup-panel-collapsible').forEach(function(el) {
                el.classList.toggle('hidden');
            });
        }

        function clearDuplicateHighlights() {
            document.querySelectorAll('#outfitsTable tbody tr[data-dup-slugs]').forEach(function(tr) {
                tr.classList.remove('bg-amber-50', 'ring-2', 'ring-inset', 'ring-amber-400');
                tr.style.backgroundColor = '';
                tr.style.boxShadow = '';
            });
        }

        function dupPanelEditStock(row) {
            if (!row || row.stock === undefined || row.stock === null || row.stock === '') {
                return 1;
            }
            var n = parseInt(row.stock, 10);
            return Number.isFinite(n) ? n : 1;
        }

        function highlightDuplicateGroup(slug) {
            slug = String(slug || '').trim();
            if (!slug) return;
            var searchEl = document.getElementById('outfitSearch');
            if (searchEl) {
                searchEl.value = '';
            }
            filterOutfits('all');
            clearDuplicateHighlights();
            var tbody = document.querySelector('#outfitsTable tbody');
            if (!tbody) return;
            var first = null;
            tbody.querySelectorAll('tr[data-dup-slugs]').forEach(function(tr) {
                var raw = (tr.getAttribute('data-dup-slugs') || '').trim();
                if (!raw) return;
                var on = raw.split(/\s+/).indexOf(slug) !== -1;
                if (on) {
                    tr.classList.add('bg-amber-50', 'ring-2', 'ring-inset', 'ring-amber-400');
                    tr.style.backgroundColor = 'rgb(254 243 199)';
                    tr.style.boxShadow = 'inset 0 0 0 2px rgb(251 191 36)';
                    tr.style.display = '';
                    if (!first) first = tr;
                }
            });
            if (first) {
                first.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            }
        }

        function openDupGroupEditModal(slug) {
            slug = String(slug || '').trim();
            var map = getDupPanelMap();
            var data = map[slug];
            if (!data || !Array.isArray(data.edit_rows)) {
                window.rentalAlert('Could not load this duplicate group. Refresh the page and try again.', { icon: 'error', title: 'Load failed' });
                return;
            }
            if (!data.edit_rows.length) {
                window.rentalAlert('No items in this group to edit. Refresh the page if the list looks wrong.', { icon: 'info', title: 'No items' });
                return;
            }
            var titleEl = document.getElementById('dupGroupEditTitle');
            if (titleEl) {
                titleEl.textContent = 'Duplicate group: ' + (data.label || slug);
            }
            var list = document.getElementById('dupGroupEditList');
            if (!list) return;
            list.innerHTML = '';
            var rows = data.edit_rows;
            rows.forEach(function(row) {
                var li = document.createElement('li');
                li.className = 'flex flex-wrap items-center justify-between gap-2 px-3 py-3';
                var left = document.createElement('div');
                left.className = 'min-w-0 flex-1';
                var codeEl = document.createElement('div');
                codeEl.className = 'font-mono text-sm font-semibold text-gray-900 truncate';
                codeEl.textContent = row.code || '';
                left.appendChild(codeEl);
                if (row.new_code) {
                    var sub = document.createElement('div');
                    sub.className = 'text-xs text-gray-500 truncate mt-0.5';
                    sub.textContent = 'New code: ' + row.new_code;
                    left.appendChild(sub);
                }
                if (rentalPricingEnabled && typeof formatOutfitPriceDisplay === 'function' && typeof outfitCoercePriceNumericForEdit === 'function') {
                    var priceLineEl = document.createElement('div');
                    priceLineEl.className = 'text-xs text-gray-600 mt-0.5 tabular-nums';
                    if (Object.prototype.hasOwnProperty.call(row, 'price') && row.price != null && String(row.price).trim() !== '') {
                        var pNum = outfitCoercePriceNumericForEdit(row.price);
                        priceLineEl.textContent = Number.isFinite(pNum) ? ('Rp ' + formatOutfitPriceDisplay(pNum)) : '—';
                    } else {
                        priceLineEl.className += ' text-gray-400';
                        priceLineEl.textContent = '—';
                    }
                    left.appendChild(priceLineEl);
                }
                var activeRentals = parseInt(row.active_rentals, 10);
                if (!Number.isFinite(activeRentals)) {
                    activeRentals = 0;
                }
                var statusCol = document.createElement('div');
                statusCol.className = 'shrink-0 flex flex-col items-end gap-0.5';
                if (activeRentals > 0) {
                    var rentBtn = document.createElement('button');
                    rentBtn.type = 'button';
                    rentBtn.className = 'outfit-act outfit-act-rental px-2 py-0.5 text-xs font-semibold rounded-full border border-amber-300 bg-yellow-100 text-yellow-800 hover:underline cursor-pointer';
                    rentBtn.setAttribute('data-rental-code', row.code || '');
                    rentBtn.title = 'Open rental detail (same as table)';
                    rentBtn.textContent = activeRentals + ' active';
                    rentBtn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        closeDupGroupEditModal();
                        openRentalForOutfit(row.code || '', { dupGroupReturnSlug: slug });
                    });
                    statusCol.appendChild(rentBtn);
                } else {
                    var avail = document.createElement('span');
                    avail.className = 'text-xs font-semibold rounded-full px-2 py-0.5 border border-green-300 bg-green-100 text-green-800';
                    avail.textContent = 'Available';
                    statusCol.appendChild(avail);
                }
                var btn = document.createElement('button');
                btn.type = 'button';
                btn.className = 'shrink-0 inline-flex items-center px-3 py-1.5 rounded-md text-sm font-medium text-indigo-600 hover:text-indigo-900 hover:bg-indigo-50 cursor-pointer';
                btn.textContent = 'Edit';
                btn.addEventListener('click', function() {
                    var outfitId = parseInt(row.id, 10);
                    if (!Number.isFinite(outfitId) || outfitId <= 0) {
                        window.rentalAlert('Could not open edit for this row. Refresh the page and try again.', { icon: 'error', title: 'Open failed' });
                        return;
                    }
                    closeDupGroupEditModal();
                    var priceArg = Object.prototype.hasOwnProperty.call(row, 'price') ? row.price : null;
                    if (rentalPricingEnabled) {
                        var jsonHasPrice = Object.prototype.hasOwnProperty.call(row, 'price')
                            && row.price != null
                            && String(row.price).trim() !== '';
                        if (!jsonHasPrice) {
                            var trFb = document.querySelector('#outfitsTable tbody tr[data-id="' + outfitId + '"]');
                            var edBtn = trFb ? trFb.querySelector('.outfit-act-edit') : null;
                            if (edBtn) {
                                var domPrice = edBtn.getAttribute('data-outfit-price');
                                if (domPrice !== null && String(domPrice).trim() !== '') {
                                    priceArg = domPrice;
                                }
                            }
                        }
                    }
                    editOutfit(
                        outfitId,
                        row.code || '',
                        row.new_code || '',
                        row.description || '',
                        priceArg,
                        dupPanelEditStock(row),
                        row.qr_redirect_url || ''
                    );
                });
                li.appendChild(left);
                li.appendChild(statusCol);
                li.appendChild(btn);
                list.appendChild(li);
            });
            var modal = document.getElementById('dupGroupEditModal');
            if (modal) {
                modal.classList.remove('hidden');
                document.body.style.overflow = 'hidden';
            }
        }

        function closeDupGroupEditModal() {
            var modal = document.getElementById('dupGroupEditModal');
            if (modal) modal.classList.add('hidden');
            document.body.style.overflow = '';
        }

        document.getElementById('dupGroupEditModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeDupGroupEditModal();
        });

        (function bindDupPanelActions() {
            var root = document.getElementById('outfitDuplicatePanel');
            if (!root) {
                return;
            }
            root.addEventListener('click', function(e) {
                var hi = e.target.closest('.outfit-dup-act-highlight-group');
                if (hi) {
                    e.preventDefault();
                    highlightDuplicateGroup(hi.getAttribute('data-dup-group-slug') || '');
                    return;
                }
                var ed = e.target.closest('.outfit-dup-act-edit-group');
                if (ed) {
                    e.preventDefault();
                    openDupGroupEditModal(ed.getAttribute('data-dup-group-slug') || '');
                    return;
                }
                var cl = e.target.closest('.outfit-dup-act-clear-highlights');
                if (cl) {
                    e.preventDefault();
                    clearDuplicateHighlights();
                }
            });
        })();

        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            var vm = document.getElementById('outfitsValidationAlertModal');
            if (vm && !vm.classList.contains('hidden')) {
                closeOutfitsValidationAlertModal();
                e.preventDefault();
                return;
            }
            var bm = document.getElementById('bulkEditModal');
            if (bm && !bm.classList.contains('hidden')) {
                closeBulkEditModal();
                e.preventDefault();
                return;
            }
            var rdm = document.getElementById('rentalDetailModal');
            if (rdm && !rdm.classList.contains('hidden')) {
                closeRentalDetailModal();
                e.preventDefault();
                return;
            }
            var dm = document.getElementById('dupGroupEditModal');
            if (dm && !dm.classList.contains('hidden')) {
                closeDupGroupEditModal();
                e.preventDefault();
                return;
            }
            var em = document.getElementById('editModal');
            if (em && !em.classList.contains('hidden')) {
                closeModal();
                e.preventDefault();
            }
        });

        // Fetch availability for outfits within a date range and update the table rows
        function fetchOutfitAvailability() {
            const start = document.getElementById('outfitStart').value;
            const end = document.getElementById('outfitEnd').value;
            // Require both dates to filter
            if (!start || !end) return;
            fetch(rentalOutfitsAvailableUrl + '?start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end))
                .then(r => r.json())
                .then(data => {
                    const outfits = data.outfits || [];
                    const byId = {};
                    outfits.forEach(s => byId[s.id] = s);
                    document.querySelectorAll('#outfitsTable tbody tr[data-id]').forEach(tr => {
                        const id = tr.dataset.id ? parseInt(tr.dataset.id) : null;
                        if (!id) return;
                        const info = byId[id];
                        if (!info) return;
                        // update status attribute
                        tr.dataset.status = info.available ? 'available' : 'rented';
                        // update status cell by class
                        const statusTd = tr.querySelector('td.outfit-status');
                        if (statusTd) {
                            statusTd.innerHTML = info.status_html || '';
                        }
                    });
                    applyFilters();
                });
        }

        function syncOutfitSearchClearVisible() {
            var inp = document.getElementById('outfitSearch');
            var btn = document.getElementById('outfitSearchClear');
            if (!inp || !btn) return;
            btn.classList.toggle('hidden', inp.value.trim().length < 1);
        }

        function clearOutfitSearchInput() {
            var inp = document.getElementById('outfitSearch');
            if (inp) inp.value = '';
            syncOutfitSearchClearVisible();
            applyFilters();
            if (inp) inp.focus();
        }

        var outfitStartEl = document.getElementById('outfitStart');
        if (outfitStartEl) outfitStartEl.addEventListener('change', fetchOutfitAvailability);
        var outfitEndEl = document.getElementById('outfitEnd');
        if (outfitEndEl) outfitEndEl.addEventListener('change', fetchOutfitAvailability);

        if (window.rentalBindSearchField) {
            window.rentalBindSearchField({
                input: 'outfitSearch',
                clearButton: 'outfitSearchClear',
                onInput: function() {
                    syncOutfitSearchClearVisible();
                    applyFilters();
                },
                onEscape: function() {
                    clearOutfitSearchInput();
                },
                onClear: function() {
                    syncOutfitSearchClearVisible();
                    applyFilters();
                },
            });
        } else {
            var outfitSearchEl = document.getElementById('outfitSearch');
            if (outfitSearchEl) {
                outfitSearchEl.addEventListener('input', function() { syncOutfitSearchClearVisible(); applyFilters(); });
                outfitSearchEl.addEventListener('keydown', function(e) {
                    if (e.key === 'Escape') { e.preventDefault(); clearOutfitSearchInput(); }
                });
            }
            var outfitSearchClearBtn = document.getElementById('outfitSearchClear');
            if (outfitSearchClearBtn) {
                outfitSearchClearBtn.addEventListener('click', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    clearOutfitSearchInput();
                });
            }
            syncOutfitSearchClearVisible();
        }
        // Default to All so every item row is visible (matches the "All Items (n)" heading). Use Available / Rented to narrow down.
        filterOutfits('all');

        (function bindBulkEditUi() {
            var openBtn = document.getElementById('bulkEditOpenBtn');
            if (openBtn) openBtn.addEventListener('click', openBulkEditModal);
            var delBtn = document.getElementById('bulkDeleteOpenBtn');
            if (delBtn) delBtn.addEventListener('click', bulkDeleteSelected);
            var table = document.getElementById('outfitsTable');
            if (table) {
                table.addEventListener('change', function(e) {
                    if (e.target && e.target.classList && e.target.classList.contains('outfit-bulk-check')) {
                        updateBulkSelectionUi();
                    }
                });
            }
            var selAll = document.getElementById('outfitBulkSelectAll');
            if (selAll) {
                selAll.addEventListener('change', function() {
                    var on = selAll.checked;
                    document.querySelectorAll('#outfitsTable tbody tr[data-id]').forEach(function(tr) {
                        if (tr.style.display === 'none') return;
                        var c = tr.querySelector('.outfit-bulk-check');
                        if (c) c.checked = on;
                    });
                    updateBulkSelectionUi();
                });
            }
            var bm = document.getElementById('bulkEditModal');
            if (bm) {
                bm.addEventListener('click', function(e) {
                    if (e.target === bm) closeBulkEditModal();
                });
            }
            var bulkForm = document.getElementById('bulkEditForm');
            if (bulkForm) {
                bulkForm.addEventListener('input', function(e) {
                    if (e.target && e.target.closest && e.target.closest('#bulkEditRowsBody')) {
                        refreshBulkEditDuplicateUi();
                        scheduleBulkEditStoreUniqueCheck(e.target);
                    }
                });
            }
            updateBulkSelectionUi();
        })();

        @if(old('codes') || $errors->has('codes')) document.getElementById('bulkForm').classList.remove('hidden'); @endif
        @if(old('pairs') || $errors->has('pairs')) document.getElementById('bulkNewCodeForm').classList.remove('hidden'); @endif

        function editOutfit(id, code, newCode, desc, price, stock, qrRedirect) {
            editingOutfitId = id;
            clearEditValidationUi();
            document.getElementById('editForm').action = outfitsUpdateUrl(id);
            document.getElementById('editCode').value = code || '';
            document.getElementById('editNewCode').value = newCode || '';
            document.getElementById('editDesc').value = desc || '';
            @if($pricing)
            (function() {
                var el = document.getElementById('editPrice');
                if (!el) return;
                if (price === null || price === undefined || price === '') {
                    el.value = '';
                } else {
                    var n = outfitCoercePriceNumericForEdit(price);
                    el.value = Number.isFinite(n) ? formatOutfitPriceDisplay(n) : '';
                }
            })();
            updateEditPriceTaxPreview();
            @endif
            document.getElementById('editStock').value = Number.isFinite(Number(stock)) ? Number(stock) : 1;
            var qrEl = document.getElementById('editQrRedirect');
            if (qrEl) qrEl.value = qrRedirect || '';
            document.getElementById('editModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }
        function closeModal() {
            editingOutfitId = null;
            clearEditValidationUi();
            document.getElementById('editModal').classList.add('hidden');
            document.body.style.overflow = '';
        }
        document.getElementById('editModal').addEventListener('click', function(e) { if (e.target === this) closeModal(); });

        // Prevent accidental submit when pressing Enter while editing form inputs.
        function preventEnterSubmit(formId) {
            const form = document.getElementById(formId);
            if (!form) return;
            form.querySelectorAll('input').forEach(function(input) {
                input.addEventListener('keydown', function(e) {
                    if (e.key === 'Enter') {
                        e.preventDefault();
                    }
                });
            });
        }
        preventEnterSubmit('createOutfitForm');
        preventEnterSubmit('editForm');

        (function bindCreateFormAjaxSubmit() {
            var createForm = document.getElementById('createOutfitForm');
            if (!createForm) return;
            createForm.addEventListener('submit', function(e) {
                e.preventDefault();
                var btn = createForm.querySelector('button[type="submit"]');
                var prevLabel = btn ? btn.textContent : '';
                if (btn) {
                    btn.disabled = true;
                    btn.textContent = 'Saving…';
                }
                var fd = new FormData(createForm);
                fetch(String(createForm.getAttribute('action') || createForm.action || ''), {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getOutfitsCsrfToken(),
                    },
                    credentials: 'same-origin',
                }).then(function(r) {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = prevLabel;
                    }
                    if (r.status === 422) {
                        return r.json().then(function(payload) {
                            showEditValidationPopup(payload);
                        }).catch(function() {
                            showOutfitsAjaxFlash('Could not add item (invalid response).', 'error');
                        });
                    }
                    if (r.status === 419) {
                        showOutfitsAjaxFlash('Session expired — refresh the page and try again.', 'error');
                        return;
                    }
                    if (r.ok) {
                        var msg = 'Item added successfully.';
                        showOutfitsAjaxFlash(msg, 'success', { skipPopup: true });
                        return window.rentalAlert(msg, { icon: 'success', title: 'Created' }).then(function() {
                            window.location.reload();
                        });
                    }
                    return r.text().then(function() {
                        showOutfitsAjaxFlash('Could not add item (HTTP ' + r.status + ').', 'error');
                    });
                }).catch(function() {
                    if (btn) {
                        btn.disabled = false;
                        btn.textContent = prevLabel;
                    }
                    showOutfitsAjaxFlash('Network error — could not add item. Check connection, then try again.', 'error');
                });
            });
        })();

        (function bindEditFormAjaxSubmit() {
            var editForm = document.getElementById('editForm');
            if (!editForm) return;
            editForm.addEventListener('input', function(e) {
                var t = e.target;
                if (!t || !t.id) return;
                if (['editCode', 'editNewCode', 'editDesc', 'editPrice', 'editStock', 'editQrRedirect'].indexOf(t.id) === -1) return;
                t.classList.remove('border-red-500', 'ring-2', 'ring-red-300', 'bg-red-50');
                t.removeAttribute('aria-invalid');
            });
            function sendEditRequest(action) {
                var fd = new FormData(editForm);
                return fetch(action, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getOutfitsCsrfToken(),
                    },
                    credentials: 'same-origin',
                });
            }
            editForm.addEventListener('submit', function(e) {
                var action = String(editForm.getAttribute('action') || editForm.action || '');
                if (!/\/outfits\/\d+/.test(action)) {
                    e.preventDefault();
                    window.rentalAlert('Open Edit from the table first, then save.', { icon: 'warning', title: 'Edit unavailable' });
                    return;
                }
                e.preventDefault();
                window.rentalConfirm('Save changes to this item?', {
                    title: 'Confirm update',
                    confirmButtonText: 'Save',
                    cancelButtonText: 'Cancel',
                    icon: 'question',
                }).then(function(confirmed) {
                    if (!confirmed) {
                        return;
                    }
                    var btn = editForm.querySelector('button[type="submit"]');
                    var prevLabel = btn ? btn.textContent : '';
                    if (btn) {
                        btn.disabled = true;
                        btn.textContent = 'Saving…';
                    }
                    sendEditRequest(action).catch(function() {
                        // One retry for transient browser/network hiccups.
                        return sendEditRequest(action);
                    }).then(function(r) {
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = prevLabel;
                        }
                        if (r.status === 422) {
                            return r.json().then(function(payload) {
                                showEditValidationInModal(payload);
                            }).catch(function() {
                                showOutfitsAjaxFlash('Could not save changes (invalid response).', 'error');
                            });
                        }
                        if (r.status === 419) {
                            showOutfitsAjaxFlash('Session expired — refresh the page and try again.', 'error');
                            return;
                        }
                        if (r.ok) {
                            return r.json().then(function(payload) {
                                var msg = (payload && payload.message) ? String(payload.message) : 'Item updated successfully.';
                                closeModal();
                                showOutfitsAjaxFlash(msg, 'success', { skipPopup: true });
                                return window.rentalAlert(msg, { icon: 'success', title: 'Saved' }).then(function() {
                                    window.location.reload();
                                });
                            }).catch(function() {
                                var msg = 'Item updated successfully.';
                                closeModal();
                                showOutfitsAjaxFlash(msg, 'success', { skipPopup: true });
                                return window.rentalAlert(msg, { icon: 'success', title: 'Saved' }).then(function() {
                                    window.location.reload();
                                });
                            });
                        }
                        if (r.status === 0 || r.type === 'opaqueredirect') {
                            window.location.href = rentalOutfitsIndexUrl;
                            return;
                        }
                        return r.text().then(function() {
                            showOutfitsAjaxFlash('Could not save changes (HTTP ' + r.status + ').', 'error');
                        });
                    }).catch(function() {
                        if (btn) {
                            btn.disabled = false;
                            btn.textContent = prevLabel;
                        }
                        showOutfitsAjaxFlash('Network error — could not reach server after retry. Check connection, then try again.', 'error');
                    });
                });
            });
        })();

        (function bindBulkNewCodeAjaxSubmit() {
            var form = document.getElementById('bulkNewCodeFormEl');
            if (!form) return;
            var submitBtn = form.querySelector('button[type="submit"]');
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var pairs = buildBulkNewCodePairsString();
                var fd = new FormData();
                fd.append('_token', getOutfitsCsrfToken());
                fd.append('pairs', pairs);
                var prevLabel = submitBtn ? submitBtn.textContent : '';
                if (submitBtn) {
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Updating…';
                }
                fetch(rentalBulkNewCodeUrl, {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': getOutfitsCsrfToken(),
                    },
                    credentials: 'same-origin',
                }).then(function(r) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = prevLabel;
                    }
                    if (r.status === 422) {
                        return r.json().then(function(payload) {
                            var lines = [];
                            if (payload && payload.errors && typeof payload.errors === 'object') {
                                Object.keys(payload.errors).forEach(function(key) {
                                    var arr = payload.errors[key];
                                    if (Array.isArray(arr)) {
                                        arr.forEach(function(msg) {
                                            if (msg) lines.push(String(msg));
                                        });
                                    }
                                });
                            }
                            var text = lines.length ? lines.join('\n') : (payload && payload.message ? String(payload.message) : 'Could not update codes.');
                            showOutfitsValidationAlertModal('Could not update codes', text);
                        }).catch(function() {
                            showOutfitsValidationAlertModal('Could not update codes', 'The server returned an invalid response. Try again.');
                        });
                    }
                    if (r.status === 419) {
                        showOutfitsValidationAlertModal('Session expired', 'Refresh the page and try again.');
                        return;
                    }
                    if (r.ok) {
                        return r.json().then(function() {
                            window.location.reload();
                        }).catch(function() {
                            window.location.reload();
                        });
                    }
                    return r.text().then(function() {
                        showOutfitsValidationAlertModal('Could not update codes', 'Server returned HTTP ' + r.status + '.');
                    });
                }).catch(function() {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.textContent = prevLabel;
                    }
                    showOutfitsValidationAlertModal('Network error', 'Check your connection and try again.');
                });
            });
        })();

        document.getElementById('outfitsValidationAlertModalOk')?.addEventListener('click', closeOutfitsValidationAlertModal);
        document.getElementById('outfitsValidationAlertModal')?.addEventListener('click', function(e) {
            if (e.target === this) closeOutfitsValidationAlertModal();
        });

        @if($pricing)
        bindOutfitPriceThousandsInput(document.getElementById('createPrice'));
        bindOutfitPriceThousandsInput(document.getElementById('editPrice'));
        function updateEditPriceTaxPreview() {
            var priceEl = document.getElementById('editPrice');
            var taxEl = document.getElementById('editTaxPercent');
            var beforeEl = document.getElementById('editPriceBeforeTax');
            if (!priceEl || !taxEl || !beforeEl) return;
            var pctRaw = Number(rentalPricingTaxPercent) || 0;
            taxEl.value = pctRaw > 0 ? (String(pctRaw).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '') + '%') : '0%';
            var raw = String(priceEl.value || '').trim();
            if (raw === '') {
                beforeEl.value = '';
                return;
            }
            var n = parseOutfitPriceDisplay(raw);
            if (!Number.isFinite(n)) {
                beforeEl.value = '';
                return;
            }
            var r = pctRaw / 100;
            if (r <= 0) {
                beforeEl.value = formatOutfitPriceDisplay(n);
                return;
            }
            if (rentalPricingPricesIncludeTax) {
                var before = n - (n * r);
                beforeEl.value = formatOutfitPriceDisplay(Math.max(0, Math.round(before * 100) / 100));
                return;
            }
            beforeEl.value = formatOutfitPriceDisplay(Math.max(0, Math.round(n * 100) / 100));
        }
        (function bindEditPriceTaxPreview() {
            var ep = document.getElementById('editPrice');
            if (!ep) return;
            ep.addEventListener('input', updateEditPriceTaxPreview);
            ep.addEventListener('blur', updateEditPriceTaxPreview);
            updateEditPriceTaxPreview();
        })();
        (function() {
            var cp = document.getElementById('createPrice');
            if (!cp || cp.value.trim() === '') return;
            var n = parseOutfitPriceDisplay(cp.value);
            if (Number.isFinite(n)) cp.value = formatOutfitPriceDisplay(n);
        })();
        @endif

        function openQrModal(outfitId, code, statusUrl, qrImageUrl, downloadUrl) {
            document.getElementById('qrCodeLabel').textContent = code ? `Code: ${code}` : 'Item';
            var src = '';
            if (statusUrl) {
                src = 'https://api.qrserver.com/v1/create-qr-code/?size=320x320&data=' + encodeURIComponent(statusUrl);
            } else if (qrImageUrl) {
                src = String(qrImageUrl).replace(/&amp;/g, '&');
            }
            document.getElementById('qrImage').src = src;
            const link = document.getElementById('qrStatusLink');
            link.href = statusUrl || '#';
            link.textContent = statusUrl || '';
            var dl = document.getElementById('qrDownloadLink');
            var hint = document.getElementById('qrDownloadFilenameHint');
            if (dl) {
                dl.href = downloadUrl || '#';
                dl.classList.toggle('pointer-events-none', !downloadUrl);
                dl.classList.toggle('opacity-50', !downloadUrl);
            }
            if (hint) {
                var safe = (code && String(code).trim()) ? String(code).replace(/[^A-Za-z0-9._-]+/g, '_').replace(/^[._-]+|[._-]+$/g, '') : '';
                hint.textContent = (safe || (outfitId ? 'item-' + outfitId : 'item')) + '.png';
            }
            document.getElementById('qrModal').classList.remove('hidden');
            document.body.style.overflow = 'hidden';
        }

        function closeQrModal() {
            document.getElementById('qrModal').classList.add('hidden');
            document.body.style.overflow = '';
        }

        document.getElementById('qrModal').addEventListener('click', function(e) {
            if (e.target === this) closeQrModal();
        });

        function clearOutfitDates() { document.getElementById('outfitStart').value = ''; document.getElementById('outfitEnd').value = '';
            document.querySelectorAll('#outfitsTable tbody tr[data-id]').forEach(function(tr) {
                const orig = tr.dataset.originalStatus || tr.dataset.status;
                tr.dataset.status = orig;
                const statusTd = tr.querySelector('td.outfit-status');
                if (statusTd) {
                    statusTd.innerHTML = tr.dataset.originalStatusHtml || '';
                }
            });
            applyFilters(); }

        // Auto-fill end date 2 days after start on Outfits page
        document.getElementById('outfitStart').addEventListener('change', function() {
            const start = this.value;
            const endEl = document.getElementById('outfitEnd');
            if (!start) return;
            const d = new Date(start);
            d.setDate(d.getDate() + 2);
            const next = d.toISOString().split('T')[0];
            if (!endEl.value || endEl.value < start) {
                endEl.value = next;
            }
            endEl.min = start;
            fetchOutfitAvailability();
        });
        document.getElementById('outfitEnd').addEventListener('change', fetchOutfitAvailability);

        // Save original statuses so clearing dates can restore them
        document.querySelectorAll('#outfitsTable tbody tr[data-id]').forEach(function(tr) {
            tr.dataset.originalStatus = tr.dataset.status;
            var statusTd = tr.querySelector('td.outfit-status');
            tr.dataset.originalStatusHtml = statusTd ? statusTd.innerHTML : '';
        });

        function updateRentalDetailDupBackButton() {
            var btn = document.getElementById('rentalDetailBackBtn');
            if (!btn) {
                return;
            }
            if (rentalDetailDupGroupReturnSlug) {
                btn.classList.remove('hidden');
            } else {
                btn.classList.add('hidden');
            }
        }

        function openRentalForOutfit(code, opts) {
            opts = opts || {};
            if (opts.dupGroupReturnSlug) {
                rentalDetailDupGroupReturnSlug = String(opts.dupGroupReturnSlug || '').trim() || null;
            } else {
                rentalDetailDupGroupReturnSlug = null;
            }
            const start = document.getElementById('outfitStart').value;
            const end = document.getElementById('outfitEnd').value;
            var qs = 'code=' + encodeURIComponent(code);
            if (start && end) {
                qs += '&start=' + encodeURIComponent(start) + '&end=' + encodeURIComponent(end);
            }
            fetch(rentalRentalDetailFragmentUrl + '?' + qs)
                .then(r => r.json())
                .then(function(res) {
                    if (res && res.ok && res.html) {
                        document.getElementById('rentalDetailContent').innerHTML = res.html;
                        document.getElementById('rentalDetailModal').classList.remove('hidden');
                        document.body.style.overflow = 'hidden';
                        updateRentalDetailDupBackButton();
                        return;
                    }
                    var slugNoRent = rentalDetailDupGroupReturnSlug;
                    rentalDetailDupGroupReturnSlug = null;
                    window.rentalAlert((res && res.message) ? String(res.message) : 'No active rent found for this item' + (start && end ? ' in the selected date range.' : '.'), { icon: 'info', title: 'No active rent' });
                    if (slugNoRent) {
                        openDupGroupEditModal(slugNoRent);
                    }
                })
                .catch(function() {
                    var slugErr = rentalDetailDupGroupReturnSlug;
                    rentalDetailDupGroupReturnSlug = null;
                    window.rentalAlert('Could not load rental info. Try again.', { icon: 'error', title: 'Load failed' });
                    if (slugErr) {
                        openDupGroupEditModal(slugErr);
                    }
                });
        }

        function rentalDetailBackToDupGroup() {
            var slug = rentalDetailDupGroupReturnSlug;
            closeRentalDetailModal();
            if (slug) {
                openDupGroupEditModal(slug);
            }
        }

        function openRentalFromList(id) {
            // fetch rental and open edit modal in rentals page in new tab
            window.open(rentalRentalsIndexUrl + '?edit=' + encodeURIComponent(id), '_blank');
        }

        function closeRentalDetailModal() {
            rentalDetailDupGroupReturnSlug = null;
            var el = document.getElementById('rentalDetailModal');
            if (el) {
                el.classList.add('hidden');
            }
            document.body.style.overflow = '';
            updateRentalDetailDupBackButton();
        }
        document.getElementById('rentalDetailModal')?.addEventListener('click', function(e) { if (e.target === this) closeRentalDetailModal(); });

        (function bindOutfitsTableActions() {
            var table = document.getElementById('outfitsTable');
            if (!table) return;
            table.addEventListener('click', function(e) {
                var qr = e.target.closest('.outfit-act-qr');
                if (qr) {
                    e.preventDefault();
                    openQrModal(
                        parseInt(qr.getAttribute('data-qr-id'), 10),
                        qr.getAttribute('data-qr-code') || '',
                        qr.getAttribute('data-qr-status-url') || '',
                        qr.getAttribute('data-qr-image-url') || '',
                        qr.getAttribute('data-qr-download-url') || ''
                    );
                    return;
                }
                var ed = e.target.closest('.outfit-act-edit');
                if (ed) {
                    e.preventDefault();
                    var sid = parseInt(ed.getAttribute('data-outfit-id'), 10);
                    var priceAttr = ed.getAttribute('data-outfit-price');
                    var priceVal = (priceAttr === null || priceAttr === '') ? null : priceAttr;
                    editOutfit(
                        sid,
                        ed.getAttribute('data-outfit-code') || '',
                        ed.getAttribute('data-outfit-new-code') || '',
                        ed.getAttribute('data-outfit-description') || '',
                        priceVal,
                        parseInt(ed.getAttribute('data-outfit-stock'), 10) || 1,
                        ed.getAttribute('data-outfit-qr-url') || ''
                    );
                    return;
                }
                var del = e.target.closest('.outfit-act-delete');
                if (del) {
                    e.preventDefault();
                    quickDeleteOutfit(
                        parseInt(del.getAttribute('data-delete-id'), 10),
                        del.getAttribute('data-delete-code') || ''
                    );
                    return;
                }
                var ren = e.target.closest('.outfit-act-rental');
                if (ren) {
                    e.preventDefault();
                    openRentalForOutfit(ren.getAttribute('data-rental-code') || '');
                }
            });
        })();

    </script>

    @if(count($outfitsFlashSegments) > 0)
        <div
            id="outfitsSessionFlashModal"
            class="fixed inset-0 z-[100] flex items-center justify-center p-4 bg-gray-900/60"
            role="alertdialog"
            aria-modal="true"
            aria-labelledby="outfitsSessionFlashModalTitle"
        >
            <div class="bg-white rounded-2xl shadow-xl border border-gray-200 max-w-lg w-full p-6">
                <h3 id="outfitsSessionFlashModalTitle" class="text-lg font-semibold text-gray-900 mb-3">
                    @php
                        $hasErr = collect($outfitsFlashSegments)->contains(fn ($s) => $s['kind'] === 'error');
                        $hasOk = collect($outfitsFlashSegments)->contains(fn ($s) => $s['kind'] === 'success');
                    @endphp
                    @if($hasErr && $hasOk)
                        Notice
                    @elseif($hasErr)
                        Something went wrong
                    @else
                        Success
                    @endif
                </h3>
                <div class="space-y-4 text-sm max-h-[min(60vh,24rem)] overflow-y-auto">
                    @foreach($outfitsFlashSegments as $seg)
                        <div @class([
                            'rounded-lg px-4 py-3 whitespace-pre-wrap break-words border',
                            'text-green-900 bg-green-50 border-green-200' => $seg['kind'] === 'success',
                            'text-red-900 bg-red-50 border-red-200' => $seg['kind'] === 'error',
                        ])>{{ $seg['text'] }}</div>
                    @endforeach
                </div>
                <div class="mt-6 flex justify-end">
                    <button
                        type="button"
                        id="outfitsSessionFlashModalOk"
                        class="px-4 py-2 bg-indigo-600 text-white rounded-lg text-sm font-medium hover:bg-indigo-700 cursor-pointer"
                    >
                        OK
                    </button>
                </div>
            </div>
        </div>
        <script>
            (function() {
                function closeOutfitsSessionFlashModal() {
                    var el = document.getElementById('outfitsSessionFlashModal');
                    if (el) {
                        el.remove();
                    }
                    document.body.style.overflow = '';
                }
                document.addEventListener('DOMContentLoaded', function() {
                    document.body.style.overflow = 'hidden';
                    var btn = document.getElementById('outfitsSessionFlashModalOk');
                    if (btn) {
                        btn.addEventListener('click', closeOutfitsSessionFlashModal);
                        btn.focus();
                    }
                    var backdrop = document.getElementById('outfitsSessionFlashModal');
                    if (backdrop) {
                        backdrop.addEventListener('click', function(e) {
                            if (e.target === backdrop) {
                                closeOutfitsSessionFlashModal();
                            }
                        });
                    }
                    document.addEventListener('keydown', function escClose(e) {
                        if (e.key === 'Escape') {
                            closeOutfitsSessionFlashModal();
                            document.removeEventListener('keydown', escClose);
                        }
                    });
                });
            })();
        </script>
    @endif
</x-app-layout>
