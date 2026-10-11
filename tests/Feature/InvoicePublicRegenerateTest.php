<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Rental;
use App\Models\Outfit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class InvoicePublicRegenerateTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_regenerate_is_accessible_without_auth_and_creates_new_invoice(): void
    {
        Storage::fake('local');
        Cache::forever('pricing_enabled', true);
        Cache::forever('pricing_tax_percent', 0);

        $outfit = Outfit::create([
            'code' => 'REGEN-001',
            'description' => 'Outfit',
            'price' => 100000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Customer',
            'phone' => '081200000077',
            'notes' => 'Address',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $rental->outfits()->attach($outfit->id);

        $signed = URL::temporarySignedRoute('invoices.regenerate', now()->addMinutes(5), [
            'rental' => $rental->id,
        ], absolute: false);

        $this->assertSame(0, Invoice::where('rental_id', $rental->id)->count());

        $res = $this->get($signed);
        $res->assertRedirect();

        $this->assertSame(1, Invoice::where('rental_id', $rental->id)->count());
    }
}
