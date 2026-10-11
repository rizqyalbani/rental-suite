<?php

namespace App\Support;

use App\Models\Rental;
use App\Models\Outfit;
use Illuminate\Support\Carbon;

/**
 * Single place for rental line / order discount / tax math (matches ShiftController & RentalController).
 */
final class RentalFinancialSummary
{
    /**
     * @return array{
     *     gross: float,
     *     sum_after_item_discounts: float,
     *     item_discount_total: float,
     *     order_discount_amount: float,
     *     pre_tax_net: float,
     *     tax_rate: float,
     *     tax_amount: float,
     *     net_incl_tax: float,
     *     rental_days: int,
     *     lines: list<array{code: string, price: float, days: int, line_gross: float, discount: float, discount_type: string, line_net: float, discount_note: string}>,
     *     order_discount_note: string,
     *     items_display: string,
     *     discounts_display: string
     * }
     */
    public static function summarize(Rental $rental, float $taxPercent): array
    {
        $taxPercent = max(0, $taxPercent);
        $fallbackRate = $taxPercent / 100;
        $pricesIncludeTax = (bool) StoreCache::get('pricing_prices_include_tax', false);
        $gross = 0.0;
        $sumAfterItems = 0.0;
        $grossExcl = 0.0;
        $grossTax = 0.0;
        $lines = [];
        ['days' => $days, 'blocks' => $blocks, 'extra_days' => $extraDays, 'mode' => $pricingMode] = self::billingInfo($rental);
        // In block mode the unit price is charged once per 3-day block, not per day.
        $priceMultiplier = ($pricingMode === 'block') ? $blocks : $days;

        foreach ($rental->outfits as $outfit) {
            $pivotIncl = $outfit->pivot->price_incl_tax ?? null;
            $pivotExcl = $outfit->pivot->price_excl_tax ?? null;
            $pivotTax = $outfit->pivot->tax_amount ?? null;

            if ($pricesIncludeTax) {
                $unitIncl = (float) (($pivotIncl !== null && $pivotIncl !== '') ? $pivotIncl : ($outfit->price ?? 0));
                // $unitIncl already HAS tax folded in — the tax portion is
                // rate/(1+rate) of it, not rate/1, or "unwrapping" it here
                // and re-adding it elsewhere double-counts the tax on tax.
                $unitTaxAmount = $pivotTax !== null && $pivotTax !== ''
                    ? (float) $pivotTax
                    : round($unitIncl * $fallbackRate / (1 + $fallbackRate), 2);
                $unitExcl = $pivotExcl !== null && $pivotExcl !== ''
                    ? (float) $pivotExcl
                    : round($unitIncl - $unitTaxAmount, 2);

                $lineGross = $unitIncl * $priceMultiplier;
                $gross += $lineGross;
                $grossExcl += $unitExcl * $priceMultiplier;
                $grossTax += $unitTaxAmount * $priceMultiplier;
            } else {
                $unitExcl = (float) (($pivotExcl !== null && $pivotExcl !== '') ? $pivotExcl : ($outfit->price ?? 0));
                $unitTaxAmount = $pivotTax !== null && $pivotTax !== ''
                    ? (float) $pivotTax
                    : round($unitExcl * $fallbackRate, 2);
                $unitIncl = (float) (($pivotIncl !== null && $pivotIncl !== '') ? $pivotIncl : round($unitExcl + $unitTaxAmount, 2));

                $lineGross = $unitExcl * $priceMultiplier;
                $gross += $lineGross;
                $lineTax = $unitTaxAmount * $priceMultiplier;
                $grossExcl += $lineGross;
                $grossTax += $lineTax;
            }

            $itemDiscount = (float) ($outfit->pivot->discount ?? 0);
            $itemDiscountType = (string) ($outfit->pivot->discount_type ?? 'fixed');
            $lineNet = $itemDiscount > 0
                ? ($itemDiscountType === 'percent'
                    ? $lineGross - ($lineGross * $itemDiscount / 100)
                    : $lineGross - $itemDiscount)
                : $lineGross;
            $lineNet = max(0, $lineNet);
            $sumAfterItems += $lineNet;

            $code = (string) ($outfit->code ?? '');
            $newCode = trim((string) ($outfit->new_code ?? ''));
            $codeLabel = $newCode !== '' ? $code.' / '.$newCode : $code;
            $lines[] = [
                'code' => $code,
                'code_label' => $codeLabel,
                'price' => $pricesIncludeTax ? $unitIncl : $unitExcl,
                'price_excl_tax' => $unitExcl,
                'tax_per_day' => $unitTaxAmount,
                'days' => $priceMultiplier,
                'line_gross' => $lineGross,
                'discount' => $itemDiscount,
                'discount_type' => $itemDiscountType,
                'line_net' => $lineNet,
                'discount_note' => self::formatDiscountNote($itemDiscount, $itemDiscountType, $lineGross),
            ];
        }

        $itemDiscountTotal = max(0, $gross - $sumAfterItems);

        $rentalDiscount = (float) ($rental->discount ?? 0);
        $rentalDiscountType = (string) ($rental->discount_type ?? 'fixed');
        $orderDiscountAmount = 0.0;
        $orderNote = '—';

        if ($pricesIncludeTax) {
            $inclBeforeOrder = $sumAfterItems;
            $inclAfterOrder = $inclBeforeOrder;
            if ($rentalDiscount > 0) {
                if ($rentalDiscountType === 'percent') {
                    $inclAfterOrder = $sumAfterItems - ($sumAfterItems * $rentalDiscount / 100);
                    $orderNote = number_format($rentalDiscount, 0, ',', '.').'%';
                } else {
                    $inclAfterOrder = $sumAfterItems - $rentalDiscount;
                    $orderNote = 'Rp '.number_format($rentalDiscount, 0, ',', '.');
                }
            }

            $inclAfterOrder = max(0, $inclAfterOrder);
            $orderDiscountAmount = $inclBeforeOrder - $inclAfterOrder;
            // Same unwrap-don't-multiply correction as the per-line fallback
            // above — $inclAfterOrder is tax-inclusive, so the tax slice is
            // rate/(1+rate) of it (tax base = selling price ÷ (1+rate) per VAT rules),
            // not a flat rate off the inclusive total.
            $taxAmount = max(0.0, $inclAfterOrder * $fallbackRate / (1 + $fallbackRate));
            $preTaxNet = max(0.0, $inclAfterOrder - $taxAmount);
            $netInclTax = $inclAfterOrder;
        } else {
            $preTaxBeforeOrder = $sumAfterItems;
            $preTax = $preTaxBeforeOrder;
            if ($rentalDiscount > 0) {
                if ($rentalDiscountType === 'percent') {
                    $preTax = $sumAfterItems - ($sumAfterItems * $rentalDiscount / 100);
                    $orderNote = number_format($rentalDiscount, 0, ',', '.').'%';
                } else {
                    $preTax = $sumAfterItems - $rentalDiscount;
                    $orderNote = 'Rp '.number_format($rentalDiscount, 0, ',', '.');
                }
            }

            $preTax = max(0, $preTax);
            $orderDiscountAmount = $preTaxBeforeOrder - $preTax;
            $taxAmount = $preTax * $fallbackRate;
            $preTaxNet = $preTax;
            $netInclTax = max(0, $preTax + $taxAmount);
        }
        $overdueFee = max(0.0, (float) ($rental->overdue_fee_amount ?? 0));
        $extraCharge = max(0.0, (float) ($rental->extra_charge ?? 0));
        $netInclTaxWithOverdue = $netInclTax + $overdueFee + $extraCharge;

        $itemsDisplay = collect($lines)
            ->map(function (array $L) use ($pricingMode) {
                $label = $L['code_label'] ?? $L['code'];
                $units = (int) ($L['days'] ?? 1);
                $unitLabel = $pricingMode === 'block' ? '/3 day' : '/day';
                $line = $label.' @ Rp '.number_format((float) ($L['price'] ?? 0), 0, ',', '.').$unitLabel;
                if ($units > 1) {
                    $line .= ' × '.$units;
                }
                $bits = [$line];
                if ($L['discount_note'] !== '—') {
                    $bits[] = '-'.$L['discount_note'];
                    $bits[] = '→ Rp '.number_format((float) ($L['line_net'] ?? 0), 0, ',', '.');
                }

                return implode(' ', $bits);
            })
            ->implode('; ');

        // Item discounts already appear on each item line; this shows only the transaction-level discount.
        $discountsDisplay = $orderDiscountAmount > 0
            ? (str_contains($orderNote, '%')
                ? $orderNote.' (-Rp '.number_format($orderDiscountAmount, 0, ',', '.').')'
                : $orderNote)
            : '—';

        return [
            'gross' => $gross,
            'gross_excl_tax' => $grossExcl,
            'gross_tax_amount' => $grossTax,
            'sum_after_item_discounts' => $sumAfterItems,
            'item_discount_total' => $itemDiscountTotal,
            'order_discount_amount' => max(0, $orderDiscountAmount),
            'pre_tax_net' => $preTaxNet,
            'tax_rate' => $taxPercent,
            'tax_amount' => $taxAmount,
            'net_incl_tax' => $netInclTaxWithOverdue,
            'net_excl_tax' => $preTaxNet + $overdueFee + $extraCharge,
            'rental_days' => $days,
            'rental_blocks' => $blocks,
            'extra_days' => $extraDays,
            'pricing_mode' => $pricingMode,
            'extra_charge' => $extraCharge,
            'overdue_fee_amount' => $overdueFee,
            'lines' => $lines,
            'order_discount_note' => $orderNote,
            'items_display' => $itemsDisplay !== '' ? $itemsDisplay : '—',
            'discounts_display' => $discountsDisplay,
        ];
    }

    /** Returns the number of extra (non-block) days for a date range under the current cut-off rule. */
    public static function extraDaysFromDates(string $startDate, string $endDate): int
    {
        $sd = Carbon::parse($startDate)->startOfDay();
        $ed = Carbon::parse($endDate)->startOfDay();
        if ($ed->lt($sd)) {
            [$sd, $ed] = [$ed, $sd];
        }
        $diff = (int) $sd->diffInDays($ed);
        $calendarDays = $diff + 1;

        $cutOff = StoreCache::get('pricing_legacy_cut_off_date');
        if ($cutOff) {
            // New rentals are always post-cut-off (created_at = now); legacy check not applicable.
            $cutOffDay = Carbon::parse(substr((string) $cutOff, 0, 10))->startOfDay();
            if (Carbon::now()->startOfDay()->lt($cutOffDay)) {
                return 0; // legacy pricing — no blocks
            }
        }

        $blockDays = max(3, (int) (floor($calendarDays / 3) * 3));
        return max(0, $calendarDays - $blockDays);
    }

    public static function netInclTax(Rental $rental, float $taxPercent): float
    {
        return self::summarize($rental, $taxPercent)['net_incl_tax'];
    }

    /**
     * The unit price each item was booked at, keyed by outfit id. A rent keeps these across
     * edits and payments (a catalog price change must not re-price a rent that already
     * exists); only an item added later is priced from the catalog. Rows without a stored
     * price (very old rents) are left out and fall back to the catalog as before.
     *
     * @return array<int, array{price_incl_tax: mixed, price_excl_tax: mixed, tax_amount: mixed}>
     */
    public static function lockedUnitPrices(Rental $rental): array
    {
        $rental->loadMissing('outfits');
        $locked = [];
        foreach ($rental->outfits as $outfit) {
            $incl = $outfit->pivot->price_incl_tax ?? null;
            if ($incl === null || $incl === '') {
                continue;
            }
            $locked[(int) $outfit->id] = [
                'price_incl_tax' => $incl,
                'price_excl_tax' => $outfit->pivot->price_excl_tax ?? null,
                'tax_amount' => $outfit->pivot->tax_amount ?? null,
            ];
        }

        return $locked;
    }

    /**
     * Build an unsaved rental + outfit rows for the same pricing math as saved rentals (forms / approvals).
     *
     * @param  list<int>  $outfitIds  ordered outfit primary keys
     * @param  array<int|string, mixed>  $itemDiscounts
     * @param  array<int|string, mixed>  $itemDiscountTypes
     * @param  array<int, array<string, mixed>>|null  $lockedPrices  see lockedUnitPrices(); null = price everything from the catalog (new rent)
     */
    public static function previewRentalForTotals(
        array $outfitIds,
        array $itemDiscounts,
        array $itemDiscountTypes,
        mixed $orderDiscount,
        mixed $orderDiscountType,
        mixed $startDate = null,
        mixed $endDate = null,
        mixed $createdAt = null,
        ?array $lockedPrices = null,
        bool $includePracticeOutfits = false,
    ): Rental {
        $ids = array_values(array_filter(array_map('intval', $outfitIds), fn (int $id) => $id > 0));
        // A practice (tour) rent is made of dedicated is_tutorial outfits that the default scope hides; without
        // this the practice outfits vanish and the total due reads Rp 0 (docs/readiness-isolation-audit.md F1-F3).
        // Real rents never pass the flag, so they still cannot see practice outfits.
        $outfitQuery = $includePracticeOutfits ? Outfit::withoutGlobalScope('tutorial_scope') : Outfit::query();
        $outfitsById = $outfitQuery->whereIn('id', $ids, 'and', false)->get()->keyBy('id');

        $outfits = collect($ids)
            ->map(fn (int $id) => $outfitsById->get($id))
            ->filter()
            ->values();

        $outfits->each(function (Outfit $outfit) use ($itemDiscounts, $itemDiscountTypes, $lockedPrices): void {
            $id = (int) $outfit->id;
            // Items already on the rent keep the price they were booked at (see
            // lockedUnitPrices); only an item without a locked price is read from the catalog.
            $outfit->setRelation('pivot', (object) array_merge([
                'discount' => $itemDiscounts[$id] ?? $itemDiscounts[(string) $id] ?? null,
                'discount_type' => $itemDiscountTypes[$id] ?? $itemDiscountTypes[(string) $id] ?? 'fixed',
            ], $lockedPrices[$id] ?? []));
        });

        $typeRaw = $orderDiscountType !== null && $orderDiscountType !== '' ? (string) $orderDiscountType : 'fixed';

        $rental = new Rental([
            'discount' => $orderDiscount,
            'discount_type' => $typeRaw,
        ]);
        // Without the dates every rent priced as one 3-day block, so a longer rent
        // (e.g. 9 days = 3 blocks) failed the paid-vs-total check with the right amount.
        if ($startDate && $endDate) {
            $rental->start_date = $startDate;
            $rental->end_date = $endDate;
        }
        if ($createdAt) {
            $rental->created_at = $createdAt;
        }
        $rental->setRelation('outfits', $outfits);

        return $rental;
    }

    private static function formatDiscountNote(float $amount, string $type, float $price): string
    {
        if ($amount <= 0) {
            return '—';
        }

        if ($type === 'percent') {
            return number_format($amount, 0, ',', '.').'%';
        }

        return 'Rp '.number_format(min($amount, $price), 0, ',', '.');
    }

    /** @return array{days: int, blocks: int, extra_days: int, mode: string} */
    private static function billingInfo(Rental $rental): array
    {
        $sd = $rental->start_date ? Carbon::parse($rental->start_date) : null;
        $ed = $rental->end_date ? Carbon::parse($rental->end_date) : null;
        if (! $sd || ! $ed) {
            return ['days' => 3, 'blocks' => 1, 'extra_days' => 0, 'mode' => 'block'];
        }
        if ($ed->lessThan($sd)) {
            [$sd, $ed] = [$ed, $sd];
        }

        $diff = (int) $sd->diffInDays($ed);
        $calendarDays = $diff + 1;

        $cutOff = StoreCache::get('pricing_legacy_cut_off_date');
        if ($cutOff) {
            $cutOffDay = Carbon::parse(substr((string) $cutOff, 0, 10))->startOfDay();
            $createdAt = $rental->created_at ? Carbon::parse($rental->created_at)->startOfDay() : null;
            if ($createdAt && $createdAt->lt($cutOffDay)) {
                // Legacy rentals keep the original day formula (preserve historical totals).
                $legacyDays = max(1, $diff - 1);
                return ['days' => $legacyDays, 'blocks' => $legacyDays, 'extra_days' => 0, 'mode' => 'legacy'];
            }
        }

        // Block pricing: each block = 3 calendar days, unit price is charged once per block.
        $blockDays = max(3, (int) (floor($calendarDays / 3) * 3));
        $blocks = max(1, (int) ($blockDays / 3));
        $extraDays = max(0, $calendarDays - $blockDays);

        return ['days' => $blockDays, 'blocks' => $blocks, 'extra_days' => $extraDays, 'mode' => 'block'];
    }

    private static function rentalDays(Rental $rental): int
    {
        return self::billingInfo($rental)['days'];
    }
}
