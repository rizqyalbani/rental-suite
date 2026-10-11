<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\Outfit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class ManagerReportTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => $role])->save();

        return $user->fresh();
    }

    public function test_management_can_access_manager_report_dashboard(): void
    {
        $management = $this->userWithRole('management');

        $this->actingAs($management)
            ->get(route('reports.manager'))
            ->assertOk()
            ->assertSeeText('Manager Report Dashboard')
            ->assertSeeText('Transaction Report');
    }

    public function test_admin_cannot_access_manager_report_dashboard(): void
    {
        $admin = $this->userWithRole('admin');

        $this->actingAs($admin)
            ->get(route('reports.manager'))
            ->assertForbidden();
    }

    public function test_manager_report_shows_computed_net_revenue(): void
    {
        $management = $this->userWithRole('management');
        $operator = $this->userWithRole('admin');
        $outfit = Outfit::create([
            'code' => 'RP-001',
            'price' => 100000,
            'stock' => 1,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Revenue Customer',
            'phone' => '081234567890',
            'notes' => 'Branch A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'returned',
            'discount' => 10000,
            'discount_type' => 'fixed',
            'handled_by_user_id' => $operator->id,
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => 5000, 'discount_type' => 'fixed']);

        $this->actingAs($management)
            ->get(route('reports.manager', [
                'date_from' => now()->subDay()->toDateString(),
                'date_to' => now()->addDay()->toDateString(),
            ]))
            ->assertOk()
            ->assertSeeText('Rp 85.000')
            ->assertSeeText('Revenue Customer')
            ->assertSeeText($operator->name);
    }

    public function test_manager_report_supports_period_filters_and_per_admin_summary(): void
    {
        $management = $this->userWithRole('management');
        $adminA = $this->userWithRole('admin');
        $adminB = $this->userWithRole('admin');
        $outfit = Outfit::create(['code' => 'RP-002', 'price' => 100000, 'stock' => 2]);

        // The report attributes a rent to the day it was recorded (created_at), not to its
        // rental period (ManagerReportController::buildReportData()).
        Rental::unguarded(fn () => Rental::create([
            'customer_name' => 'Daily Match',
            'phone' => '081200000001',
            'notes' => 'Branch A',
            'start_date' => '2026-05-09',
            'end_date' => '2026-05-10',
            'status' => 'returned',
            'handled_by_user_id' => $adminA->id,
            'created_at' => '2026-05-09 10:00:00',
            'updated_at' => '2026-05-09 10:00:00',
        ]))->outfits()->attach($outfit->id);

        Rental::unguarded(fn () => Rental::create([
            'customer_name' => 'Outside Day',
            'phone' => '081200000002',
            'notes' => 'Branch A',
            'start_date' => '2026-05-08',
            'end_date' => '2026-05-09',
            'status' => 'returned',
            'handled_by_user_id' => $adminB->id,
            'created_at' => '2026-05-08 10:00:00',
            'updated_at' => '2026-05-08 10:00:00',
        ]))->outfits()->attach($outfit->id);

        $this->actingAs($management)
            ->get(route('reports.manager', [
                'period' => 'daily',
                'day' => '2026-05-09',
            ]))
            ->assertOk()
            ->assertSeeText('Daily Match')
            ->assertDontSeeText('Outside Day')
            ->assertSeeText($adminA->name);
    }

    public function test_manager_report_pdf_download_returns_pdf(): void
    {
        $management = $this->userWithRole('management');
        Cache::forever('pricing_tax_percent', 10);
        $outfit = Outfit::create(['code' => 'RP-003', 'price' => 100000, 'stock' => 1]);
        $rental = Rental::create([
            'customer_name' => 'Pdf Customer',
            'phone' => '081200000003',
            'notes' => 'Branch A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'returned',
            'handled_by_user_id' => $management->id,
        ]);
        $rental->outfits()->attach($outfit->id);

        $response = $this->actingAs($management)
            ->get(route('reports.manager.download-pdf', [
                'period' => 'daily',
                'day' => now()->toDateString(),
            ]));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent() ?: '');
    }
}
