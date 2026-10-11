<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\Outfit;
use App\Models\User;
use App\Support\StockOpnamePin;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regressions from the independent review of the stock opname feature:
 * the PIN identity is bound to the store whose item page it was entered on,
 * a session only accepts its own store's outfits, photos do not cross stores,
 * and two staff cannot share a PIN.
 */
class StockOpnameSecurityTest extends TestCase
{
    use RefreshDatabase;

    private Store $a;

    private Store $b;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
        $this->a = Store::create(['name' => 'Store A', 'code' => 'store-a', 'is_active' => true]);
        $this->b = Store::create(['name' => 'Store B', 'code' => 'store-b', 'is_active' => true]);
    }

    protected function tearDown(): void
    {
        StoreContext::set(null);
        parent::tearDown();
    }

    private function outfit(Store $store, string $code, bool $tutorial = false): Outfit
    {
        return Outfit::withoutGlobalScopes()->create(['store_id' => $store->id, 'code' => $code, 'stock' => 1, 'is_tutorial' => $tutorial]);
    }

    private function openSession(Store $store, bool $practice = false): StockOpname
    {
        return StockOpname::withoutGlobalScopes()->create(['store_id' => $store->id, 'started_by_user_id' => User::factory()->create(['role' => 'supervisor'])->id, 'started_at' => now(), 'is_tutorial_practice' => $practice]);
    }

    private function pinUser(string $pin, string $role = 'supervisor'): User
    {
        $user = User::factory()->create(['role' => $role]);
        $user->stock_opname_pin = $pin;
        $user->save();

        return $user;
    }

    // ---- PIN identity is bound to one store -----------------------------

    public function test_a_pin_unlocked_for_store_a_cannot_mark_a_outfit_of_store_b(): void
    {
        $user = $this->pinUser('123456');
        $outfitA = $this->outfit($this->a, 'A-1');
        $outfitB = $this->outfit($this->b, 'B-1');
        $sessionA = $this->openSession($this->a);
        $sessionB = $this->openSession($this->b);

        $this->withSession([
            'stock_opname_pin_user_id' => $user->id,
            'stock_opname_pin_verified_at' => now()->timestamp,
            'stock_opname_pin_store_id' => $this->a->id,
        ]);

        // Its own store works.
        $this->post(route('outfits.opname-mark', $outfitA), ['status' => 'ok'])->assertRedirect();
        $this->assertSame(1, StockOpnameItem::where('stock_opname_id', $sessionA->id)->count());

        // Another store's outfit id in the URL is refused, and nothing is written to that session.
        $this->post(route('outfits.opname-mark', $outfitB), ['status' => 'ok'])->assertForbidden();
        $this->assertSame(0, StockOpnameItem::where('stock_opname_id', $sessionB->id)->count());
    }

    public function test_a_tutorial_outfit_cannot_be_marked_into_a_real_session(): void
    {
        $user = $this->pinUser('123456');
        $practiceOutfit = $this->outfit($this->a, 'T-1', tutorial: true);
        $session = $this->openSession($this->a);

        $this->withSession([
            'stock_opname_pin_user_id' => $user->id,
            'stock_opname_pin_verified_at' => now()->timestamp,
            'stock_opname_pin_store_id' => $this->a->id,
        ]);

        $this->post(route('outfits.opname-mark', $practiceOutfit), ['status' => 'ok']);

        $this->assertSame(0, StockOpnameItem::where('stock_opname_id', $session->id)->count());
    }

    public function test_rusak_from_the_qr_page_needs_an_existing_photo_and_keeps_notes(): void
    {
        $user = $this->pinUser('123456');
        $outfit = $this->outfit($this->a, 'A-2');
        $session = $this->openSession($this->a);
        $this->withSession([
            'stock_opname_pin_user_id' => $user->id,
            'stock_opname_pin_verified_at' => now()->timestamp,
            'stock_opname_pin_store_id' => $this->a->id,
        ]);

        $this->post(route('outfits.opname-mark', $outfit), ['status' => 'damaged'])->assertSessionHasErrors('status');
        $this->assertSame(0, StockOpnameItem::where('stock_opname_id', $session->id)->count());

        // With a photo already attached (taken on the full opname page), re-marking keeps the note.
        StockOpnameItem::create(['stock_opname_id' => $session->id, 'outfit_id' => $outfit->id, 'status' => 'damaged', 'note' => 'torn', 'photo_path' => 'stock-opname/x.jpg', 'marked_by_user_id' => $user->id]);
        $this->post(route('outfits.opname-mark', $outfit), ['status' => 'damaged'])->assertSessionHasNoErrors();
        $this->assertSame('torn', StockOpnameItem::first()->note);
    }

    public function test_a_tutorial_user_cannot_mark_a_real_session_through_the_pin_page(): void
    {
        $tutorialUser = $this->pinUser('000000', 'admin');
        $tutorialUser->forceFill(['is_tutorial' => true])->save();
        $outfit = $this->outfit($this->a, 'A-3');
        $session = $this->openSession($this->a);
        $this->withSession([
            'stock_opname_pin_user_id' => $tutorialUser->id,
            'stock_opname_pin_verified_at' => now()->timestamp,
            'stock_opname_pin_store_id' => $this->a->id,
        ]);

        $this->post(route('outfits.opname-mark', $outfit), ['status' => 'ok'])->assertNotFound();
        $this->assertSame(0, StockOpnameItem::where('stock_opname_id', $session->id)->count());
    }

    // ---- PIN entry ------------------------------------------------------

    public function test_the_wrong_pin_message_does_not_reveal_whether_a_pin_was_close(): void
    {
        $this->pinUser('123456', 'admin');
        $outfit = $this->outfit($this->a, 'A-4');

        $response = $this->post(route('outfits.opname-pin', $outfit), ['pin' => '999999']);

        // One wording for "wrong PIN" and "right PIN but outside the shop", so the
        // location error cannot be used to confirm a guessed PIN.
        $response->assertSessionHasErrors(['pin' => 'Wrong PIN, or you are not at the store location.']);
    }

    public function test_two_staff_cannot_share_a_pin(): void
    {
        $first = $this->pinUser('246810');
        $second = User::factory()->create(['role' => 'supervisor']);

        $this->assertTrue(StockOpnamePin::isTakenByAnother('246810', $second->id));
        $this->assertFalse(StockOpnamePin::isTakenByAnother('246810', $first->id));
        $this->assertFalse(StockOpnamePin::isTakenByAnother('135790', $second->id));

        $this->actingAs($second)
            ->put(route('profile.opname-pin.update'), ['stock_opname_pin' => '246810', 'stock_opname_pin_confirmation' => '246810'])
            ->assertSessionHasErrorsIn('updateOpnamePin', 'stock_opname_pin');
        $this->assertNull($second->fresh()->stock_opname_pin);
    }

    // ---- the full opname screen -----------------------------------------

    public function test_a_session_only_accepts_outfits_of_its_own_store(): void
    {
        $sup = User::factory()->create(['role' => 'supervisor']);
        $outfitA = $this->outfit($this->a, 'A-5');
        $outfitB = $this->outfit($this->b, 'B-5');
        $session = $this->openSession($this->a);

        $this->actingAs($sup)->withSession(['current_store_id' => $this->a->id]);
        $this->postJson(route('stock-opname.mark', $session), ['outfit_id' => $outfitA->id, 'status' => 'ok'])->assertOk();
        $this->postJson(route('stock-opname.mark', $session), ['outfit_id' => $outfitB->id, 'status' => 'ok'])->assertStatus(422);

        $this->assertSame(1, StockOpnameItem::where('stock_opname_id', $session->id)->count());
    }

    public function test_item_photos_do_not_cross_stores(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('stock-opname/1/p.jpg', 'x');
        $staffA = User::factory()->create(['role' => 'supervisor']);
        $staffA->stores()->attach($this->a->id);
        $staffB = User::factory()->create(['role' => 'supervisor']);
        $staffB->stores()->attach($this->b->id);
        $outfitB = $this->outfit($this->b, 'B-6');
        $sessionB = $this->openSession($this->b);
        $item = StockOpnameItem::create(['stock_opname_id' => $sessionB->id, 'outfit_id' => $outfitB->id, 'status' => 'damaged', 'photo_path' => 'stock-opname/1/p.jpg', 'marked_by_user_id' => $staffB->id]);

        // Store A's staff cannot read store B's photo by guessing the id...
        $this->actingAs($staffA)->get(route('stock-opname.item-photo', $item))->assertNotFound();
        // ...while store B's own staff can.
        $this->actingAs($staffB)->get(route('stock-opname.item-photo', $item))->assertOk();
    }
    // ---- follow-ups from the same review ------------------------------------

    private function completeAs(User $user, StockOpname $session)
    {
        return $this->actingAs($user)->withSession(['current_store_id' => $session->store_id])
            ->post(route('stock-opname.complete', $session), ['notes' => 'done']);
    }

    private function supervisorFor(Store $store): User
    {
        $u = User::factory()->create(['role' => 'supervisor']);
        $u->stores()->attach($store->id);

        return $u;
    }

    public function test_a_finished_session_keeps_the_numbers_it_closed_with(): void
    {
        $sup = $this->supervisorFor($this->a);
        $s1 = $this->outfit($this->a, 'S-1');
        $s2 = $this->outfit($this->a, 'S-2');
        $session = $this->openSession($this->a);
        StockOpnameItem::create(['stock_opname_id' => $session->id, 'outfit_id' => $s1->id, 'status' => 'ok', 'marked_by_user_id' => $sup->id]);

        $this->completeAs($sup, $session)->assertRedirect();
        $snap = $session->fresh()->snapshot;
        $this->assertSame(2, $snap['total']);
        $this->assertSame(1, $snap['ok']);
        $this->assertSame(1, $snap['unchecked']);

        // Ten outfits are added afterwards: the old session must not start showing them as unchecked.
        for ($i = 0; $i < 10; $i++) {
            $this->outfit($this->a, 'NEW-'.$i);
        }
        $page = $this->actingAs($sup)->withSession(['current_store_id' => $this->a->id])->get(route('stock-opname.show', $session));
        $page->assertOk();
        $this->assertSame(1, $page->viewData('uncheckedCount'));
        $this->assertCount(2, $page->viewData('outfits'));
    }

    public function test_finishing_a_session_twice_does_not_change_who_finished_it(): void
    {
        $first = $this->supervisorFor($this->a);
        $second = $this->supervisorFor($this->a);
        $session = $this->openSession($this->a);

        $this->completeAs($first, $session)->assertRedirect();
        $this->completeAs($second, $session)->assertStatus(422);

        $this->assertSame($first->id, $session->fresh()->completed_by_user_id);
    }

    public function test_a_staff_account_that_marked_items_can_be_deleted(): void
    {
        $sup = $this->supervisorFor($this->a);
        $staff = $this->supervisorFor($this->a);
        $outfit = $this->outfit($this->a, 'D-1');
        $session = $this->openSession($this->a);
        StockOpnameItem::create(['stock_opname_id' => $session->id, 'outfit_id' => $outfit->id, 'status' => 'ok', 'marked_by_user_id' => $staff->id]);

        $staff->delete();

        $this->assertNull(StockOpnameItem::first()->marked_by_user_id);
        $this->assertSame(1, StockOpnameItem::count());
    }

    public function test_bulk_mark_never_offers_or_accepts_rusak(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $this->outfit($this->a, 'B-9');
        $session = $this->openSession($this->a);

        $this->actingAs($admin)->withSession(['current_store_id' => $this->a->id])
            ->post(route('stock-opname.bulk-mark', $session), ['status' => 'damaged'])
            ->assertSessionHasErrors('status');
        $this->assertSame(0, StockOpnameItem::count());
    }

    public function test_a_second_session_cannot_be_started_while_one_is_open(): void
    {
        $sup = $this->supervisorFor($this->a);

        $this->actingAs($sup)->withSession(['current_store_id' => $this->a->id])->post(route('stock-opname.store'));
        $this->actingAs($sup)->withSession(['current_store_id' => $this->a->id])->post(route('stock-opname.store'));

        $this->assertSame(1, StockOpname::withoutGlobalScopes()->where('store_id', $this->a->id)->count());
    }

    public function test_marking_the_same_outfit_again_updates_instead_of_failing(): void
    {
        $user = User::factory()->create(['role' => 'supervisor']);
        $outfit = $this->outfit($this->a, 'U-1');
        $session = $this->openSession($this->a);

        $one = StockOpnameItem::upsertSafely(['stock_opname_id' => $session->id, 'outfit_id' => $outfit->id], ['status' => 'ok', 'marked_by_user_id' => $user->id]);
        $two = StockOpnameItem::upsertSafely(['stock_opname_id' => $session->id, 'outfit_id' => $outfit->id], ['status' => 'missing', 'marked_by_user_id' => $user->id]);

        $this->assertSame($one->id, $two->id);
        $this->assertSame('missing', StockOpnameItem::first()->status);
        $this->assertSame(1, StockOpnameItem::count());
    }

    public function test_the_geofence_also_applies_to_a_user_with_no_store_assignment(): void
    {
        \Illuminate\Support\Facades\DB::table('store_settings')->insert([
            ['store_id' => $this->a->id, 'key' => 'geofence_enabled', 'value' => json_encode(true)],
            ['store_id' => $this->a->id, 'key' => 'geofence_latitude', 'value' => json_encode(-8.65)],
            ['store_id' => $this->a->id, 'key' => 'geofence_longitude', 'value' => json_encode(115.2)],
            ['store_id' => $this->a->id, 'key' => 'geofence_radius_m', 'value' => json_encode(100)],
        ]);
        $unassigned = User::factory()->create(['role' => 'admin']);

        $controller = new \App\Http\Controllers\Auth\AuthenticatedSessionController;
        $check = function (array $geo) use ($controller, $unassigned) {
            $m = new \ReflectionMethod($controller, 'passesStaffGeofence');
            $m->setAccessible(true);

            return $m->invoke($controller, $unassigned, \Illuminate\Http\Request::create('/login', 'POST', $geo));
        };

        $this->assertFalse($check([]));                                                   // no location sent
        $this->assertFalse($check(['geo_lat' => -6.2, 'geo_lng' => 106.8, 'geo_accuracy' => 20])); // far away
        $this->assertTrue($check(['geo_lat' => -8.65, 'geo_lng' => 115.2, 'geo_accuracy' => 20])); // at the shop
    }

    public function test_bulk_mark_skips_items_that_are_out_with_a_customer(): void
    {
        $admin = User::factory()->create(['role' => 'super_admin']);
        $onShelf = $this->outfit($this->a, 'S-1');
        $booked = $this->outfit($this->a, 'S-2');
        $out = $this->outfit($this->a, 'S-3');
        $bookedRental = \App\Models\Rental::withoutGlobalScopes()->create(['store_id' => $this->a->id, 'customer_name' => 'Booked', 'phone' => '1', 'start_date' => now()->addDays(9)->toDateString(), 'end_date' => now()->addDays(11)->toDateString(), 'status' => 'active']);
        $bookedRental->outfits()->attach($booked->id);
        $outRental = \App\Models\Rental::withoutGlobalScopes()->create(['store_id' => $this->a->id, 'customer_name' => 'Out', 'phone' => '1', 'start_date' => now()->subDay()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'status' => 'active', 'picked_up_at' => now()]);
        $outRental->outfits()->attach($out->id);
        $session = $this->openSession($this->a);

        $this->actingAs($admin)->withSession(['current_store_id' => $this->a->id])
            ->post(route('stock-opname.bulk-mark', $session), ['status' => 'missing'])
            ->assertSessionHas('success', fn ($m) => str_contains($m, '1 item(s) currently out with a customer were skipped'));

        $marked = StockOpnameItem::pluck('status', 'outfit_id');
        $this->assertSame('missing', $marked[$onShelf->id] ?? null);
        $this->assertSame('missing', $marked[$booked->id] ?? null, 'booked but still on the shelf is counted');
        $this->assertArrayNotHasKey($out->id, $marked->all(), 'out with a customer is left for a person to judge');
    }
}
