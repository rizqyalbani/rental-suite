<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class ManagerReportMonthRangeFilterTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    /**
     * The manager report attributes a rent to the day it was recorded (created_at),
     * not to the day its rental period starts (ManagerReportController::buildReportData()).
     */
    private function rentalRecordedOn(string $createdAt, string $name, string $phone, string $start, string $end, Outfit $outfit, User $admin): Rental
    {
        $rental = Rental::unguarded(fn () => Rental::create([
            'customer_name' => $name,
            'phone' => $phone,
            'notes' => 'Branch A',
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'returned',
            'handled_by_user_id' => $admin->id,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]));
        $rental->outfits()->attach($outfit->id);

        return $rental;
    }

    public function test_manager_report_filters_by_month_range_multi_year(): void
    {
        $management = $this->userWithRole('management');
        $admin = $this->userWithRole('admin');
        $outfit = Outfit::create(['code' => 'MR-001', 'price' => 100000, 'stock' => 2]);

        $this->rentalRecordedOn('2026-01-15 10:00:00', 'Jan Rental', '081200000010', '2026-01-15', '2026-01-16', $outfit, $admin);

        $this->rentalRecordedOn('2026-02-05 10:00:00', 'Feb Rental', '081200000011', '2026-02-05', '2026-02-06', $outfit, $admin);

        $this->rentalRecordedOn('2026-03-01 10:00:00', 'Mar Rental', '081200000012', '2026-03-01', '2026-03-02', $outfit, $admin);

        $this->actingAs($management)
            ->get(route('reports.manager', [
                'month_from' => '2026-01',
                'month_to' => '2026-02',
            ]))
            ->assertOk()
            ->assertSeeText('Jan Rental')
            ->assertSeeText('Feb Rental')
            ->assertDontSeeText('Mar Rental');
    }

    public function test_month_range_swaps_when_from_is_after_to(): void
    {
        $management = $this->userWithRole('management');
        $admin = $this->userWithRole('admin');
        $outfit = Outfit::create(['code' => 'MR-002', 'price' => 100000, 'stock' => 2]);

        $this->rentalRecordedOn('2025-11-10 10:00:00', 'Nov Rental', '081200000020', '2025-11-10', '2025-11-11', $outfit, $admin);

        $this->rentalRecordedOn('2025-12-10 10:00:00', 'Dec Rental', '081200000021', '2025-12-10', '2025-12-11', $outfit, $admin);

        $this->actingAs($management)
            ->get(route('reports.manager', [
                'month_from' => '2025-12',
                'month_to' => '2025-11',
            ]))
            ->assertOk()
            ->assertSeeText('Nov Rental')
            ->assertSeeText('Dec Rental');
    }
}
