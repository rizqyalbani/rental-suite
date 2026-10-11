<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\RentalActivityLog;
use App\Models\RentalApprovalRequest;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class RentalApprovalCancelTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private function makeApproval(User $requester): RentalApprovalRequest
    {
        $outfit = Outfit::create(['code' => 'CANCEL-01', 'stock' => 1, 'price' => 100000]);

        return RentalApprovalRequest::create([
            'requested_by_user_id' => $requester->id,
            'source_shift_session_id' => null,
            'status' => 'pending',
            'payload' => [
                'kind' => 'create',
                'rental' => [
                    'customer_name' => '',
                    'phone' => '',
                    'start_date' => now()->addDays(2)->toDateString(),
                    'end_date' => now()->addDays(4)->toDateString(),
                    'status' => 'active',
                    'customer_pending' => true,
                ],
                'outfit_ids' => [$outfit->id],
            ],
        ]);
    }

    private function makeEditApproval(User $requester, Rental $rental): RentalApprovalRequest
    {
        return RentalApprovalRequest::create([
            'requested_by_user_id' => $requester->id,
            'source_shift_session_id' => null,
            'status' => 'pending',
            'payload' => [
                'kind' => 'edit',
                'rental_id' => $rental->id,
                'rental' => [
                    'customer_name' => $rental->customer_name,
                    'phone' => $rental->phone,
                    'notes' => 'Updated note',
                    'start_date' => $rental->start_date->toDateString(),
                    'end_date' => $rental->end_date->toDateString(),
                    'status' => 'active',
                    'customer_pending' => false,
                ],
                'outfit_ids' => $rental->outfits->pluck('id')->all(),
            ],
        ]);
    }

    public function test_requester_can_cancel_own_pending_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $approval = $this->makeApproval($admin);

        $res = $this->actingAs($admin)->postJson(route('approvals.rentals.cancel', $approval));

        $res->assertOk()->assertJsonPath('mode', 'cancelled');
        $this->assertSame('cancelled', $approval->fresh()->status);
    }

    public function test_other_user_cannot_cancel_the_request(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $other = User::factory()->create(['role' => 'admin']);
        $approval = $this->makeApproval($admin);

        $this->actingAs($other)->postJson(route('approvals.rentals.cancel', $approval))->assertForbidden();
        $this->assertSame('pending', $approval->fresh()->status);
    }

    public function test_processed_request_cannot_be_cancelled(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $approval = $this->makeApproval($admin);
        $approval->update(['status' => 'rejected']);

        $this->actingAs($admin)->postJson(route('approvals.rentals.cancel', $approval))->assertStatus(422);
        $this->assertSame('rejected', $approval->fresh()->status);
    }

    public function test_cancelling_an_edit_request_writes_an_activity_log_entry(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $outfit = Outfit::create(['code' => 'CANCEL-LOG-01', 'stock' => 1, 'price' => 100000]);
        $rental = Rental::create([
            'customer_name' => 'Cancel Log Customer',
            'phone' => '080000000021',
            'notes' => 'Original note',
            'start_date' => now()->addDays(2)->toDateString(),
            'end_date' => now()->addDays(4)->toDateString(),
            'status' => 'active',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 100000,
        ]);
        $rental->outfits()->attach($outfit->id);
        $approval = $this->makeEditApproval($admin, $rental);

        $this->assertDatabaseMissing('rental_activity_logs', [
            'rental_id' => $rental->id,
            'action' => 'edit_cancelled',
        ]);

        $res = $this->actingAs($admin)->postJson(route('approvals.rentals.cancel', $approval));

        $res->assertOk();
        $this->assertSame('cancelled', $approval->fresh()->status);
        $log = RentalActivityLog::where('rental_id', $rental->id)->where('action', 'edit_cancelled')->first();
        $this->assertNotNull($log, 'cancelling a pending edit request should be logged');
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Approval #'.$approval->id, $log->note);
    }

    public function test_cancelling_a_create_request_does_not_write_an_activity_log_entry(): void
    {
        // A 'create' (new cool-down booking) request has no rental yet to attach a log
        // entry to — same precedent as approve()/reject() for this kind.
        $admin = User::factory()->create(['role' => 'admin']);
        $approval = $this->makeApproval($admin);
        $beforeCount = RentalActivityLog::count();

        $this->actingAs($admin)->postJson(route('approvals.rentals.cancel', $approval))->assertOk();

        $this->assertSame($beforeCount, RentalActivityLog::count());
    }
}
