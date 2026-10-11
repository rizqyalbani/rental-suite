<?php

namespace Tests\Unit;

use App\Support\OutfitPrefixFormatOutliers;
use PHPUnit\Framework\TestCase;

class OutfitPrefixFormatOutliersTest extends TestCase
{
    public function test_format_template_separates_space_before_digits(): void
    {
        $this->assertSame('KBL #', OutfitPrefixFormatOutliers::formatTemplate('KBL 1'));
        $this->assertSame('KBL#', OutfitPrefixFormatOutliers::formatTemplate('KBL2'));
    }

    public function test_flags_minority_spacing_among_same_prefix(): void
    {
        $panels = OutfitPrefixFormatOutliers::panelsFromOutfits(collect([
            (object) ['code' => 'KBL 1'],
            (object) ['code' => 'KBL 3'],
            (object) ['code' => 'KBL 4'],
            (object) ['code' => 'KBL2'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame('KBL', $panels[0]['prefix_display']);
        $this->assertContains('KBL2', $panels[0]['outlier_codes']);
        $this->assertNotContains('KBL 1', $panels[0]['outlier_codes']);
    }

    public function test_single_template_emits_no_panel(): void
    {
        $panels = OutfitPrefixFormatOutliers::panelsFromOutfits(collect([
            (object) ['code' => 'KBL 1'],
            (object) ['code' => 'KBL 3'],
        ]));

        $this->assertSame([], $panels);
    }
}
