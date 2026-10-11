<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalApprovalRequest;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Coverage for RentalApprovalRequest::diffAgainstSavedRental() — the shared "what does
 * this edit request actually change" helper extracted from two near-identical Blade
 * blocks (pending-rental-approval-cards.blade.php and rentals/index.blade.php). Both
 * views now depend on this method's output shape, so it needs its own direct tests
 * rather than only being exercised indirectly through view rendering.
 */
class RentalApprovalDiffTest extends TestCase
{
    use RefreshDatabase;

    private function makeRentalWithItem(): array
    {
        $outfit = Outfit::create(['code' => 'DIFF-01', 'stock' => 1, 'price' => 100000]);
        $rental = Rental::create([
            'customer_name' => 'Diff Customer',
            'phone' => '080000000041',
            'notes' => 'Original note',
            'start_date' => '2026-07-26',
            'end_date' => '2026-07-27',
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'down_payment',
            'amount_paid' => 5000,
            'discount' => null,
            'discount_type' => 'fixed',
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => null, 'discount_type' => 'fixed', 'discount_note' => null]);

        return [$rental, $outfit];
    }

    public function test_returns_no_changes_when_payload_matches_saved_rental_exactly(): void
    {
        [$rental, $outfit] = $this->makeRentalWithItem();
        $requester = User::factory()->create(['role' => 'admin']);
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $requester->id,
            'status' => 'pending',
            'payload' => [
                'kind' => 'edit',
                'rental_id' => $rental->id,
                'rental' => [
                    'customer_name' => 'Diff Customer',
                    'phone' => '080000000041',
                    'notes' => 'Original note',
                    'payment_method' => 'cash',
                    'payment_kind' => 'down_payment',
                    'amount_paid' => 5000,
                    'discount' => null,
                    'discount_type' => 'fixed',
                    'start_date' => '2026-07-26',
                    'end_date' => '2026-07-27',
                ],
                'outfit_ids' => [$outfit->id],
            ],
        ]);

        $diff = $approval->diffAgainstSavedRental($rental->fresh(), [$outfit->code]);

        $this->assertSame([], $diff['changed_field_keys']);
        $this->assertNull($diff['items']);
        $this->assertSame([], $diff['item_discounts']);
        foreach ($diff['fields'] as $field) {
            $this->assertFalse($field['changed'], $field['field'].' should not be flagged as changed');
        }
    }

    public function test_detects_a_changed_notes_field(): void
    {
        [$rental, $outfit] = $this->makeRentalWithItem();
        $requester = User::factory()->create(['role' => 'admin']);
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $requester->id,
            'status' => 'pending',
            'payload' => [
                'kind' => 'edit',
                'rental_id' => $rental->id,
                'rental' => [
                    'customer_name' => 'Diff Customer',
                    'notes' => 'A brand new note',
                    'start_date' => '2026-07-26',
                    'end_date' => '2026-07-27',
                ],
                'outfit_ids' => [$outfit->id],
            ],
        ]);

        $diff = $approval->diffAgainstSavedRental($rental->fresh(), [$outfit->code]);

        $this->assertSame(['notes'], $diff['changed_field_keys']);
        $this->assertSame('Original note', $diff['fields_by_key']['notes']['old']);
        $this->assertSame('A brand new note', $diff['fields_by_key']['notes']['new']);
    }

    public function test_treats_same_calendar_date_with_different_time_component_as_unchanged(): void
    {
        // Rental's date columns come back as Carbon instances (app timezone), while the
        // payload stores a plain "Y-m-d" string — these must compare as the same day.
        [$rental, $outfit] = $this->makeRentalWithItem();
        $requester = User::factory()->create(['role' => 'admin']);
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $requester->id,
            'status' => 'pending',
            'payload' => [
                'kind' => 'edit',
                'rental_id' => $rental->id,
                'rental' => [
                    'start_date' => '2026-07-26',
                    'end_date' => '2026-07-27',
                ],
                'outfit_ids' => [$outfit->id],
            ],
        ]);

        $diff = $approval->diffAgainstSavedRental($rental->fresh(), [$outfit->code]);

        $this->assertSame([], $diff['changed_field_keys']);
    }

    public function test_detects_amount_paid_change_ignoring_decimal_string_formatting(): void
    {
        // Saved amount_paid comes back as a "5000.00" decimal string; payload sends a
        // plain int/float — must compare numerically, not as strings.
        [$rental, $outfit] = $this->makeRentalWithItem();
        $requester = User::factory()->create(['role' => 'admin']);
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $requester->id,
            'status' => 'pending',
            'payload' => [
                'kind' => 'edit',
                'rental_id' => $rental->id,
                'rental' => ['amount_paid' => 5000],
                'outfit_ids' => [$outfit->id],
            ],
        ]);

        $diff = $approval->diffAgainstSavedRental($rental->fresh(), [$outfit->code]);
        $this->assertSame([], $diff['changed_field_keys'], 'amount_paid 5000 vs "5000.00" should be treated as equal');

        $approval->payload = array_replace_recursive($approval->payload, ['rental' => ['amount_paid' => 6000]]);
        $diff = $approval->diffAgainstSavedRental($rental->fresh(), [$outfit->code]);
        $this->assertSame(['amount_paid'], $diff['changed_field_keys']);
    }

    public function test_detects_item_discount_change_on_an_existing_item(): void
    {
        [$rental, $outfit] = $this->makeRentalWithItem();
        $requester = User::factory()->create(['role' => 'admin']);
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $requester->id,
            'status' => 'pending',
            'payload' => [
                'kind' => 'edit',
                'rental_id' => $rental->id,
                'rental' => [],
                'outfit_ids' => [$outfit->id],
                'item_discounts' => [$outfit->id => '10'],
                'item_discount_types' => [$outfit->id => 'percent'],
                'item_discount_notes' => [$outfit->id => 'loyalty discount'],
            ],
        ]);

        $diff = $approval->diffAgainstSavedRental($rental->fresh(), [$outfit->code]);

        $this->assertSame([$outfit->id], $diff['item_discount_outfit_ids']);
        $this->assertSame('No discount', $diff['item_discounts_by_outfit_id'][$outfit->id]['old']);
        $this->assertSame('10% ("loyalty discount")', $diff['item_discounts_by_outfit_id'][$outfit->id]['new']);
        $this->assertCount(1, $diff['item_discounts']);
        $this->assertSame('Discount on '.$outfit->code, $diff['item_discounts'][0]['label']);
    }

    public function test_detects_items_changed_when_outfit_ids_differ(): void
    {
        [$rental, $outfit] = $this->makeRentalWithItem();
        $newOutfit = Outfit::create(['code' => 'DIFF-02', 'stock' => 1, 'price' => 50000]);
        $requester = User::factory()->create(['role' => 'admin']);
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $requester->id,
            'status' => 'pending',
            'payload' => [
                'kind' => 'edit',
                'rental_id' => $rental->id,
                'rental' => [],
                'outfit_ids' => [$newOutfit->id],
            ],
        ]);

        $diff = $approval->diffAgainstSavedRental($rental->fresh(), [$newOutfit->code]);

        $this->assertNotNull($diff['items']);
        $this->assertTrue($diff['items']['changed']);
        $this->assertSame($outfit->code, $diff['items']['old']);
        $this->assertSame($newOutfit->code, $diff['items']['new']);
    }

    public function test_returns_empty_diff_when_saved_rental_is_null(): void
    {
        $requester = User::factory()->create(['role' => 'admin']);
        $approval = RentalApprovalRequest::create([
            'requested_by_user_id' => $requester->id,
            'status' => 'pending',
            'payload' => ['kind' => 'edit', 'rental_id' => 999999, 'rental' => [], 'outfit_ids' => []],
        ]);

        $diff = $approval->diffAgainstSavedRental(null);

        $this->assertSame([], $diff['fields']);
        $this->assertSame([], $diff['changed_field_keys']);
        $this->assertNull($diff['items']);
        $this->assertSame([], $diff['item_discounts']);
    }
}
