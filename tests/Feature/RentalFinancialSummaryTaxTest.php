<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Store;
use App\Models\Outfit;
use App\Support\RentalFinancialSummary;
use App\Support\StoreCache;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RentalFinancialSummaryTaxTest extends TestCase
{
    use RefreshDatabase;

    /**
     * StoreContext is a plain static (not cache/DB-backed), so it survives
     * RefreshDatabase's rollback — a prior test file's real HTTP request
     * could leave it pointing at a store id that no longer exists once that
     * test's transaction rolls back, which then makes StoreCache::forever()
     * (used below) violate its FK against the now-gone store. Same fix as
     * RentalRevenueRecorderTest/AccountingRoleAccessTest.
     */
    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
    }

    private function makeRental(): Rental
    {
        $store = Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);

        // 3 calendar days == exactly 1 block, so the unit price is charged
        // once (priceMultiplier = 1) — keeps the numbers in this test as
        // simple as the price itself, no day-multiplication to track.
        $rental = Rental::create([
            'store_id' => $store->id,
            'customer_name' => 'Tax Test Customer',
            'phone' => '081234567890',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(2)->toDateString(),
            'status' => 'active',
        ]);

        $outfit = Outfit::create(['code' => 'TAX-01', 'price' => 111000, 'stock' => 1]);
        $rental->outfits()->attach($outfit->id);

        return $rental->fresh(['outfits']);
    }

    public function test_tax_inclusive_price_is_unwrapped_not_multiplied(): void
    {
        StoreCache::forever('pricing_prices_include_tax', true);
        $rental = $this->makeRental();

        // Rp111.000 already includes 11% tax — the correct pre-tax base is
        // 111.000 / 1.11 = 100.000, and the tax slice is 11.000. The old bug
        // multiplied 111.000 * 0.11 = 12.210, taxing the tax itself.
        $result = RentalFinancialSummary::summarize($rental, 11.0);

        $this->assertEqualsWithDelta(100000.0, $result['pre_tax_net'], 0.5);
        $this->assertEqualsWithDelta(11000.0, $result['tax_amount'], 0.5);
        $this->assertEqualsWithDelta(111000.0, $result['net_incl_tax'], 0.5);

        // The defining property of tax-inclusive math: base + tax must add
        // back up to exactly the original inclusive price — this is the
        // check that catches the double-taxing bug outright.
        $this->assertEqualsWithDelta(
            $result['net_incl_tax'],
            $result['pre_tax_net'] + $result['tax_amount'],
            0.5
        );
    }

    public function test_tax_exclusive_price_is_unaffected_by_the_fix(): void
    {
        StoreCache::forever('pricing_prices_include_tax', false);
        $rental = $this->makeRental();

        // Rp111.000 is the pre-tax price here — tax is added ON TOP, so this
        // branch's math (unchanged by the fix) should still give a flat 11%.
        $result = RentalFinancialSummary::summarize($rental, 11.0);

        $this->assertEqualsWithDelta(111000.0, $result['pre_tax_net'], 0.5);
        $this->assertEqualsWithDelta(12210.0, $result['tax_amount'], 0.5);
        $this->assertEqualsWithDelta(123210.0, $result['net_incl_tax'], 0.5);
    }

    public function test_zero_tax_percent_is_unaffected_either_way(): void
    {
        StoreCache::forever('pricing_prices_include_tax', true);
        $rental = $this->makeRental();

        $result = RentalFinancialSummary::summarize($rental, 0.0);

        $this->assertEqualsWithDelta(111000.0, $result['pre_tax_net'], 0.5);
        $this->assertEqualsWithDelta(0.0, $result['tax_amount'], 0.5);
        $this->assertEqualsWithDelta(111000.0, $result['net_incl_tax'], 0.5);
    }
}
