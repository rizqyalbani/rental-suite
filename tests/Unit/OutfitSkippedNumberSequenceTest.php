<?php

namespace Tests\Unit;

use App\Support\OutfitSkippedNumberSequence;
use PHPUnit\Framework\TestCase;

class OutfitSkippedNumberSequenceTest extends TestCase
{
    public function test_reports_gap_between_min_and_max_suffix_same_digit_width(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR1'],
            (object) ['code' => 'BR3'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame('BR', $panels[0]['prefix_display']);
        $this->assertSame('#01–#03', $panels[0]['span_label']);
        $this->assertSame([2], $panels[0]['skipped']);
        $this->assertSame('02', $panels[0]['skipped_text']);
        $this->assertSame(1, $panels[0]['digit_width']);
        $this->assertSame(2, $panels[0]['display_pad']);
    }

    public function test_mixed_br_space_01_and_br_3_still_finds_missing_02(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR 01'],
            (object) ['code' => 'BR 3'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame('BR', $panels[0]['prefix_display']);
        $this->assertSame([2], $panels[0]['skipped']);
        $this->assertSame('02', $panels[0]['skipped_text']);
    }

    public function test_hyphen_codes_merge_with_spaced_codes(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR-01'],
            (object) ['code' => 'BR 03'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame([2], $panels[0]['skipped']);
        $this->assertSame('02', $panels[0]['skipped_text']);
    }

    public function test_fullwidth_digit_in_suffix_merges_with_ascii_prefix(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR 01'],
            (object) ['code' => 'BR０3'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame('BR', $panels[0]['prefix_display']);
        $this->assertSame([2], $panels[0]['skipped']);
        $this->assertSame('02', $panels[0]['skipped_text']);
    }

    public function test_en_dash_between_prefix_and_digits_merges_with_spaced_codes(): void
    {
        $enDash = "\u{2013}";
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR'.$enDash.'01'],
            (object) ['code' => 'BR 03'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame([2], $panels[0]['skipped']);
        $this->assertSame('02', $panels[0]['skipped_text']);
    }

    public function test_period_between_prefix_and_digits_merges_with_spaced_codes(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR.01'],
            (object) ['code' => 'BR 03'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame([2], $panels[0]['skipped']);
        $this->assertSame('02', $panels[0]['skipped_text']);
    }

    public function test_two_digit_codes_show_missing_02_between_01_and_03(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR 01'],
            (object) ['code' => 'BR 03'],
            (object) ['code' => 'BR 04'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame('BR', $panels[0]['prefix_display']);
        $this->assertSame([2], $panels[0]['skipped']);
        $this->assertSame('02', $panels[0]['skipped_text']);
        $this->assertSame('#01–#04', $panels[0]['span_label']);
    }

    public function test_mixed_one_and_two_digit_merge_into_one_span(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR1'],
            (object) ['code' => 'BR2'],
            (object) ['code' => 'BR4'],
            (object) ['code' => 'BR10'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame('BR', $panels[0]['prefix_display']);
        $this->assertSame([3, 5, 6, 7, 8, 9], $panels[0]['skipped']);
        $this->assertSame('03, 05, 06, 07, 08, 09', $panels[0]['skipped_text']);
        $this->assertSame('#01–#10', $panels[0]['span_label']);
    }

    public function test_two_digit_prefix_span_uses_padding(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR08'],
            (object) ['code' => 'BR11'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame('BR', $panels[0]['prefix_display']);
        $this->assertSame('#08–#11', $panels[0]['span_label']);
        $this->assertSame([9, 10], $panels[0]['skipped']);
        $this->assertSame('09, 10', $panels[0]['skipped_text']);
        $this->assertSame(2, $panels[0]['display_pad']);
    }

    public function test_br08_br_space_8_and_br10_merge_eights(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR08'],
            (object) ['code' => 'BR 8'],
            (object) ['code' => 'BR10'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame('BR', $panels[0]['prefix_display']);
        $this->assertSame([9], $panels[0]['skipped']);
        $this->assertSame('09', $panels[0]['skipped_text']);
        $this->assertSame('#08–#10', $panels[0]['span_label']);
    }

    public function test_br8_with_br08_and_br10_single_gap(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR8'],
            (object) ['code' => 'BR08'],
            (object) ['code' => 'BR10'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame([9], $panels[0]['skipped']);
        $this->assertSame('09', $panels[0]['skipped_text']);
    }

    public function test_single_suffix_emits_no_panel(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR5'],
        ]));

        $this->assertSame([], $panels);
    }

    public function test_consecutive_suffixes_emits_no_panel(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'X1'],
            (object) ['code' => 'X2'],
            (object) ['code' => 'X3'],
        ]));

        $this->assertSame([], $panels);
    }

    public function test_non_numeric_suffix_codes_ignored(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'ABC'],
            (object) ['code' => 'ABD'],
        ]));

        $this->assertSame([], $panels);
    }

    /** "SE 1 40" is not suffix 140; must not stretch SE range to #140. */
    public function test_two_digit_clusters_with_space_not_merged_into_one_suffix(): void
    {
        $this->assertNull(OutfitSkippedNumberSequence::parseNumericSuffix('SE 1 40'));

        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'SE 001'],
            (object) ['code' => 'SE 025'],
            (object) ['code' => 'SE 1 40'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertStringNotContainsString('#140', $panels[0]['span_label']);
        $this->assertLessThanOrEqual(24, max($panels[0]['skipped']));
    }

    public function test_sequential_se_codes_still_work(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'SE 001'],
            (object) ['code' => 'SE 003'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame([2], $panels[0]['skipped']);
    }

    /** BR 04–BR 07 exist; they must never appear as missing alongside BR 3 / padded-line gap at 03. */
    public function test_does_not_flag_04_07_when_those_rows_exist(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR 01'],
            (object) ['code' => 'BR 02'],
            (object) ['code' => 'BR 3'],
            (object) ['code' => 'BR 04'],
            (object) ['code' => 'BR 05'],
            (object) ['code' => 'BR 06'],
            (object) ['code' => 'BR 07'],
            (object) ['code' => 'BR 08'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame([3], $panels[0]['skipped']);
    }

    /** BR 3 fills merged slot 3; 2-digit line 01,02,04 still missing 03 — same single BR panel. */
    public function test_two_digit_line_missing_03_when_br3_exists(): void
    {
        $panels = OutfitSkippedNumberSequence::panelsFromOutfits(collect([
            (object) ['code' => 'BR 01'],
            (object) ['code' => 'BR 02'],
            (object) ['code' => 'BR 04'],
            (object) ['code' => 'BR 3'],
        ]));

        $this->assertCount(1, $panels);
        $this->assertSame([3], $panels[0]['skipped']);
        $this->assertSame('03', $panels[0]['skipped_text']);
        $this->assertSame('#01–#04', $panels[0]['span_label']);
        $this->assertArrayNotHasKey('suffix_digit_width', $panels[0]);
    }
}
