<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class OutfitQrStatusTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_signed_item_status_page_is_publicly_accessible(): void
    {
        $outfit = Outfit::create([
            'code' => 'SPBL1',
            'stock' => 3,
        ]);

        $staff = User::factory()->create(['name' => 'Staff QR Demo', 'role' => 'admin']);

        $rental = Rental::create([
            'customer_name' => 'Alex',
            'phone' => '08123',
            'notes' => '-',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
            'handled_by_user_id' => $staff->id,
        ]);
        $rental->outfits()->attach($outfit->id);

        $url = URL::signedRoute('outfits.status', ['outfit' => $outfit], absolute: false);

        // The page is reachable without a login, but it is a gate: stock and rental
        // details only show for a staff identity (OutfitController::renderItemStatus()).
        $this->get($url)
            ->assertOk()
            ->assertSeeText('Log in first to see the info for this item.')
            ->assertDontSeeText('Alex')
            ->assertDontSeeText('08123')
            ->assertDontSeeText('Available Now');

        $response = $this->actingAs($staff)->get($url);

        $response->assertOk();
        $response->assertSeeText('SPBL1');
        $response->assertSeeText('Available Now');
        $response->assertSeeText('2');
        $response->assertSeeText('Alex');
        $response->assertSeeText('Handled by:');
        $response->assertSeeText('Staff QR Demo');
    }

    public function test_item_status_page_rejects_unsigned_request(): void
    {
        $outfit = Outfit::create([
            'code' => 'SPBL2',
            'stock' => 1,
        ]);

        $this->get(route('outfits.status', ['outfit' => $outfit], false))
            ->assertForbidden();
    }

    public function test_printed_qr_stable_link_shows_same_status_without_signature(): void
    {
        $outfit = Outfit::create([
            'code' => 'QR-STABLE',
            'stock' => 3,
        ]);

        $this->assertNotEmpty($outfit->fresh()->qr_token);

        $staff = User::factory()->create(['name' => 'Stable QR Staff', 'role' => 'admin']);

        $rental = Rental::create([
            'customer_name' => 'Guest',
            'phone' => '08123',
            'notes' => '-',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
            'handled_by_user_id' => $staff->id,
        ]);
        $rental->outfits()->attach($outfit->id);

        $url = route('outfits.scan', ['token' => $outfit->qr_token]);

        // Anonymous scan: only the staff gate, no rental details.
        $this->get($url)
            ->assertOk()
            ->assertSeeText('Log in first to see the info for this item.')
            ->assertDontSeeText('Guest');

        $response = $this->actingAs($staff)->get($url);

        $response->assertOk();
        $response->assertSeeText('QR-STABLE');
        $response->assertSeeText('Guest');
    }

    public function test_scan_redirects_when_qr_redirect_url_is_set(): void
    {
        $outfit = Outfit::create([
            'code' => 'QR-REDIR',
            'stock' => 1,
            'qr_redirect_url' => 'https://example.com/catalog/item',
        ]);

        $this->get(route('outfits.scan', ['token' => $outfit->qr_token]))
            ->assertRedirect('https://example.com/catalog/item');
    }

    public function test_scan_returns_404_for_unknown_token(): void
    {
        $this->get(route('outfits.scan', ['token' => str_repeat('z', 48)]))
            ->assertNotFound();
    }

    public function test_qr_png_download_requires_authentication(): void
    {
        $outfit = Outfit::create(['code' => 'NO-AUTH-QR', 'stock' => 1]);

        $this->get(route('outfits.qr-download', $outfit))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_qr_png_download_returns_attachment_named_after_item_code(): void
    {
        $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake([
            'api.qrserver.com/*' => Http::response($tinyPng, 200, ['Content-Type' => 'image/png']),
        ]);

        $user = User::factory()->create();
        $outfit = Outfit::create([
            'code' => 'SPBL-PRINT',
            'stock' => 1,
        ]);

        $response = $this->actingAs($user)->get(route('outfits.qr-download', $outfit));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');
        $disposition = $response->headers->get('Content-Disposition');
        $this->assertNotNull($disposition);
        $this->assertStringContainsString('attachment', $disposition);
        $this->assertStringContainsString('SPBL-PRINT.png', $disposition);
    }

    public function test_qr_export_zip_requires_authentication(): void
    {
        Outfit::create(['code' => 'ZIP-AUTH', 'stock' => 1]);

        $this->get(route('outfits.qr-export-zip'))
            ->assertRedirect(route('login', absolute: false));
    }

    public function test_qr_export_zip_redirects_when_inventory_empty(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('outfits.qr-export-zip'))
            ->assertRedirect(route('outfits.index'))
            ->assertSessionHas('error');
    }

    public function test_qr_export_zip_contains_one_png_per_item(): void
    {
        $tinyPng = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        Http::fake([
            'api.qrserver.com/*' => Http::response($tinyPng, 200, ['Content-Type' => 'image/png']),
        ]);

        $user = User::factory()->create();
        Outfit::create(['code' => 'ZIP-ONE', 'stock' => 1]);
        Outfit::create(['code' => 'ZIP-TWO', 'stock' => 1]);

        $response = $this->actingAs($user)->get(route('outfits.qr-export-zip'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/zip');
        $file = $response->getFile();
        $this->assertNotNull($file);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file->getPathname()) === true);
        $this->assertSame(2, $zip->numFiles);
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);
        $this->assertSame(['ZIP-ONE.png', 'ZIP-TWO.png'], $names);
    }
}
