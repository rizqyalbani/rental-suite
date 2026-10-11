<?php

namespace App\Support;

use App\Models\Outfit;
use Illuminate\Support\Collection;

/**
 * Groups outfit rows that likely refer to the same physical code with different spelling
 * (spaces, leading zeros on numeric suffix), e.g. BR8, BR08, BR 08, BR 8.
 */
final class OutfitDuplicateCodeGroups
{
    /**
     * Stable key for grouping primary `code` values. Empty string means skip (blank code).
     */
    public static function groupKey(?string $code): string
    {
        $c = trim((string) $code);
        if ($c === '') {
            return '';
        }

        $compact = preg_replace('/\s+/u', '', $c);

        if (preg_match('/^(.*?)(\d+)$/u', $compact, $m)) {
            $prefix = strtolower((string) $m[1]);
            $num = (int) $m[2];

            return 'n:'.$prefix.'|'.$num;
        }

        return 's:'.strtolower($compact);
    }

    /**
     * Human label for the panel, e.g. "BR → #8" or "abc" for non-numeric codes.
     */
    public static function formatGroupLabel(string $groupKey): string
    {
        if (str_starts_with($groupKey, 'n:')) {
            $rest = substr($groupKey, 2);
            $parts = explode('|', $rest, 2);
            $prefix = $parts[0] ?? '';
            $num = $parts[1] ?? '';

            return strtoupper($prefix).' → #'.$num;
        }

        if (str_starts_with($groupKey, 's:')) {
            return substr($groupKey, 2);
        }

        return $groupKey;
    }

    /**
     * @return list<array{slug: string, label: string, variants_text: string, variants: list<string>, outfit_ids: list<int>}>
     */
    public static function panelsFromOutfits(Collection $outfits): array
    {
        /** @var array<string, Collection<int, Outfit>> $groups */
        $groups = [];
        foreach ($outfits as $outfit) {
            $k = self::groupKey($outfit->code ?? null);
            if ($k === '') {
                continue;
            }
            if (! isset($groups[$k])) {
                $groups[$k] = collect();
            }
            $groups[$k]->push($outfit);
        }

        $groups = array_filter($groups, fn (Collection $list) => $list->count() >= 2);

        ksort($groups);

        $panels = [];
        $n = 0;
        foreach ($groups as $key => $list) {
            $variants = $list->pluck('code')->filter()->unique()->sort()->values()->all();
            $panels[] = [
                'slug' => 'dg'.$n++,
                'label' => self::formatGroupLabel($key),
                'variants' => $variants,
                'variants_text' => implode("\n", $variants),
                'outfit_ids' => $list->pluck('id')->unique()->sort()->values()->all(),
            ];
        }

        return $panels;
    }

    /**
     * outfit id => space-separated slugs (for row data attributes).
     *
     * @param  list<array{slug: string, outfit_ids: list<int>}>  $panels
     * @return array<int, string>
     */
    public static function outfitIdToSlugMap(array $panels): array
    {
        $map = [];
        foreach ($panels as $panel) {
            foreach ($panel['outfit_ids'] as $id) {
                $id = (int) $id;
                $map[$id] = trim(($map[$id] ?? '').' '.$panel['slug']);
            }
        }

        foreach ($map as $id => $slugs) {
            $map[$id] = trim(preg_replace('/\s+/', ' ', $slugs));
        }

        return $map;
    }
}
