<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class RentalApprovalUiTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private function seedPendingApproval(): RentalApprovalRequest
    {
        $staff = User::factory()->create(['role' => 'admin']);
        $outfit = Outfit::create(['code' => 'OUTFIT-APPROVAL-UI', 'stock' => 1]);

        return RentalApprovalRequest::create([
            'requested_by_user_id' => $staff->id,
            'source_shift_session_id' => null,
            'payload' => [
                'rental' => [
                    'customer_name' => 'Approval UI Customer',
                    'phone' => '0811999888777',
                    'instagram' => null,
                    'notes' => 'Test Addr',
                    'start_date' => now()->addDay()->toDateString(),
                    'end_date' => now()->addDays(2)->toDateString(),
                ],
                'outfit_ids' => [$outfit->id],
                'item_discounts' => [],
                'item_discount_types' => [],
            ],
            'status' => 'pending',
        ]);
    }

    public function test_resolved_approval_focus_infers_outfits_not_on_saved_rent_for_quick_edit(): void
    {
        $s1 = Outfit::create(['code' => 'OUTFIT-FOCUS-A', 'stock' => 1]);
        $s2 = Outfit::create(['code' => 'OUTFIT-FOCUS-B', 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'R',
            'phone' => '081111111111',
            'instagram' => null,
            'notes' => 'X',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $rental->outfits()->attach($s1->id);

        $approval = new RentalApprovalRequest([
            'payload' => [
                'kind' => 'edit',
                'rental_id' => $rental->id,
                'outfit_ids' => [$s1->id, $s2->id],
                'rental' => [
                    'start_date' => now()->addDay()->toDateString(),
                    'end_date' => now()->addDays(2)->toDateString(),
                ],
            ],
        ]);

        $this->assertSame([$s2->id], $approval->resolvedApprovalFocusOutfitIds());
    }

    public function test_rentals_index_shows_inline_pending_queue_for_management(): void
    {
        $this->seedPendingApproval();
        $manager = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($manager)->get(route('rentals.index'));

        $response->assertOk();
        $response->assertSee('Pending cool-down approvals', false);
        $response->assertSee('Approval UI Customer', false);
        $response->assertSee('OUTFIT-APPROVAL-UI', false);
        $response->assertSee('id="manager-pending-approvals-inline"', false);
        $response->assertSee('approvals/rentals/', false);
    }

    public function test_queue_fragment_returns_json_for_management(): void
    {
        $this->seedPendingApproval();
        $manager = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($manager)
            ->getJson(route('approvals.rentals.queue-fragment'));

        $response->assertOk();
        $response->assertJsonStructure(['inline_html', 'dock_html', 'queue_total']);
        $this->assertNotNull($response->json('inline_html'));
        $this->assertNotNull($response->json('dock_html'));
        $this->assertSame(1, $response->json('queue_total'));
        $this->assertStringContainsString('manager-pending-approvals-inline', (string) $response->json('inline_html'));
    }

    public function test_queue_fragment_forbidden_for_staff(): void
    {
        $approval = $this->seedPendingApproval();
        $staff = User::findOrFail($approval->requested_by_user_id);

        $this->actingAs($staff)
            ->getJson(route('approvals.rentals.queue-fragment'))
            ->assertForbidden();
    }

    public function test_queue_fragment_returns_null_html_when_queue_empty(): void
    {
        $manager = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($manager)->getJson(route('approvals.rentals.queue-fragment'));

        $response->assertOk();
        $this->assertNull($response->json('inline_html'));
        $this->assertNull($response->json('dock_html'));
        $this->assertSame(0, $response->json('queue_total'));
    }

    public function test_rentals_index_places_approval_poll_anchor_above_shift_control_and_rental_cards(): void
    {
        $this->seedPendingApproval();
        $manager = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($manager)->get(route('rentals.index'));
        $response->assertOk();
        $html = $response->getContent();

        $posAnchor = strpos($html, 'id="rentals-approval-poll-anchor"');
        $posShiftControl = strpos($html, 'id="shiftControlHost"');
        $posRentalCards = strpos($html, 'id="rentalCards"');

        $this->assertNotFalse($posAnchor, 'Expected #rentals-approval-poll-anchor in Rentals HTML.');
        $this->assertNotFalse($posShiftControl);
        $this->assertNotFalse($posRentalCards);
        $response->assertSee('rentalsMgmtSnapshotWatcher = true', false);
    }

    public function test_rentals_index_sets_snapshot_watcher_flags_by_role(): void
    {
        $approval = $this->seedPendingApproval();
        $staff = User::findOrFail($approval->requested_by_user_id);
        $manager = User::factory()->create(['role' => 'management']);

        $staffHtml = $this->actingAs($staff)->get(route('rentals.index'))->assertOk()->getContent();
        $this->assertStringContainsString('rentalsMgmtSnapshotWatcher = false', $staffHtml);
        $this->assertStringContainsString('rentalsStaffApprovalWatcher = true', $staffHtml);

        $mgrHtml = $this->actingAs($manager)->get(route('rentals.index'))->assertOk()->getContent();
        $this->assertStringContainsString('rentalsMgmtSnapshotWatcher = true', $mgrHtml);
        $this->assertStringContainsString('rentalsStaffApprovalWatcher = false', $mgrHtml);
    }

    public function test_rentals_index_shows_awaiting_approval_flash_when_approval_request_sent(): void
    {
        $staff = User::factory()->create(['role' => 'admin']);

        $response = $this->actingAs($staff)
            ->withSession(['approval_request_sent' => true])
            ->get(route('rentals.index'));

        $response->assertOk();
        $response->assertSee('data-rentals-awaiting-approval-flash', false);
        $response->assertSee('id="rentals-approval-poll-anchor"', false);
        $response->assertDontSee('Next approval check', false);
    }

    public function test_rentals_index_hides_inline_queue_for_admin_but_shows_staff_summary(): void
    {
        $approval = $this->seedPendingApproval();
        $staff = User::findOrFail($approval->requested_by_user_id);

        $response = $this->actingAs($staff)->get(route('rentals.index'));

        $response->assertOk();
        $response->assertDontSee('id="manager-pending-approvals-inline"', false);
        $response->assertSee('Your pending manager approvals', false);
        $response->assertSee('Approval UI Customer', false);
        $response->assertSee('id="rentals-approval-poll-anchor"', false);
    }

    public function test_dashboard_shows_floating_dock_for_management_when_pending(): void
    {
        $this->seedPendingApproval();
        $manager = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($manager)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('id="manager-pending-approvals-dock"', false);
        $response->assertSee('OUTFIT-APPROVAL-UI', false);
    }

    public function test_rentals_index_does_not_render_duplicate_floating_dock(): void
    {
        $this->seedPendingApproval();
        $manager = User::factory()->create(['role' => 'management']);

        $response = $this->actingAs($manager)->get(route('rentals.index'));

        $response->assertOk();
        $response->assertDontSee('id="manager-pending-approvals-dock"', false);
    }

    public function test_management_role_display_variants_still_see_approval_queue(): void
    {
        $this->seedPendingApproval();

        foreach (['Management', 'manager', 'MANAGEMENT'] as $role) {
            $manager = User::factory()->create(['role' => $role]);
            $response = $this->actingAs($manager)->get(route('rentals.index'));
            $this->assertSame(200, $response->getStatusCode(), 'HTTP status for role variant: '.$role);
            $response->assertSee('Pending cool-down approvals', false);
        }
    }

    public function test_store_returns_json_for_boundary_approval_when_accept_json(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($admin);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-JSON-APR',
            // Priced so the nominal Rp 1 payment below stays within the total due
            // (RentalController::rejectIfPaymentExceedsTotalDue) — this test is
            // about the JSON shape of the response, not the payment amount.
            'price' => 1,
            'stock' => 1,
        ]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $response = $this->actingAs($admin)->postJson(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'JSON Approval',
            'phone' => '082222220000',
            'instagram' => null,
            'notes' => 'Surabaya',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '1',
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'mode' => 'approval_request_sent',
        ]);
        $response->assertJsonPath('pending_summary.customer_name', 'JSON Approval');
        $response->assertJsonPath('pending_summary.phone', '082222220000');
        $response->assertJsonFragment(['outfit_codes' => ['OUTFIT-JSON-APR']]);
        $this->assertIsInt($response->json('pending_summary.approval_id'));
    }

    public function test_approve_returns_json_when_accept_json(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($admin);

        $outfit = Outfit::create([
            'code' => 'OUTFIT-JSON-OK',
            // Priced so the nominal Rp 1 payment below stays within the total due
            // (RentalController::rejectIfPaymentExceedsTotalDue) — this test is
            // about the JSON shape of the response, not the payment amount.
            'price' => 1,
            'stock' => 1,
        ]);

        $existing = Rental::create([
            'customer_name' => 'Existing',
            'phone' => '080000000001',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $this->actingAs($admin)->postJson(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Approve JSON',
            'phone' => '080000000002',
            'instagram' => null,
            'notes' => 'Jakarta',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '1',
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ])->assertOk();

        $approval = RentalApprovalRequest::firstOrFail();

        $response = $this->actingAs($manager)->postJson(route('approvals.rentals.approve', $approval), [
            'decision_note' => 'ok',
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'mode' => 'approved',
        ]);
        $response->assertJsonPath('kind', 'create');
        $this->assertNotNull($response->json('rental_id'));
    }

    public function test_staff_can_queue_cooldown_approval_with_only_dates_and_items(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($admin);
        $outfit = Outfit::create(['code' => 'OUTFIT-MIN-APR', 'stock' => 1]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $start = now()->addDays(4)->toDateString();
        $end = now()->addDays(5)->toDateString();

        $response = $this->actingAs($admin)->postJson(route('rentals.cooldown-approval-request'), [
            'outfit_ids' => [$outfit->id],
            'start_date' => $start,
            'end_date' => $end,
        ]);

        $response->assertOk();
        $response->assertJsonPath('mode', 'approval_request_sent');
        $response->assertJsonPath('pending_summary.items_only', true);
        $response->assertJsonPath('pending_summary.customer_pending', true);
        $response->assertJsonFragment(['outfit_codes' => ['OUTFIT-MIN-APR']]);
        $response->assertJsonPath('pending_summary.approval_focus_outfit_codes', ['OUTFIT-MIN-APR']);
        $this->assertSame($outfit->id, $response->json('pending_summary.outfit_ids.0'));

        $approval = RentalApprovalRequest::firstOrFail();
        $this->assertTrue($approval->payload['rental']['customer_pending'] ?? false);
        $this->assertSame($approval->id, $response->json('pending_summary.approval_id'));
        $this->assertSame([$outfit->id], $approval->payload['approval_focus_outfit_ids'] ?? null);
    }

    public function test_create_cooldown_approval_requires_note_for_item_discount(): void
    {
        Cache::forever('rental_return_buffer_days', 1);
        Cache::forever('pricing_enabled', true);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($admin);
        $outfit = Outfit::create(['code' => 'OUTFIT-DSC-APR', 'stock' => 1, 'price' => 100000]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111998',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $base = [
            'outfit_ids' => [$outfit->id],
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'item_discounts' => [$outfit->id => '10000'],
            'item_discount_types' => [$outfit->id => 'fixed'],
        ];

        // Item discount without a note is rejected.
        $response = $this->actingAs($admin)->postJson(route('rentals.cooldown-approval-request'), $base);
        $response->assertStatus(422);
        $response->assertJsonPath('errors.item_discount_notes.0', 'Discount reason is required for each item discount.');
        $this->assertSame(0, RentalApprovalRequest::count());

        // Same request with a note is accepted.
        $response = $this->actingAs($admin)->postJson(route('rentals.cooldown-approval-request'), array_merge($base, [
            'item_discount_notes' => [$outfit->id => 'loyal customer'],
        ]));
        $response->assertOk();
        $response->assertJsonPath('mode', 'approval_request_sent');
        $this->assertSame(1, RentalApprovalRequest::count());
    }

    public function test_create_cooldown_approval_requires_note_for_order_discount(): void
    {
        Cache::forever('rental_return_buffer_days', 1);
        Cache::forever('pricing_enabled', true);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($admin);
        $outfit = Outfit::create(['code' => 'OUTFIT-ODSC-APR', 'stock' => 1, 'price' => 100000]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111997',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $response = $this->actingAs($admin)->postJson(route('rentals.cooldown-approval-request'), [
            'outfit_ids' => [$outfit->id],
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'discount' => '10000',
            'discount_note' => '',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.discount_note.0', 'Discount note is required when applying an order discount.');
        $this->assertSame(0, RentalApprovalRequest::count());
    }

    public function test_create_cooldown_approval_rejects_full_payment_below_total_due(): void
    {
        Cache::forever('rental_return_buffer_days', 1);
        Cache::forever('pricing_enabled', true);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($admin);
        $outfit = Outfit::create(['code' => 'OUTFIT-PAY-APR', 'stock' => 1, 'price' => 100000]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111996',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $base = [
            'outfit_ids' => [$outfit->id],
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'customer_name' => 'Cool Customer',
            'phone' => '081111111995',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
        ];

        // Full payment below the total due is rejected, same as a normal create.
        $response = $this->actingAs($admin)->postJson(route('rentals.cooldown-approval-request'), array_merge($base, [
            'amount_paid' => '50000',
        ]));
        $response->assertStatus(422);
        $this->assertStringContainsString('amount paid must be at least the total due', (string) $response->json('errors.amount_paid.0'));
        $this->assertSame(0, RentalApprovalRequest::count());

        // Covering payment is accepted and carried into the approval payload.
        $response = $this->actingAs($admin)->postJson(route('rentals.cooldown-approval-request'), array_merge($base, [
            'amount_paid' => '100000',
        ]));
        $response->assertOk();
        $response->assertJsonPath('mode', 'approval_request_sent');

        $approval = RentalApprovalRequest::firstOrFail();
        $this->assertSame('cash', $approval->payload['rental']['payment_method'] ?? null);
        $this->assertSame('full', $approval->payload['rental']['payment_kind'] ?? null);
        $this->assertEquals(100000.0, (float) ($approval->payload['rental']['amount_paid'] ?? 0));
    }

    public function test_create_cooldown_approval_focuses_only_boundary_outfits_when_cart_mixes_clear_and_cooldown(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $admin = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($admin);

        $outfitBoundary = Outfit::create(['code' => 'OUTFIT-B-FOCUS', 'stock' => 1]);
        $outfitClear = Outfit::create(['code' => 'OUTFIT-CLEAR-OK', 'stock' => 1]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfitBoundary->id);

        $start = now()->addDays(4)->toDateString();
        $end = now()->addDays(5)->toDateString();

        $response = $this->actingAs($admin)->postJson(route('rentals.cooldown-approval-request'), [
            'outfit_ids' => [$outfitBoundary->id, $outfitClear->id],
            'start_date' => $start,
            'end_date' => $end,
        ]);

        $response->assertOk();
        $response->assertJsonPath('pending_summary.approval_focus_outfit_codes', ['OUTFIT-B-FOCUS']);
        $this->assertEqualsCanonicalizing([$outfitBoundary->id, $outfitClear->id], $response->json('pending_summary.outfit_ids'));

        $approval = RentalApprovalRequest::firstOrFail();
        $this->assertSame([$outfitBoundary->id], $approval->payload['approval_focus_outfit_ids'] ?? null);
    }

    public function test_management_cannot_use_cooldown_approval_shortcut(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $manager = User::factory()->create(['role' => 'management']);
        $outfit = Outfit::create(['code' => 'OUTFIT-MGR-BLK', 'stock' => 1]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $this->actingAs($manager)
            ->postJson(route('rentals.cooldown-approval-request'), [
                'outfit_ids' => [$outfit->id],
                'start_date' => now()->addDays(4)->toDateString(),
                'end_date' => now()->addDays(5)->toDateString(),
            ])
            ->assertForbidden()
            ->assertJsonFragment(['ok' => false]);

        $this->assertDatabaseCount('rental_approval_requests', 0);
    }

    public function test_super_admin_cooldown_shortcut_endpoint_returns_422(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $super = User::factory()->create(['role' => 'super_admin']);
        $this->openShiftAs($super);
        $outfit = Outfit::create(['code' => 'OUTFIT-SA-SHORT', 'stock' => 1]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $this->actingAs($super)
            ->postJson(route('rentals.cooldown-approval-request'), [
                'outfit_ids' => [$outfit->id],
                'start_date' => now()->addDays(4)->toDateString(),
                'end_date' => now()->addDays(5)->toDateString(),
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'This shortcut is for staff. Managers can use Create Rental for cool-down bookings.']);

        $this->assertDatabaseCount('rental_approval_requests', 0);
    }

    public function test_super_admin_json_store_creates_rental_on_cooldown_boundary_without_approval_row(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $super = User::factory()->create(['role' => 'super_admin']);
        $this->openShiftAs($super);
        $outfit = Outfit::create([
            'code' => 'OUTFIT-SA-BOUND',
            // Priced so the nominal Rp 1 payment below stays within the total due
            // (RentalController::rejectIfPaymentExceedsTotalDue) — this test is
            // about the JSON shape of the response, not the payment amount.
            'price' => 1,
            'stock' => 1,
        ]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $response = $this->actingAs($super)->postJson(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Super Direct',
            'phone' => '082222220000',
            'instagram' => null,
            'notes' => 'Surabaya',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '1',
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'mode' => 'rental_created',
        ]);
        $this->assertDatabaseCount('rental_approval_requests', 0);
        $this->assertDatabaseCount('rentals', 2);
    }

    public function test_approve_minimal_cooldown_request_creates_rental_with_placeholders(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $admin = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($admin);

        $outfit = Outfit::create(['code' => 'OUTFIT-PLACE', 'stock' => 1]);

        $existing = Rental::create([
            'customer_name' => 'Existing',
            'phone' => '080000000001',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $this->actingAs($admin)->postJson(route('rentals.cooldown-approval-request'), [
            'outfit_ids' => [$outfit->id],
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
        ])->assertOk();

        $approval = RentalApprovalRequest::firstOrFail();

        $this->actingAs($manager)->postJson(route('approvals.rentals.approve', $approval))->assertOk();

        $rental = Rental::orderByDesc('id')->first();
        $this->assertNotNull($rental);
        $this->assertStringContainsString('Pending', $rental->customer_name);
        $this->assertSame('—', $rental->phone);
        $this->assertTrue($rental->outfits()->whereKey($outfit->id)->exists());
    }

    public function test_rentals_search_includes_pending_approval_rows_on_all_filter(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $staff = User::factory()->create(['role' => 'admin', 'name' => 'Staff List']);
        $this->openShiftAs($staff);
        $outfit = Outfit::create(['code' => 'OUTFIT-LIST-PEND', 'stock' => 1]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $this->actingAs($staff)->postJson(route('rentals.cooldown-approval-request'), [
            'outfit_ids' => [$outfit->id],
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ])->assertOk();

        $res = $this->actingAs($staff)->getJson(route('rentals.search', ['per_page' => 10]));
        $res->assertOk();
        $rows = $res->json('pending_approval_rows');
        $this->assertIsArray($rows);
        $this->assertCount(1, $rows);
        $this->assertSame('pending_approval', $rows[0]['row_kind']);
        $this->assertTrue($rows[0]['customer_pending']);
        $this->assertSame('OUTFIT-LIST-PEND', $rows[0]['outfit_codes'][0]);
        $this->assertSame('Staff List', $rows[0]['requested_by_name']);
    }

    public function test_rentals_search_omits_pending_rows_when_status_filter_set(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $staff = User::factory()->create(['role' => 'admin']);
        $this->openShiftAs($staff);
        $outfit = Outfit::create(['code' => 'OUTFIT-NO-PEND-ROW', 'stock' => 1]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $this->actingAs($staff)->postJson(route('rentals.cooldown-approval-request'), [
            'outfit_ids' => [$outfit->id],
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ])->assertOk();

        $res = $this->actingAs($staff)->getJson(route('rentals.search', [
            'per_page' => 10,
            'status' => 'active',
        ]));
        $res->assertOk();
        $this->assertSame([], $res->json('pending_approval_rows'));
    }

    public function test_management_sees_staff_pending_in_rentals_search(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $staff = User::factory()->create(['role' => 'admin', 'name' => 'Staff For Mgr']);
        $this->openShiftAs($staff);
        $outfit = Outfit::create(['code' => 'OUTFIT-MGR-SEE', 'stock' => 1]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $this->actingAs($staff)->postJson(route('rentals.cooldown-approval-request'), [
            'outfit_ids' => [$outfit->id],
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ])->assertOk();

        $manager = User::factory()->create(['role' => 'management']);
        $res = $this->actingAs($manager)->getJson(route('rentals.search', ['per_page' => 10]));
        $res->assertOk();
        $this->assertCount(1, $res->json('pending_approval_rows'));
        $this->assertSame('Staff For Mgr', $res->json('pending_approval_rows.0.requested_by_name'));
    }

    public function test_management_sees_staff_pending_create_approval_when_session_store_differs_from_request_store(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        Store::query()->firstOrCreate(['code' => 'store-1'], ['name' => 'Store 1', 'is_active' => true]);
        Store::query()->firstOrCreate(['code' => 'store-2'], ['name' => 'Store 2', 'is_active' => true]);

        $outfit = Outfit::create(['code' => 'OUTFIT-XSTORE', 'stock' => 1, 'store_id' => 2]);

        $existing = Rental::create([
            'store_id' => 2,
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $staff = User::factory()->create(['role' => 'admin', 'name' => 'Staff Store 2']);
        $staff->stores()->sync([2]);
        ShiftSession::create([
            'store_id' => 2,
            'opened_by_user_id' => $staff->id,
            'opened_at' => now(),
            'period' => ShiftSession::PERIOD_MORNING,
        ]);

        $this->actingAs($staff)
            ->withSession(['current_store_id' => 2])
            ->postJson(route('rentals.cooldown-approval-request'), [
                'outfit_ids' => [$outfit->id],
                'start_date' => now()->addDays(4)->toDateString(),
                'end_date' => now()->addDays(5)->toDateString(),
            ])
            ->assertOk();

        $manager = User::factory()->create(['role' => 'management']);
        $manager->stores()->sync([1, 2]);

        $res = $this->actingAs($manager)
            ->withSession(['current_store_id' => 1])
            ->getJson(route('rentals.search', ['per_page' => 10]));

        $res->assertOk();
        $this->assertCount(1, $res->json('pending_approval_rows'));
        $this->assertSame('Staff Store 2', $res->json('pending_approval_rows.0.requested_by_name'));
    }

    /**
     * Server contract used by the no-refresh UI: JSON approve, then /rentals/search matches what loadRentals would show.
     */
    public function test_after_json_approve_rentals_search_has_new_rental_and_no_pending_rows(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $staff = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($staff);

        $outfit = Outfit::create(['code' => 'OUTFIT-AJAX-FLOW', 'stock' => 1]);

        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        $this->actingAs($staff)->postJson(route('rentals.cooldown-approval-request'), [
            'outfit_ids' => [$outfit->id],
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
        ])->assertOk();

        $before = $this->actingAs($staff)->getJson(route('rentals.search', ['per_page' => 10]));
        $before->assertOk();
        $this->assertCount(1, $before->json('pending_approval_rows'));

        $approval = RentalApprovalRequest::firstOrFail();

        $this->actingAs($manager)
            ->postJson(route('approvals.rentals.approve', $approval), ['decision_note' => 'ok'])
            ->assertOk()
            ->assertJson(['ok' => true, 'mode' => 'approved']);

        $after = $this->actingAs($staff)->getJson(route('rentals.search', ['per_page' => 10]));
        $after->assertOk();
        $this->assertSame([], $after->json('pending_approval_rows'));

        $created = Rental::orderByDesc('id')->first();
        $this->assertNotNull($created);
        $this->assertTrue($created->outfits()->whereKey($outfit->id)->exists());

        $row = collect($after->json('data'))->firstWhere('id', $created->id);
        $this->assertNotNull($row, 'New rental should appear in search payload like loadRentals()');
        $this->assertContains('OUTFIT-AJAX-FLOW', $row['outfit_list'] ?? []);
    }

    public function test_pending_snapshot_returns_scoped_count_for_staff_and_total_for_manager(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $staffA = User::factory()->create(['role' => 'admin']);
        $staffB = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'management']);

        $outfit = Outfit::create(['code' => 'OUTFIT-SNAP', 'stock' => 1]);
        $existing = Rental::create([
            'customer_name' => 'Existing Customer',
            'phone' => '081111111999',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $existing->outfits()->attach($outfit->id);

        RentalApprovalRequest::create([
            'requested_by_user_id' => $staffA->id,
            'source_shift_session_id' => null,
            'status' => 'pending',
            'payload' => [
                'rental' => [
                    'customer_name' => '',
                    'phone' => '',
                    'instagram' => null,
                    'notes' => '',
                    'payment_method' => null,
                    'discount' => null,
                    'discount_type' => 'fixed',
                    'start_date' => now()->addDays(6)->toDateString(),
                    'end_date' => now()->addDays(7)->toDateString(),
                    'status' => 'active',
                    'handled_by_user_id' => $staffA->id,
                    'customer_pending' => true,
                ],
                'outfit_ids' => [$outfit->id],
                'item_discounts' => [],
                'item_discount_types' => [],
            ],
        ]);

        RentalApprovalRequest::create([
            'requested_by_user_id' => $staffB->id,
            'source_shift_session_id' => null,
            'status' => 'pending',
            'payload' => [
                'rental' => [
                    'customer_name' => '',
                    'phone' => '',
                    'instagram' => null,
                    'notes' => '',
                    'payment_method' => null,
                    'discount' => null,
                    'discount_type' => 'fixed',
                    'start_date' => now()->addDays(6)->toDateString(),
                    'end_date' => now()->addDays(7)->toDateString(),
                    'status' => 'active',
                    'handled_by_user_id' => $staffB->id,
                    'customer_pending' => true,
                ],
                'outfit_ids' => [$outfit->id],
                'item_discounts' => [],
                'item_discount_types' => [],
            ],
        ]);

        $staffRes = $this->actingAs($staffA)->getJson(route('approvals.rentals.pending-snapshot'));
        $staffRes->assertOk();
        $this->assertSame(1, $staffRes->json('count'));
        $staffRes->assertJsonStructure(['my_pending_ids']);
        $this->assertCount(1, $staffRes->json('my_pending_ids'));

        $mgrRes = $this->actingAs($manager)->getJson(route('approvals.rentals.pending-snapshot'));
        $mgrRes->assertOk();
        $this->assertSame(2, $mgrRes->json('count'));
        $this->assertNotNull($mgrRes->json('latest_id'));
        $mgrRes->assertJsonStructure(['my_pending_ids']);
    }

    public function test_staff_can_queue_quick_edit_cooldown_approval_and_manager_updates_rental(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $staff = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($staff);

        $outfit = Outfit::create(['code' => 'OUTFIT-QEDIT-APR', 'stock' => 1]);

        $blocker = Rental::create([
            'customer_name' => 'Other Customer',
            'phone' => '081000000001',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $blocker->outfits()->attach($outfit->id);

        $toEdit = Rental::create([
            'customer_name' => 'Edit Target',
            'phone' => '081000000002',
            'instagram' => null,
            'notes' => 'Jakarta',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 100,
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
        ]);
        $toEdit->outfits()->attach($outfit->id);

        $newStart = now()->addDays(4)->toDateString();
        $newEnd = now()->addDays(6)->toDateString();

        $queue = $this->actingAs($staff)->postJson(route('rentals.cooldown-approval-request'), [
            'rental_id' => $toEdit->id,
            'outfit_ids' => [$outfit->id],
            'approval_focus_outfit_ids' => [$outfit->id],
            'start_date' => $newStart,
            'end_date' => $newEnd,
            'customer_name' => 'Updated After Approval',
            'phone' => '081000000099',
            'instagram' => null,
            'notes' => 'Surabaya',
            'payment_method' => 'transfer',
            'payment_kind' => 'down_payment',
            'amount_paid' => '25000',
        ]);

        $queue->assertOk();
        $queue->assertJsonPath('mode', 'approval_request_sent');
        $queue->assertJsonPath('pending_summary.kind', 'edit');
        $queue->assertJsonPath('pending_summary.rental_id', $toEdit->id);
        $queue->assertJsonPath('pending_summary.approval_focus_outfit_codes', ['OUTFIT-QEDIT-APR']);

        $approval = RentalApprovalRequest::firstOrFail();
        $this->assertSame('edit', $approval->payload['kind'] ?? null);
        $this->assertSame($toEdit->id, (int) ($approval->payload['rental_id'] ?? 0));
        $this->assertSame($newEnd, $approval->payload['rental']['end_date'] ?? null);
        $this->assertSame([$outfit->id], $approval->payload['approval_focus_outfit_ids'] ?? null);

        $approve = $this->actingAs($manager)->postJson(route('approvals.rentals.approve', $approval), [
            'decision_note' => 'ok',
        ]);
        $approve->assertOk();
        $approve->assertJsonPath('mode', 'approved');
        $approve->assertJsonPath('kind', 'edit');
        $this->assertSame($toEdit->id, (int) $approve->json('rental_id'));

        $toEdit->refresh();
        $this->assertSame('Updated After Approval', $toEdit->customer_name);
        $this->assertSame($newEnd, $toEdit->end_date->toDateString());
        // Full-payment rents: payment fields are not changed via cool-down edit approval.
        $this->assertSame('cash', $toEdit->payment_method);
        // Original rent was full payment — staff cannot switch to down payment on edit; server keeps full.
        $this->assertSame('full', $toEdit->payment_kind);
    }

    public function test_reject_quick_edit_cooldown_request_returns_kind_and_rental_id_json(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $staff = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($staff);

        $outfit = Outfit::create(['code' => 'OUTFIT-QEDIT-REJ', 'stock' => 1]);

        $blocker = Rental::create([
            'customer_name' => 'Other Customer',
            'phone' => '081000000001',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $blocker->outfits()->attach($outfit->id);

        $toEdit = Rental::create([
            'customer_name' => 'Reject Target',
            'phone' => '081000000002',
            'instagram' => null,
            'notes' => 'Jakarta',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 100,
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
        ]);
        $toEdit->outfits()->attach($outfit->id);

        $newEnd = now()->addDays(6)->toDateString();

        $this->actingAs($staff)->postJson(route('rentals.cooldown-approval-request'), [
            'rental_id' => $toEdit->id,
            'outfit_ids' => [$outfit->id],
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => $newEnd,
            'customer_name' => 'Should Not Apply',
            'phone' => '081000000099',
            'instagram' => null,
            'notes' => 'Surabaya',
            'payment_method' => 'transfer',
            'payment_kind' => 'down_payment',
            'amount_paid' => '25000',
        ])->assertOk();

        $approval = RentalApprovalRequest::firstOrFail();

        $reject = $this->actingAs($manager)->postJson(route('approvals.rentals.reject', $approval), [
            'decision_note' => 'no',
        ]);
        $reject->assertOk();
        $reject->assertJsonPath('mode', 'rejected');
        $reject->assertJsonPath('kind', 'edit');
        $reject->assertJsonPath('rental_id', $toEdit->id);

        $toEdit->refresh();
        $this->assertSame('Reject Target', $toEdit->customer_name);
        $this->assertNotSame($newEnd, $toEdit->end_date->toDateString());
    }

    public function test_manager_rentals_index_shows_quick_edit_card_with_rent_period_and_items(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $staff = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($staff);

        $outfit = Outfit::create(['code' => 'OUTFIT-MGR-UI-CARD', 'stock' => 1]);

        $blocker = Rental::create([
            'customer_name' => 'Other Customer',
            'phone' => '081000000001',
            'instagram' => null,
            'notes' => 'Bandung',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $blocker->outfits()->attach($outfit->id);

        $toEdit = Rental::create([
            'customer_name' => 'Card Target',
            'phone' => '081000000002',
            'instagram' => null,
            'notes' => 'Jakarta',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 100,
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
        ]);
        $toEdit->outfits()->attach($outfit->id);

        $newStart = now()->addDays(4)->toDateString();
        $newEnd = now()->addDays(7)->toDateString();

        $this->actingAs($staff)->postJson(route('rentals.cooldown-approval-request'), [
            'rental_id' => $toEdit->id,
            'outfit_ids' => [$outfit->id],
            'approval_focus_outfit_ids' => [$outfit->id],
            'start_date' => $newStart,
            'end_date' => $newEnd,
            'customer_name' => 'Card Target Updated',
            'phone' => '081000000099',
            'instagram' => null,
            'notes' => 'Surabaya',
            'payment_method' => 'transfer',
            'payment_kind' => 'down_payment',
            'amount_paid' => '25000',
        ])->assertOk();

        $html = $this->actingAs($manager)->get(route('rentals.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Quick edit', $html);
        $this->assertStringContainsString('Rent #'.$toEdit->id, $html);
        $this->assertStringContainsString(Carbon::parse($newStart)->format('d M Y'), $html);
        $this->assertStringContainsString(Carbon::parse($newEnd)->format('d M Y'), $html);
        $this->assertStringNotContainsString('Saved rent (system now)', $html);
        $this->assertStringNotContainsString('Staff requested period', $html);
        $this->assertStringContainsString('OUTFIT-MGR-UI-CARD', $html);
        $this->assertStringContainsString('Needs approval', $html);
        $this->assertStringContainsString('Approve &amp; update rent', $html);
    }

    public function test_rentals_search_pending_row_includes_edit_kind_and_saved_date_labels(): void
    {
        Cache::forever('rental_return_buffer_days', 1);

        $staff = User::factory()->create(['role' => 'admin']);
        $manager = User::factory()->create(['role' => 'management']);
        $this->openShiftAs($staff);

        $outfit = Outfit::create(['code' => 'OUTFIT-PEND-JSON', 'stock' => 1]);

        $blocker = Rental::create([
            'customer_name' => 'Blocker',
            'phone' => '081000000001',
            'instagram' => null,
            'notes' => 'A',
            'start_date' => now()->addDays(1)->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'status' => 'active',
        ]);
        $blocker->outfits()->attach($outfit->id);

        $toEdit = Rental::create([
            'customer_name' => 'Json Target',
            'phone' => '081000000002',
            'instagram' => null,
            'notes' => 'B',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 50,
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(5)->toDateString(),
            'status' => 'active',
        ]);
        $toEdit->outfits()->attach($outfit->id);

        $this->actingAs($staff)->postJson(route('rentals.cooldown-approval-request'), [
            'rental_id' => $toEdit->id,
            'outfit_ids' => [$outfit->id],
            'start_date' => now()->addDays(4)->toDateString(),
            'end_date' => now()->addDays(6)->toDateString(),
            'customer_name' => 'Json Target',
            'phone' => '081000000002',
            'instagram' => null,
            'notes' => 'B',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '50',
        ])->assertOk();

        $res = $this->actingAs($manager)->getJson(route('rentals.search', ['per_page' => 10]));
        $res->assertOk();
        $rows = $res->json('pending_approval_rows');
        $this->assertCount(1, $rows);
        $this->assertSame('edit', $rows[0]['approval_kind']);
        $this->assertSame($toEdit->id, $rows[0]['target_rental_id']);
        $this->assertNotNull($rows[0]['saved_start_label']);
        $this->assertNotNull($rows[0]['saved_end_label']);
        $this->assertSame('OUTFIT-PEND-JSON', $rows[0]['outfit_codes'][0]);
    }
}
