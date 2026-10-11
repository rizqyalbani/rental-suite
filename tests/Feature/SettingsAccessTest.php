<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\FonnteSettings;
use App\Support\StoreCache;
use App\Support\WhatsappLinkSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class SettingsAccessTest extends TestCase
{
    use RefreshDatabase;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    private function assertStoredSetting(string $key, mixed $value): void
    {
        $this->assertDatabaseHas('store_settings', [
            'store_id' => null,
            'key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
        ]);
    }

    private function assertStoredSettingMissing(string $key): void
    {
        $this->assertDatabaseMissing('store_settings', [
            'store_id' => null,
            'key' => $key,
        ]);
    }

    public function test_management_can_open_settings_page(): void
    {
        $management = $this->userWithRole('management');

        $this->actingAs($management)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSeeText('Pricing Visibility')
            ->assertSeeText('Tax (%)')
            ->assertSeeText('WhatsApp and external links')
            ->assertSeeText('Fonnte token')
            ->assertSeeText('Staff permissions');
    }

    public function test_admin_cannot_open_settings_page(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('settings.index'))
            ->assertForbidden();
    }

    public function test_management_can_toggle_pricing_setting(): void
    {
        $management = $this->userWithRole('management');
        StoreCache::forever('pricing_enabled', true);
        StoreCache::forever('rental_return_buffer_days', 1);

        $this->actingAs($management)
            ->post(route('settings.pricing'), [
                // checkbox intentionally omitted => false
                'rental_return_buffer_days' => 1,
                'pricing_tax_percent' => 0,
                'overdue_fine_per_day' => 100000,
                'invoice_signed_url_days' => 0,
            ])
            ->assertRedirect(route('settings.index'));

        $this->assertFalse(StoreCache::get('pricing_enabled', true));
        $this->assertStoredSetting('pricing_enabled', false);
    }

    public function test_management_can_update_rental_return_buffer_days_setting(): void
    {
        $management = $this->userWithRole('management');
        StoreCache::forever('rental_return_buffer_days', 1);

        $this->actingAs($management)
            ->post(route('settings.pricing'), [
                'pricing_enabled' => '1',
                'rental_return_buffer_days' => 3,
                'pricing_tax_percent' => 5,
                'overdue_fine_per_day' => 100000,
                'invoice_signed_url_days' => 7,
            ])
            ->assertRedirect(route('settings.index'));

        $this->assertSame(3, (int) StoreCache::get('rental_return_buffer_days'));
        $this->assertSame(5.0, (float) StoreCache::get('pricing_tax_percent'));
        $this->assertSame(7, (int) StoreCache::get('invoice_signed_url_days'));
        $this->assertStoredSetting('rental_return_buffer_days', 3);
        $this->assertStoredSetting('pricing_tax_percent', 5);
        $this->assertStoredSetting('invoice_signed_url_days', 7);
    }

    public function test_management_can_save_whatsapp_public_base_url_and_it_is_used_for_link_settings(): void
    {
        $management = $this->userWithRole('management');
        StoreCache::forget('whatsapp_public_base_url');
        StoreCache::forget('invoice_signed_url_days');

        $this->actingAs($management)
            ->post(route('settings.pricing'), [
                'pricing_enabled' => '1',
                'rental_return_buffer_days' => 1,
                'pricing_tax_percent' => 0,
                'overdue_fine_per_day' => 100000,
                'invoice_signed_url_days' => 0,
                'whatsapp_public_base_url' => 'https://dev-tunnel.example.test/',
            ])
            ->assertRedirect(route('settings.index'));

        $this->assertSame('https://dev-tunnel.example.test', StoreCache::get('whatsapp_public_base_url'));
        $this->assertSame('https://dev-tunnel.example.test', WhatsappLinkSettings::publicBaseUrl());
        $this->assertStoredSetting('whatsapp_public_base_url', 'https://dev-tunnel.example.test');
    }

    public function test_management_can_clear_whatsapp_public_base_url_to_fall_back_to_env(): void
    {
        $management = $this->userWithRole('management');

        $this->actingAs($management)
            ->post(route('settings.pricing'), [
                'pricing_enabled' => '1',
                'rental_return_buffer_days' => 1,
                'pricing_tax_percent' => 0,
                'overdue_fine_per_day' => 100000,
                'invoice_signed_url_days' => 0,
                'whatsapp_public_base_url' => 'https://staging.example.test',
            ])
            ->assertRedirect(route('settings.index'));

        $this->actingAs($management)
            ->post(route('settings.pricing'), [
                'pricing_enabled' => '1',
                'rental_return_buffer_days' => 1,
                'pricing_tax_percent' => 0,
                'overdue_fine_per_day' => 100000,
                'invoice_signed_url_days' => 0,
                'whatsapp_public_base_url' => '',
            ])
            ->assertRedirect(route('settings.index'));

        $this->assertNull(StoreCache::get('whatsapp_public_base_url'));
        $this->assertStoredSettingMissing('whatsapp_public_base_url');
    }

    public function test_management_can_save_fonnte_token_and_resolver_prefers_it_over_env(): void
    {
        config()->set('services.fonnte.token', 'env-token');

        $management = $this->userWithRole('management');
        StoreCache::forget('fonnte_token');

        $this->actingAs($management)
            ->post(route('settings.pricing'), [
                'pricing_enabled' => '1',
                'rental_return_buffer_days' => 1,
                'pricing_tax_percent' => 0,
                'overdue_fine_per_day' => 100000,
                'invoice_signed_url_days' => 0,
                'fonnte_token' => 'saved-store-token',
            ])
            ->assertRedirect(route('settings.index'));

        $this->assertSame('saved-store-token', StoreCache::get('fonnte_token'));
        $this->assertSame('saved-store-token', FonnteSettings::token());
        $this->assertStoredSetting('fonnte_token', 'saved-store-token');
    }

    public function test_management_can_clear_fonnte_token_when_form_posts_empty_field(): void
    {
        config()->set('services.fonnte.token', 'env-token');

        $management = $this->userWithRole('management');
        StoreCache::forever('fonnte_token', 'temp-token');

        $this->actingAs($management)
            ->post(route('settings.pricing'), [
                'pricing_enabled' => '1',
                'rental_return_buffer_days' => 1,
                'pricing_tax_percent' => 0,
                'overdue_fine_per_day' => 100000,
                'invoice_signed_url_days' => 0,
                'fonnte_token' => '',
            ])
            ->assertRedirect(route('settings.index'));

        $this->assertNull(StoreCache::get('fonnte_token'));
        $this->assertSame('env-token', FonnteSettings::token());
        $this->assertStoredSettingMissing('fonnte_token');
    }

    public function test_management_receives_validation_error_for_oversized_fonnte_token(): void
    {
        $management = $this->userWithRole('management');

        $this->actingAs($management)
            ->post(route('settings.pricing'), [
                'pricing_enabled' => '1',
                'rental_return_buffer_days' => 1,
                'pricing_tax_percent' => 0,
                'overdue_fine_per_day' => 100000,
                'invoice_signed_url_days' => 0,
                'fonnte_token' => str_repeat('a', 501),
            ])
            ->assertSessionHasErrors(['fonnte_token']);
    }

    public function test_management_submit_without_fonnte_token_field_preserves_cached_token(): void
    {
        config()->set('services.fonnte.token', null);

        $management = $this->userWithRole('management');
        StoreCache::forever('fonnte_token', 'keep-me');

        $this->actingAs($management)
            ->post(route('settings.pricing'), [
                'pricing_enabled' => '1',
                'rental_return_buffer_days' => 1,
                'pricing_tax_percent' => 0,
                'overdue_fine_per_day' => 100000,
                'invoice_signed_url_days' => 0,
            ])
            ->assertRedirect(route('settings.index'));

        $this->assertSame('keep-me', FonnteSettings::token());
        $this->assertStoredSetting('fonnte_token', 'keep-me');
    }

    public function test_settings_save_clears_legacy_fonnte_endpoint_cache_key(): void
    {
        $management = $this->userWithRole('management');
        Cache::forever('fonnte_endpoint', 'https://legacy.example.test/send');

        $this->actingAs($management)
            ->post(route('settings.pricing'), [
                'pricing_enabled' => '1',
                'rental_return_buffer_days' => 1,
                'pricing_tax_percent' => 0,
                'overdue_fine_per_day' => 100000,
                'invoice_signed_url_days' => 0,
            ])
            ->assertRedirect(route('settings.index'));

        $this->assertNull(Cache::get('fonnte_endpoint'));
    }

    public function test_settings_page_shows_saved_fonnte_token_hint_without_exposing_full_token(): void
    {
        $management = $this->userWithRole('management');
        StoreCache::forever('fonnte_token', 'saved-store-token');

        $this->actingAs($management)
            ->get(route('settings.index'))
            ->assertOk()
            ->assertSeeText('Saved token:')
            ->assertSeeText('oken')
            ->assertDontSee('saved-store-token');
    }
}
