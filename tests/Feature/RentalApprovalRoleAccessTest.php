<?php

namespace Tests\Feature;

use App\Models\RentalApprovalRequest;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

/**
 * Regression coverage for a real bug: approvals.rentals.approve/reject were restricted
 * to role:super_admin,management, while the Edit Requests page that renders the
 * Approve/Reject buttons (approvals.edit-requests.index) allows supervisor too — so a
 * supervisor could see the buttons but every click 403'd. Route access here must match
 * who editRequestsIndex is actually shown to.
 */
class RentalApprovalRoleAccessTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private function makeCreateApproval(User $requester): RentalApprovalRequest
    {
        $outfit = Outfit::create(['code' => 'ROLE-ACCESS-01', 'stock' => 1, 'price' => 100000]);

        return RentalApprovalRequest::create([
            'requested_by_user_id' => $requester->id,
            'source_shift_session_id' => null,
            'status' => 'pending',
            'payload' => [
                'kind' => 'create',
                'rental' => [
                    'customer_name' => 'Role Access Customer',
                    'phone' => '080000000099',
                    'start_date' => now()->addDays(2)->toDateString(),
                    'end_date' => now()->addDays(4)->toDateString(),
                    'status' => 'active',
                    'customer_pending' => false,
                ],
                'outfit_ids' => [$outfit->id],
                'item_discounts' => [],
                'item_discount_types' => [],
            ],
        ]);
    }

    private function assertRoleCanReject(string $role): void
    {
        $requester = User::factory()->create(['role' => 'admin']);
        $decider = User::factory()->create(['role' => $role]);
        $approval = $this->makeCreateApproval($requester);

        $res = $this->actingAs($decider)->postJson(route('approvals.rentals.reject', $approval));

        $res->assertOk();
        $this->assertSame('rejected', $approval->fresh()->status, "role={$role} should be able to reject");
        $this->assertSame($decider->id, $approval->fresh()->decided_by_user_id);
    }

    public function test_super_admin_can_reject_a_pending_approval(): void
    {
        $this->assertRoleCanReject('super_admin');
    }

    public function test_management_can_reject_a_pending_approval(): void
    {
        $this->assertRoleCanReject('management');
    }

    public function test_supervisor_can_reject_a_pending_approval(): void
    {
        // This is the exact case that was broken: supervisor could see the Edit Requests
        // page and its Reject button, but the route itself 403'd every click.
        $this->assertRoleCanReject('supervisor');
    }

    public function test_admin_cannot_approve_or_reject(): void
    {
        $requester = User::factory()->create(['role' => 'admin']);
        $decider = User::factory()->create(['role' => 'admin']);
        $approval = $this->makeCreateApproval($requester);

        $this->actingAs($decider)->postJson(route('approvals.rentals.approve', $approval))->assertForbidden();
        $this->actingAs($decider)->postJson(route('approvals.rentals.reject', $approval))->assertForbidden();
        $this->assertSame('pending', $approval->fresh()->status);
    }

    /** Every role allowed to decide must also be allowed to see the page whose buttons post to these routes. */
    public function test_super_admin_can_view_edit_requests_page(): void
    {
        $user = User::factory()->create(['role' => 'super_admin']);
        $this->actingAs($user)->get(route('approvals.edit-requests.index'))->assertOk();
    }

    public function test_management_can_view_edit_requests_page(): void
    {
        $user = User::factory()->create(['role' => 'management']);
        $this->actingAs($user)->get(route('approvals.edit-requests.index'))->assertOk();
    }

    public function test_supervisor_can_view_edit_requests_page(): void
    {
        $user = User::factory()->create(['role' => 'supervisor']);
        $this->actingAs($user)->get(route('approvals.edit-requests.index'))->assertOk();
    }
}
