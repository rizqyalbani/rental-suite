<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Per letter-prefix (e.g. KBL), detects when most codes share one spelling pattern
 * (space before number, no space, padding, etc.) and lists codes that use a different pattern.
 *
 * Pattern key: original trim, split into (text before last digit run) + '#'.
 * Example: "KBL 1" → "KBL #", "KBL2" → "KBL#".
 */
final class OutfitPrefixFormatOutliers
{
    /**
     * @return list<array{slug: string, prefix_display: string, majority_example_code: string, majority_template: string, outlier_codes: list<string>, outlier_codes_text: string}>
     */
    public static function panelsFromOutfits(Collection $outfits): array
    {
        /** @var array<string, list<array{code: string, template: string}>> */
        $byPrefix = [];

        foreach ($outfits as $outfit) {
            $code = trim((string) ($outfit->code ?? ''));
            $parsed = OutfitSkippedNumberSequence::parseNumericSuffix($code);
            if ($parsed === null) {
                continue;
            }
            [$prefix] = $parsed;
            $template = self::formatTemplate($code);
            if ($template === null) {
                continue;
            }
            $byPrefix[$prefix][] = ['code' => $code, 'template' => $template];
        }

        $panels = [];
        $n = 0;
        ksort($byPrefix);

        foreach ($byPrefix as $prefix => $rows) {
            if (count($rows) < 2) {
                continue;
            }

            $tplCounts = [];
            foreach ($rows as $row) {
                $t = $row['template'];
                $tplCounts[$t] = ($tplCounts[$t] ?? 0) + 1;
            }

            if (count($tplCounts) < 2) {
                continue;
            }

            arsort($tplCounts);
            /** @var string $majorityTpl */
            $majorityTpl = array_key_first($tplCounts);

            $majorityExample = null;
            foreach ($rows as $row) {
                if ($row['template'] === $majorityTpl) {
                    $majorityExample = $row['code'];
                    break;
                }
            }

            $outliers = [];
            foreach ($rows as $row) {
                if ($row['template'] !== $majorityTpl) {
                    $outliers[] = $row['code'];
                }
            }

            if ($outliers === []) {
                continue;
            }

            sort($outliers, SORT_STRING);

            $maxOutlierPreview = 120;
            $outlierPreview = $outliers;
            $outlierMoreCount = 0;
            if (count($outlierPreview) > $maxOutlierPreview) {
                $outlierMoreCount = count($outlierPreview) - $maxOutlierPreview;
                $outlierPreview = array_slice($outlierPreview, 0, $maxOutlierPreview);
            }
            $outlierText = implode(', ', $outlierPreview);
            if ($outlierMoreCount > 0) {
                $outlierText .= ' + '.$outlierMoreCount.' more';
            }

            $prefixDisplay = strtoupper($prefix);

            $panels[] = [
                'slug' => 'fmt'.$n++,
                'prefix_display' => $prefixDisplay,
                'majority_example_code' => $majorityExample ?? '',
                'majority_template' => $majorityTpl,
                'outlier_codes' => $outliers,
                'outlier_codes_text' => $outlierText,
            ];
        }

        return $panels;
    }

    /**
     * Fingerprint for how the code is written: text before the trailing digit run, plus '#'.
     */
    public static function formatTemplate(string $code): ?string
    {
        $t = trim($code);
        if ($t === '' || ! preg_match('/^(.*?)(\d+)$/u', $t, $m)) {
            return null;
        }

        return $m[1].'#';
    }
}
