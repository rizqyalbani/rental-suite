<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalFinancialSummary;
use App\Support\StoreCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class InvoiceGenerationOverdueChargeTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_generate_auto_applies_overdue_charge_when_fully_paid_and_not_returned(): void
    {
        Storage::fake('local');
        StoreCache::forever('pricing_enabled', true);
        StoreCache::forever('pricing_tax_percent', 0);

        $user = User::factory()->create(['role' => 'admin']);

        $outfit = Outfit::create([
            'code' => 'AUTO-CHARGE-1',
            'description' => 'Outfit',
            'price' => 100000,
            'stock' => 1,
        ]);

        $start = now()->subDays(3)->toDateString();
        $end = now()->subDay()->toDateString();

        $rental = Rental::create([
            'customer_name' => 'Customer',
            'phone' => '081200000099',
            'notes' => 'Address',
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 100000,
        ]);
        $rental->outfits()->attach($outfit->id);

        $before = RentalFinancialSummary::summarize($rental->fresh()->load('outfits'), 0);
        $this->assertSame(100000.0, (float) ($before['net_incl_tax'] ?? 0));

        $res = $this->actingAs($user)->postJson(route('invoices.generate', $rental));
        $res->assertOk()->assertJsonPath('success', true);

        $rental->refresh();
        $this->assertSame(100000.0, (float) $rental->overdue_fee_amount);
        $this->assertNotNull($rental->overdue_fee_applied_at);

        $after = RentalFinancialSummary::summarize($rental->fresh()->load('outfits'), 0);
        $this->assertSame(200000.0, (float) ($after['net_incl_tax'] ?? 0));
    }

    public function test_generate_regenerates_when_existing_image_is_broken(): void
    {
        Storage::fake('local');
        StoreCache::forever('pricing_enabled', true);
        StoreCache::forever('pricing_tax_percent', 0);

        $user = User::factory()->create(['role' => 'admin']);

        $outfit = Outfit::create([
            'code' => 'BROKEN-IMG-1',
            'description' => 'Outfit',
            'price' => 100000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Customer',
            'phone' => '081200000098',
            'notes' => 'Address',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $rental->outfits()->attach($outfit->id);

        $invoice = Invoice::create([
            'rental_id' => $rental->id,
            'pdf_path' => "invoices/{$rental->id}/invoice.pdf",
            'png_path' => "invoices/{$rental->id}/invoice.jpg",
            'generated_at' => now(),
            'status' => 'generated',
        ]);

        Storage::disk('local')->put($invoice->pdf_path, 'pdf');
        Storage::disk('local')->put($invoice->png_path, 'tiny');

        $this->assertSame(1, Invoice::where('rental_id', $rental->id)->count());

        $res = $this->actingAs($user)->postJson(route('invoices.generate', $rental));
        $res->assertOk()->assertJsonPath('success', true);

        $this->assertSame(2, Invoice::where('rental_id', $rental->id)->count());
    }
}
