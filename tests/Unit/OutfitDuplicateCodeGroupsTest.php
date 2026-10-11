<?php

namespace Tests\Unit;

use App\Support\OutfitDuplicateCodeGroups;
use PHPUnit\Framework\TestCase;

class OutfitDuplicateCodeGroupsTest extends TestCase
{
    public function test_br_numeric_variants_share_group_key(): void
    {
        $keys = array_map(
            fn (string $c) => OutfitDuplicateCodeGroups::groupKey($c),
            ['BR8', 'BR08', 'BR 08', 'BR 8']
        );
        $this->assertSame($keys[0], $keys[1]);
        $this->assertSame($keys[0], $keys[2]);
        $this->assertSame($keys[0], $keys[3]);
    }

    public function test_different_numbers_are_separate_groups(): void
    {
        $a = OutfitDuplicateCodeGroups::groupKey('BR8');
        $b = OutfitDuplicateCodeGroups::groupKey('BR9');
        $this->assertNotSame($a, $b);
    }

    public function test_non_numeric_codes_use_compact_lowercase_key(): void
    {
        $this->assertSame(
            OutfitDuplicateCodeGroups::groupKey('AB CD'),
            OutfitDuplicateCodeGroups::groupKey('ABCD')
        );
    }

    public function test_format_group_label_for_numeric_suffix(): void
    {
        $k = OutfitDuplicateCodeGroups::groupKey('BR 08');
        $label = OutfitDuplicateCodeGroups::formatGroupLabel($k);
        $this->assertStringContainsString('BR', $label);
        $this->assertStringContainsString('#8', $label);
    }
}
