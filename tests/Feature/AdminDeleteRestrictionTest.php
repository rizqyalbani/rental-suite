<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\ShiftSession;
use App\Models\Outfit;
use App\Models\User;
use App\Support\StoreCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class AdminDeleteRestrictionTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    public function test_admin_cannot_delete_outfit_when_restriction_enabled(): void
    {
        StoreCache::forever('restrict_admin_deletes', true);
        $admin = $this->user('admin');
        $outfit = Outfit::create([
            'code' => 'ADM-DEL-BLOCK',
            'description' => 'x',
            'stock' => 1,
        ]);

        $this->actingAs($admin)->deleteJson(route('outfits.destroy', $outfit))
            ->assertForbidden()
            ->assertJsonFragment(['ok' => false]);
        $this->assertDatabaseHas('outfits', ['id' => $outfit->id]);
    }

    public function test_management_can_delete_outfit_when_restriction_enabled(): void
    {
        StoreCache::forever('restrict_admin_deletes', true);
        $management = $this->user('management');
        $outfit = Outfit::create([
            'code' => 'MGMT-DEL-OK',
            'description' => 'x',
            'stock' => 1,
        ]);

        $this->actingAs($management)->deleteJson(route('outfits.destroy', $outfit))
            ->assertOk()
            ->assertJson(['ok' => true]);
        $this->assertDatabaseMissing('outfits', ['id' => $outfit->id]);
    }

    public function test_admin_can_delete_outfit_when_restriction_disabled(): void
    {
        StoreCache::forever('restrict_admin_deletes', false);
        $admin = $this->user('admin');
        $outfit = Outfit::create([
            'code' => 'ADM-DEL-ALLOW',
            'description' => 'x',
            'stock' => 1,
        ]);

        $this->actingAs($admin)->deleteJson(route('outfits.destroy', $outfit))
            ->assertForbidden()
            ->assertJsonFragment(['ok' => false]);
        $this->assertDatabaseHas('outfits', ['id' => $outfit->id]);
    }

    public function test_admin_cannot_delete_rental_when_restriction_enabled(): void
    {
        StoreCache::forever('restrict_admin_deletes', true);
        $admin = $this->user('admin');
        ShiftSession::create([
            'opened_by_user_id' => $admin->id,
            'opened_at' => now(),
            'period' => ShiftSession::PERIOD_MORNING,
        ]);
        $outfit = Outfit::create(['code' => 'R-1', 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'C',
            'phone' => '081',
            'notes' => 'A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'cancelled',
            'handled_by_user_id' => $admin->id,
        ]);
        $rental->outfits()->attach($outfit->id);

        // Admin never deletes directly (docs/general-flow-2026-08-06.md §15): the click
        // queues a delete request for supervisor/management and the rent stays.
        $this->actingAs($admin)->deleteJson(route('rentals.destroy', $rental))
            ->assertOk()
            ->assertJson(['ok' => true, 'mode' => 'delete_approval_request_sent']);
        $this->assertNotSoftDeleted('rentals', ['id' => $rental->id]);
        $this->assertTrue(RentalApprovalRequest::query()
            ->where('status', 'pending')
            ->where('payload->kind', 'delete')
            ->where('payload->rental_id', $rental->id)
            ->exists());
    }

    public function test_admin_cannot_cancel_rental_when_restriction_enabled(): void
    {
        StoreCache::forever('restrict_admin_deletes', true);
        $admin = $this->user('admin');
        ShiftSession::create([
            'opened_by_user_id' => $admin->id,
            'opened_at' => now(),
            'period' => ShiftSession::PERIOD_MORNING,
        ]);
        $outfit = Outfit::create(['code' => 'ADM-CANCEL-BLOCK', 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'C',
            'phone' => '081',
            'notes' => 'A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
            'handled_by_user_id' => $admin->id,
        ]);
        $rental->outfits()->attach($outfit->id);

        $this->actingAs($admin)
            ->postJson(route('rentals.status', $rental), ['status' => 'cancelled'])
            ->assertForbidden();
        $this->assertDatabaseHas('rentals', ['id' => $rental->id, 'status' => 'active']);
    }

    public function test_admin_can_cancel_rental_when_restriction_disabled(): void
    {
        StoreCache::forever('restrict_admin_deletes', false);
        $admin = $this->user('admin');
        ShiftSession::create([
            'opened_by_user_id' => $admin->id,
            'opened_at' => now(),
            'period' => ShiftSession::PERIOD_MORNING,
        ]);
        $outfit = Outfit::create(['code' => 'ADM-CANCEL-OK', 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'C',
            'phone' => '081',
            'notes' => 'A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
            'handled_by_user_id' => $admin->id,
        ]);
        $rental->outfits()->attach($outfit->id);

        $this->actingAs($admin)
            ->postJson(route('rentals.status', $rental), ['status' => 'cancelled'])
            ->assertOk()
            ->assertJson(['ok' => true, 'status' => 'cancelled']);
        $this->assertDatabaseHas('rentals', ['id' => $rental->id, 'status' => 'cancelled']);
    }

    public function test_admin_cannot_delete_rental_when_rent_delete_restriction_enabled(): void
    {
        StoreCache::forever('restrict_admin_deletes', true);

        $admin = $this->user('admin');
        ShiftSession::create([
            'opened_by_user_id' => $admin->id,
            'opened_at' => now(),
            'period' => ShiftSession::PERIOD_MORNING,
        ]);
        $outfit = Outfit::create(['code' => 'R-3', 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'C',
            'phone' => '081',
            'notes' => 'A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'cancelled',
            'handled_by_user_id' => $admin->id,
        ]);
        $rental->outfits()->attach($outfit->id);

        // Admin never deletes directly (docs/general-flow-2026-08-06.md §15): the click
        // queues a delete request for supervisor/management and the rent stays.
        $this->actingAs($admin)->deleteJson(route('rentals.destroy', $rental))
            ->assertOk()
            ->assertJson(['ok' => true, 'mode' => 'delete_approval_request_sent']);
        $this->assertNotSoftDeleted('rentals', ['id' => $rental->id]);
        $this->assertTrue(RentalApprovalRequest::query()
            ->where('status', 'pending')
            ->where('payload->kind', 'delete')
            ->where('payload->rental_id', $rental->id)
            ->exists());
    }

    public function test_management_can_delete_rental_when_restriction_enabled(): void
    {
        StoreCache::forever('restrict_admin_deletes', true);
        $management = $this->user('management');
        ShiftSession::create([
            'opened_by_user_id' => $management->id,
            'opened_at' => now(),
            'period' => ShiftSession::PERIOD_MORNING,
        ]);
        $outfit = Outfit::create(['code' => 'R-2', 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'C',
            'phone' => '081',
            'notes' => 'A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'cancelled',
            'handled_by_user_id' => $management->id,
        ]);
        $rental->outfits()->attach($outfit->id);

        $this->actingAs($management)->deleteJson(route('rentals.destroy', $rental))
            ->assertOk()
            ->assertJson(['ok' => true]);
        $this->assertSoftDeleted('rentals', ['id' => $rental->id]);
    }

    public function test_management_can_enable_restriction_via_settings(): void
    {
        $management = $this->user('management');

        $this->actingAs($management)
            ->post(route('settings.pricing'), [
                'pricing_enabled' => '1',
                'rental_return_buffer_days' => 1,
                'pricing_tax_percent' => 0,
                'overdue_fine_per_day' => 100000,
                'invoice_signed_url_days' => 0,
                'restrict_admin_rent_transactions' => '1',
            ])
            ->assertRedirect(route('settings.index'));

        $this->assertTrue(StoreCache::get('restrict_admin_deletes', false));
        $this->assertNull(StoreCache::get('restrict_admin_rent_transactions'));
    }
}
