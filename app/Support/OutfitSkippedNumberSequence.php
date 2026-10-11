<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Finds missing integers between the smallest and largest numeric suffix per letter prefix.
 *
 * Uses one numeric line per prefix (BR 1 and BR 01 share slot 1). When the same prefix mixes
 * how many digits the suffix uses in storage (e.g. BR 3 vs BR 01), gaps are also computed on each
 * “same printed width” sub-sequence; any such gaps that the merged line does not already flag are
 * unioned into the same missing list for a single panel row.
 */
final class OutfitSkippedNumberSequence
{
    /**
     * @return list<array{slug: string, prefix_display: string, span_label: string, skipped: list<int>, skipped_text: string, digit_width: int, display_pad: int}>
     */
    public static function panelsFromOutfits(Collection $outfits): array
    {
        /** @var array<string, array<int, true>> */
        $numsByPrefix = [];
        /** @var array<string, int> Widest digit run seen for this letter prefix. */
        $padWidthByPrefix = [];
        /** @var array<string, array<int, true>> key "prefix|digitWidth" */
        $numsByPrefixWidth = [];
        /** @var array<string, array<int, true>> */
        $distinctWidthsByPrefix = [];

        foreach ($outfits as $outfit) {
            $parsed = self::parseNumericSuffix($outfit->code ?? null);
            if ($parsed === null) {
                continue;
            }
            [$prefix, $num, $digitWidth] = $parsed;
            $numsByPrefix[$prefix][$num] = true;
            $padWidthByPrefix[$prefix] = max($padWidthByPrefix[$prefix] ?? 0, $digitWidth);
            $numsByPrefixWidth[$prefix.'|'.$digitWidth][$num] = true;
            $distinctWidthsByPrefix[$prefix][$digitWidth] = true;
        }

        $panels = [];
        $n = 0;
        ksort($numsByPrefix);

        foreach ($numsByPrefix as $prefix => $numMap) {
            $widest = $padWidthByPrefix[$prefix] ?? 1;
            $skippedMerged = self::computeSkippedInts($numMap);
            $extra = [];

            if (count($distinctWidthsByPrefix[$prefix] ?? []) >= 2) {
                foreach ($numsByPrefixWidth as $key => $bucket) {
                    [$p, $wStr] = explode('|', $key, 2);
                    if ($p !== $prefix) {
                        continue;
                    }
                    $bucketWidth = (int) $wStr;
                    $skippedW = self::computeSkippedInts($bucket);
                    foreach (array_diff($skippedW, $skippedMerged) as $g) {
                        $g = (int) $g;
                        if (self::numericSlotCoveredAtLeastAsWideAs($numsByPrefixWidth, $prefix, $g, $bucketWidth)) {
                            continue;
                        }
                        $extra[$g] = true;
                    }
                }
            }

            $allSkipped = array_values(array_unique(array_merge($skippedMerged, array_keys($extra))));
            sort($allSkipped, SORT_NUMERIC);
            $allSkipped = self::filterSpuriousSkippedForFilledSlots(
                $allSkipped,
                $numMap,
                $numsByPrefixWidth,
                $prefix,
                $widest,
            );

            if ($allSkipped === []) {
                continue;
            }

            self::pushGapPanelRow($panels, $n, $prefix, $numMap, $allSkipped, $widest);
        }

        return $panels;
    }

    /**
     * @param  array<int, true>  $numMap
     * @return list<int>
     */
    private static function computeSkippedInts(array $numMap): array
    {
        $nums = array_keys($numMap);
        if (count($nums) < 2) {
            return [];
        }
        sort($nums, SORT_NUMERIC);
        $min = $nums[0];
        $max = $nums[count($nums) - 1];
        if ($max <= $min) {
            return [];
        }

        $skipped = [];
        for ($i = $min + 1; $i < $max; $i++) {
            if (! array_key_exists($i, $numMap)) {
                $skipped[] = $i;
            }
        }

        return $skipped;
    }

    /**
     * True if some item uses this numeric slot with a suffix at least as wide as $minWidth
     * (e.g. BR 04 covers slot 4 for a 1-digit-only sub-line, but BR 3 does not cover slot 3 for a 2-digit line).
     *
     * @param  array<string, array<int, true>>  $numsByPrefixWidth
     */
    private static function numericSlotCoveredAtLeastAsWideAs(
        array $numsByPrefixWidth,
        string $prefix,
        int $num,
        int $minWidth,
    ): bool {
        foreach ($numsByPrefixWidth as $key => $map) {
            [$p, $wStr] = explode('|', $key, 2);
            if ($p !== $prefix || ! array_key_exists($num, $map)) {
                continue;
            }
            if ((int) $wStr >= $minWidth) {
                return true;
            }
        }

        return false;
    }

    /**
     * If a slot is already filled, only keep it as "missing" when no stored code uses that slot with a
     * suffix at least as wide as this prefix's widest (e.g. keep 03 when only BR 3 exists; drop 04 if BR 04 exists).
     *
     * @param  list<int>  $skipped
     * @param  array<int, true>  $numMap
     * @param  array<string, array<int, true>>  $numsByPrefixWidth
     * @return list<int>
     */
    private static function filterSpuriousSkippedForFilledSlots(
        array $skipped,
        array $numMap,
        array $numsByPrefixWidth,
        string $prefix,
        int $widestForPrefix,
    ): array {
        $out = [];
        foreach ($skipped as $g) {
            $g = (int) $g;
            if (! array_key_exists($g, $numMap)) {
                $out[] = $g;

                continue;
            }
            if (self::numericSlotCoveredAtLeastAsWideAs($numsByPrefixWidth, $prefix, $g, $widestForPrefix)) {
                continue;
            }
            $out[] = $g;
        }

        return $out;
    }

    /**
     * @param  array<int, true>  $numMap  Used for span (#min–#max)
     * @param  list<int>  $skipped
     */
    private static function pushGapPanelRow(
        array &$panels,
        int &$slugN,
        string $prefix,
        array $numMap,
        array $skipped,
        int $widestForInfer,
    ): void {
        $nums = array_keys($numMap);
        sort($nums, SORT_NUMERIC);
        $min = $nums[0];
        $max = $nums[count($nums) - 1];

        $displayPad = self::inferDisplayPad($widestForInfer, $widestForInfer, $min, $max);

        $maxSkippedPreview = 120;
        $skippedPreview = $skipped;
        $skippedMoreCount = 0;
        if (count($skippedPreview) > $maxSkippedPreview) {
            $skippedMoreCount = count($skippedPreview) - $maxSkippedPreview;
            $skippedPreview = array_slice($skippedPreview, 0, $maxSkippedPreview);
        }
        $skippedText = self::formatSkippedList($skippedPreview, $displayPad);
        if ($skippedMoreCount > 0) {
            $skippedText .= ' + '.$skippedMoreCount.' more';
        }

        $panels[] = [
            'slug' => 'sk'.$slugN++,
            'prefix_display' => strtoupper($prefix),
            'span_label' => self::formatPadded($min, $displayPad).'–'.self::formatPadded($max, $displayPad),
            'skipped' => $skipped,
            'skipped_text' => $skippedText,
            'digit_width' => $widestForInfer,
            'display_pad' => $displayPad,
        ];
    }

    /**
     * @return array{0: string, 1: int, 2: int}|null Lowercase prefix, numeric value, digit character count
     */
    public static function parseNumericSuffix(?string $code): ?array
    {
        $c = trim((string) $code);
        if ($c === '') {
            return null;
        }

        $c = self::normalizeUnicodeDigits($c);

        // Copy-paste artifacts (PDF, Excel)
        $c = preg_replace('/\p{Cf}/u', '', $c);
        // "SE 1 40" (size/model + number) must not become SE140 — skip sequence math for multi-cluster codes.
        if (preg_match('/\d\p{Z}+\d/u', $c)) {
            return null;
        }
        // All unicode separator characters (includes NBSP, narrow NBSP, etc.)
        $compact = preg_replace('/\p{Z}+/u', '', $c);
        // BR-02, BR–02 (en dash), BR.02, BR_02 → BR02 (Word/Excel paste variants)
        $prev = null;
        while ($prev !== $compact) {
            $prev = $compact;
            $compact = preg_replace('/([^\d])[\/_.\p{Pd}]+(?=\d)/u', '$1', $compact);
        }

        if (! preg_match('/^(.*?)(\d+)$/u', $compact, $m)) {
            return null;
        }

        $prefix = strtolower((string) $m[1]);
        if ($prefix === '') {
            return null;
        }

        $digitStr = (string) $m[2];
        $digitWidth = strlen($digitStr);
        $num = (int) $digitStr;

        return [$prefix, $num, $digitWidth];
    }

    /**
     * Fold compatibility/fullwidth digits so BR０1 and BR 01 share prefix "br" and value 1.
     */
    private static function normalizeUnicodeDigits(string $code): string
    {
        if (class_exists(\Normalizer::class)) {
            $n = \Normalizer::normalize($code, \Normalizer::FORM_KC);
            if (is_string($n) && $n !== '') {
                $code = $n;
            }
        }

        return preg_replace_callback('/[\x{FF10}-\x{FF19}]/u', static function (array $m): string {
            $ord = mb_ord($m[0], 'UTF-8');

            return (string) ($ord - 0xFF10);
        }, $code);
    }

    /**
     * Pad skipped numbers and span so values under 100 show as 02, 03, … while respecting wider runs.
     */
    private static function inferDisplayPad(int $digitWidth, int $prefixMaxDigitWidth, int $min, int $max): int
    {
        $fromStored = max($digitWidth, $prefixMaxDigitWidth);
        $fromRange = max(strlen((string) $min), strlen((string) $max));
        $under100Min = ($max < 100) ? 2 : $fromRange;

        return max($fromStored, $fromRange, $under100Min);
    }

    private static function formatPadded(int $value, int $digitWidth): string
    {
        return '#'.str_pad((string) $value, $digitWidth, '0', STR_PAD_LEFT);
    }

    /**
     * @param  list<int>  $skipped
     */
    private static function formatSkippedList(array $skipped, int $digitWidth): string
    {
        return implode(', ', array_map(
            fn (int $i) => str_pad((string) $i, $digitWidth, '0', STR_PAD_LEFT),
            $skipped
        ));
    }
}
