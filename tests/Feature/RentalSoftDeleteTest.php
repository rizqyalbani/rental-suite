<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class RentalSoftDeleteTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private function makeRental(string $name = 'Trash Me'): Rental
    {
        $outfit = Outfit::create(['code' => 'SD-'.uniqid(), 'stock' => 1, 'price' => 100000]);
        $rental = Rental::create([
            'customer_name' => $name,
            'phone' => '081200000000',
            'notes' => 'X',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $rental->outfits()->attach($outfit->id);

        return $rental;
    }

    public function test_destroy_soft_deletes_and_hides_from_list(): void
    {
        $manager = User::factory()->create(['role' => 'management']);
        $rental = $this->makeRental();

        $this->actingAs($manager)->deleteJson(route('rentals.destroy', $rental))->assertOk();

        $this->assertSoftDeleted('rentals', ['id' => $rental->id]);
        $this->assertSame(0, Rental::count());
        $this->assertSame(1, Rental::onlyTrashed()->count());
    }

    public function test_deleted_list_requires_management(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->get(route('rentals.index', ['deleted' => 1]))->assertForbidden();
    }

    public function test_deleted_search_returns_only_trashed(): void
    {
        $manager = User::factory()->create(['role' => 'management']);
        $kept = $this->makeRental('Kept');
        $trashed = $this->makeRental('Trashed');
        $trashed->delete();

        $res = $this->actingAs($manager)->getJson(route('rentals.search', ['deleted' => 1]));
        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$trashed->id], $ids);

        $res = $this->actingAs($manager)->getJson(route('rentals.search'));
        $ids = collect($res->json('data'))->pluck('id')->all();
        $this->assertSame([$kept->id], $ids);
    }

    public function test_bulk_force_delete_removes_selected_trashed_only(): void
    {
        $manager = User::factory()->create(['role' => 'management']);
        $active = $this->makeRental('Active');
        $a = $this->makeRental('A');
        $b = $this->makeRental('B');
        $a->delete();
        $b->delete();

        $res = $this->actingAs($manager)->postJson(route('rentals.deleted.bulk-destroy'), [
            'ids' => [$a->id, $active->id],
        ]);

        $res->assertOk()->assertJsonPath('deleted', 1);
        $this->assertDatabaseMissing('rentals', ['id' => $a->id]);
        $this->assertSoftDeleted('rentals', ['id' => $b->id]);
        $this->assertDatabaseHas('rentals', ['id' => $active->id, 'deleted_at' => null]);
    }

    public function test_clear_deleted_removes_all_trashed(): void
    {
        $manager = User::factory()->create(['role' => 'management']);
        $active = $this->makeRental('Active');
        $a = $this->makeRental('A');
        $b = $this->makeRental('B');
        $a->delete();
        $b->delete();

        $res = $this->actingAs($manager)->postJson(route('rentals.deleted.clear'));

        $res->assertOk()->assertJsonPath('deleted', 2);
        $this->assertSame(0, Rental::onlyTrashed()->count());
        $this->assertDatabaseHas('rentals', ['id' => $active->id, 'deleted_at' => null]);
    }

    public function test_force_delete_endpoints_forbidden_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->postJson(route('rentals.deleted.clear'))->assertForbidden();
        $this->actingAs($admin)->postJson(route('rentals.deleted.bulk-destroy'), ['ids' => [1]])->assertForbidden();
    }
}
