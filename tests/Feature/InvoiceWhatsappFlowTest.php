<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class InvoiceWhatsappFlowTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private function expectedTarget(string $phone): string
    {
        $target = preg_replace('/[^0-9+]/', '', $phone);
        if (str_starts_with($target, '0')) {
            $target = '62'.substr($target, 1);
        }
        if (str_starts_with($target, '+')) {
            $target = substr($target, 1);
        }

        return $target;
    }

    private function createRentalWithOutfit(string $phone = '081234567890'): Rental
    {
        $outfit = Outfit::create([
            'code' => 'INV-001',
            'description' => 'Invoice Outfit',
            'price' => 150000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Invoice Customer',
            'phone' => $phone,
            'instagram' => null,
            'notes' => 'Jakarta',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $rental->outfits()->attach($outfit->id);

        return $rental;
    }

    public function test_send_whatsapp_uses_jpg_when_available_and_marks_invoice_sent(): void
    {
        Storage::fake('local');
        Http::fake();

        config()->set('services.fonnte.token', 'test-token');
        config()->set('services.fonnte.endpoint', 'https://api.fonnte.com/send');
        config()->set('services.fonnte.media_mode', 'url');

        $user = User::factory()->create(['role' => 'admin']);
        $rental = $this->createRentalWithOutfit('081111111111');

        $invoice = Invoice::create([
            'rental_id' => $rental->id,
            'pdf_path' => "invoices/{$rental->id}/invoice.pdf",
            'png_path' => "invoices/{$rental->id}/invoice.jpg",
            'generated_at' => now(),
            'status' => 'generated',
        ]);

        Storage::disk('local')->put($invoice->pdf_path, 'fake-pdf');
        Storage::disk('local')->put($invoice->png_path, 'fake-jpg');

        $response = $this->actingAs($user)
            ->postJson(route('invoices.send-whatsapp', $rental));

        $response->assertOk()->assertJson(['success' => true, 'target' => $this->expectedTarget('081111111111')]);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.fonnte.com/send'
                && ($request->header('Authorization')[0] ?? null) === 'test-token'
                && ($data['target'] ?? null) === $this->expectedTarget('081111111111')
                && ($data['filename'] ?? null) === 'invoice-1.jpg'
                && str_contains((string) ($data['url'] ?? ''), 'invoice.jpg');
        });

        $this->assertSame('sent', $invoice->fresh()->status);
        $this->assertNotNull($invoice->fresh()->sent_at);
    }

    public function test_send_whatsapp_link_mode_sends_message_only_for_whatsapp_link_preview(): void
    {
        Storage::fake('local');
        Http::fake([
            'https://api.fonnte.com/send' => Http::response(['status' => true, 'message' => 'ok'], 200),
        ]);

        config()->set('services.fonnte.token', 'test-token');
        config()->set('services.fonnte.endpoint', 'https://api.fonnte.com/send');
        config()->set('services.fonnte.invoice_send_mode', 'link');

        $user = User::factory()->create(['role' => 'admin']);
        $rental = $this->createRentalWithOutfit('089998887776');

        Invoice::create([
            'rental_id' => $rental->id,
            'pdf_path' => "invoices/{$rental->id}/invoice.pdf",
            'png_path' => null,
            'generated_at' => now(),
            'status' => 'generated',
        ]);

        Storage::disk('local')->put("invoices/{$rental->id}/invoice.pdf", 'fake-pdf');

        $response = $this->actingAs($user)
            ->postJson(route('invoices.send-whatsapp', $rental));

        $response->assertOk()
            ->assertJson(['success' => true, 'send_mode' => 'link']);

        Http::assertSent(function ($request) {
            $data = $request->data();

            return $request->url() === 'https://api.fonnte.com/send'
                && ($data['target'] ?? null) === $this->expectedTarget('089998887776')
                && isset($data['message'])
                && ! isset($data['url'])
                && ! isset($data['filename']);
        });

        $this->assertSame('sent', Invoice::where('rental_id', $rental->id)->latest()->first()?->status);
    }

    public function test_invoice_show_page_exposes_og_image_for_link_preview(): void
    {
        Storage::fake('local');

        $rental = $this->createRentalWithOutfit();
        Invoice::create([
            'rental_id' => $rental->id,
            'pdf_path' => "invoices/{$rental->id}/invoice.pdf",
            'png_path' => "invoices/{$rental->id}/invoice.jpg",
            'generated_at' => now(),
            'status' => 'generated',
        ]);
        Storage::disk('local')->put("invoices/{$rental->id}/invoice.pdf", 'x');
        Storage::disk('local')->put("invoices/{$rental->id}/invoice.jpg", 'y');

        $signedUrl = URL::temporarySignedRoute('invoices.view', now()->addMinutes(5), [
            'rental' => $rental->id,
        ], absolute: false);

        $html = $this->get($signedUrl)->assertOk()->getContent();
        $this->assertStringContainsString('property="og:image"', $html);
        $this->assertStringContainsString('invoice.jpg', $html);
    }

    public function test_send_whatsapp_returns_validation_error_when_jpg_is_unavailable(): void
    {
        Storage::fake('local');
        Http::fake();

        config()->set('services.fonnte.token', 'test-token');
        config()->set('services.fonnte.endpoint', 'https://api.fonnte.com/send');
        config()->set('services.fonnte.media_mode', 'url');

        $user = User::factory()->create(['role' => 'admin']);
        $rental = $this->createRentalWithOutfit('082222222222');

        $invoice = Invoice::create([
            'rental_id' => $rental->id,
            'pdf_path' => "invoices/{$rental->id}/invoice.pdf",
            'png_path' => null,
            'generated_at' => now(),
            'status' => 'generated',
        ]);

        Storage::disk('local')->put($invoice->pdf_path, 'fake-pdf');

        $response = $this->actingAs($user)->postJson(route('invoices.send-whatsapp', $rental));

        $response->assertStatus(422);
        $response->assertJsonPath('error', 'Invoice image preview is not available. Please re-generate invoice and try again.');

        Http::assertNothingSent();
    }

    public function test_signed_invoice_download_is_accessible_without_auth(): void
    {
        Storage::fake('local');

        $rental = $this->createRentalWithOutfit('083333333333');
        Storage::disk('local')->put("invoices/{$rental->id}/invoice.jpg", 'fake-jpg-content');

        $signedUrl = URL::temporarySignedRoute('invoices.download', now()->addMinutes(5), [
            'rental' => $rental->id,
            'file' => 'invoice.jpg',
        ], absolute: false);

        $response = $this->get($signedUrl);

        $response->assertOk();
        $cache = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
    }

    public function test_signed_invoice_view_is_accessible_without_auth(): void
    {
        Storage::fake('local');

        $rental = $this->createRentalWithOutfit('084444444444');
        Invoice::create([
            'rental_id' => $rental->id,
            'pdf_path' => "invoices/{$rental->id}/invoice.pdf",
            'png_path' => "invoices/{$rental->id}/invoice.jpg",
            'generated_at' => now(),
            'status' => 'generated',
        ]);

        Storage::disk('local')->put("invoices/{$rental->id}/invoice.pdf", 'fake-pdf-content');
        Storage::disk('local')->put("invoices/{$rental->id}/invoice.jpg", 'fake-jpg-content');

        $signedUrl = URL::temporarySignedRoute('invoices.view', now()->addMinutes(5), [
            'rental' => $rental->id,
        ], absolute: false);

        $response = $this->get($signedUrl);

        $response->assertOk();
        // Commit 8b58134: the document is always titled "Rent Receipt" (no separate "Rent Invoice").
        $response->assertSee('Rent Receipt');
        $response->assertSee('Download image');
    }

    public function test_invoice_generate_returns_signed_urls(): void
    {
        Storage::fake('local');

        $user = User::factory()->create(['role' => 'admin']);
        $rental = $this->createRentalWithOutfit('086666666666');

        $response = $this->actingAs($user)
            ->postJson(route('invoices.generate', $rental));

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['signed_view_url', 'signed_url']);

        $this->assertDatabaseHas('invoices', [
            'rental_id' => $rental->id,
        ]);
    }

    public function test_template_preview_displays_pricing_breakdown_when_pricing_enabled(): void
    {
        Cache::forever('pricing_enabled', true);
        Cache::forever('pricing_tax_percent', 10);

        $rental = $this->createRentalWithOutfit('085555555555');
        $signedUrl = URL::temporarySignedRoute('invoices.template-preview', now()->addMinutes(5), [
            'rental' => $rental->id,
        ], absolute: false);

        $response = $this->get($signedUrl);
        $response->assertOk();
        $response->assertSee('Subtotal', false);
        $response->assertSee('after line discounts', false);
        // The receipt no longer prints "Total before tax" / "Tax (x%)" rows (removed in
        // commit 8b58134); the tax is only inside the grand total.
        $response->assertSee('Total due');
        $response->assertDontSee('Total before tax');
        $response->assertDontSee('Tax (', false);
        $response->assertDontSee('Total due (incl. tax)');
        $response->assertSee('Rp 150.000');
        $response->assertSee('Rp 165.000');
    }

    public function test_template_preview_hides_tax_row_when_tax_is_zero(): void
    {
        Cache::forever('pricing_enabled', true);
        Cache::forever('pricing_tax_percent', 0);

        $rental = $this->createRentalWithOutfit('085555555555');
        $signedUrl = URL::temporarySignedRoute('invoices.template-preview', now()->addMinutes(5), [
            'rental' => $rental->id,
        ], absolute: false);

        $response = $this->get($signedUrl);
        $response->assertOk();
        $response->assertSee('Total due');
        $response->assertDontSee('Total before tax');
        $response->assertDontSee('Tax (', false);
    }
}
