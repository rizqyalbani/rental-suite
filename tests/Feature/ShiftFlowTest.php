<?php

namespace Tests\Feature;

use App\Models\Rental;
use App\Models\ShiftSession;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalFinancialSummary;
use App\Support\StoreCache;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\URL;
use Tests\Support\SeedsDefaultStores;
use Tests\TestCase;

class ShiftFlowTest extends TestCase
{
    use RefreshDatabase, SeedsDefaultStores;

    public function test_admin_can_open_shift_and_create_rental_linked_to_shift(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();
        $outfit = Outfit::create([
            'code' => 'SHIFT-ITEM-1',
            'price' => 100000,
            'stock' => 1,
        ]);

        $this->actingAs($user)->post(route('shifts.open'), [
            'period' => ShiftSession::PERIOD_MORNING,
        ])->assertRedirect(route('rentals.index'));

        $shift = ShiftSession::where('opened_by_user_id', $user->id)->whereNull('closed_at')->first();
        $this->assertNotNull($shift);
        $this->assertSame(ShiftSession::PERIOD_MORNING, $shift->period);

        $this->actingAs($user)->post(route('rentals.store'), [
            'outfit_ids' => [$outfit->id],
            'customer_name' => 'Shift Customer',
            'phone' => '081111111111',
            'instagram' => null,
            'notes' => 'Branch A',
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => '100000',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDays(3)->toDateString(),
            'discount' => null,
            'discount_type' => 'fixed',
        ])->assertRedirect(route('rentals.index'));

        $rental = Rental::latest()->first();
        $this->assertSame((int) $shift->id, (int) $rental->shift_session_id);
    }

    public function test_close_shift_generates_report_metrics(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();
        $outfit = Outfit::create([
            'code' => 'SHIFT-ITEM-2',
            'price' => 100000,
            'stock' => 1,
        ]);
        $shift = ShiftSession::create([
            'opened_by_user_id' => $user->id,
            'opened_at' => now()->subHours(3),
            'period' => ShiftSession::PERIOD_AFTERNOON,
        ]);
        $rental = Rental::create([
            'customer_name' => 'Shift Customer 2',
            'phone' => '082222222222',
            'notes' => 'Badung',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'returned',
            'shift_session_id' => $shift->id,
            'handled_by_user_id' => $user->id,
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 85000,
            'discount' => 10000,
            'discount_type' => 'fixed',
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => 5000, 'discount_type' => 'fixed']);

        $this->actingAs($user)->post(route('shifts.close'))
            ->assertRedirect(route('rentals.index'))
            ->assertSessionHas('success');

        $this->actingAs($user)->get(route('rentals.index'))
            ->assertOk()
            ->assertSee('Latest closing report', false)
            ->assertSee('Download PDF', false);

        $closed = $shift->fresh();
        $this->assertNotNull($closed->closed_at);
        $this->assertSame(1, (int) $closed->total_transactions);
        $this->assertSame('85000.00', number_format((float) $closed->total_revenue, 2, '.', ''));
    }

    public function test_shift_report_pdf_download_returns_pdf(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();
        $shift = ShiftSession::create([
            'opened_by_user_id' => $user->id,
            'opened_at' => now()->subHours(2),
            'period' => ShiftSession::PERIOD_MORNING,
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
            'total_transactions' => 0,
            'total_revenue' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('shifts.report.download-pdf', $shift));
        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $response->getContent() ?: '');
    }

    public function test_signed_public_shift_report_pdf_requires_signature(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();
        $shift = ShiftSession::create([
            'opened_by_user_id' => $user->id,
            'opened_at' => now()->subHours(2),
            'period' => ShiftSession::PERIOD_MORNING,
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
            'total_transactions' => 0,
            'total_revenue' => 0,
        ]);

        $this->get(route('shifts.report.pdf', ['shift' => $shift->id]))
            ->assertForbidden();
    }

    public function test_signed_public_shift_report_pdf_works_when_closed(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();
        $shift = ShiftSession::create([
            'opened_by_user_id' => $user->id,
            'opened_at' => now()->subHours(2),
            'period' => ShiftSession::PERIOD_MORNING,
            'closed_at' => now(),
            'closed_by_user_id' => $user->id,
            'total_transactions' => 0,
            'total_revenue' => 0,
        ]);

        $url = URL::signedRoute('shifts.report.pdf', ['shift' => $shift->id], absolute: false);
        $this->get($url)->assertOk()->assertHeader('content-type', 'application/pdf');
    }

    public function test_open_shift_rejects_invalid_period(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();

        $this->actingAs($user)->from(route('rentals.index'))->post(route('shifts.open'), [
            'period' => 'night',
        ])->assertRedirect(route('rentals.index'))->assertSessionHasErrors('period');

        $this->assertSame(0, ShiftSession::count());
    }

    public function test_management_can_open_shift(): void
    {
        // Management has full transaction access since mayOperateRentTransactions() (see its
        // docblock in User.php); the shift control shows the Open Shift form to it too.
        $management = User::factory()->create(['role' => 'management']);

        $this->actingAs($management)->from(route('rentals.index'))->post(route('shifts.open'), [
            'period' => ShiftSession::PERIOD_MORNING,
        ])->assertRedirect(route('rentals.index'))->assertSessionHasNoErrors();

        $this->assertSame(1, ShiftSession::count());
        $this->assertSame($management->id, (int) ShiftSession::firstOrFail()->opened_by_user_id);
    }

    public function test_accounting_cannot_open_shift(): void
    {
        $accounting = User::factory()->create(['role' => 'accounting']);

        $this->actingAs($accounting)->from(route('rentals.index'))->post(route('shifts.open'), [
            'period' => ShiftSession::PERIOD_MORNING,
        ])->assertRedirect(route('rentals.index'))->assertSessionHas('error');

        $this->assertSame(0, ShiftSession::count());
    }

    public function test_shift_report_supports_period_filter_and_shows_per_admin_rows(): void
    {
        $management = User::factory()->create();
        $management->forceFill(['role' => 'management'])->save();
        $adminA = User::factory()->create();
        $adminA->forceFill(['role' => 'admin'])->save();
        $adminB = User::factory()->create();
        $adminB->forceFill(['role' => 'admin'])->save();
        Cache::forever('pricing_tax_percent', 10);

        $outfit = Outfit::create(['code' => 'SHIFT-FLT-1', 'price' => 100000, 'stock' => 2]);
        $shift = ShiftSession::create([
            'opened_by_user_id' => $adminA->id,
            'opened_at' => now()->subHours(3),
            'period' => ShiftSession::PERIOD_MORNING,
            'closed_at' => now(),
            'closed_by_user_id' => $adminA->id,
        ]);

        $inRange = Rental::create([
            'customer_name' => 'In Range',
            'phone' => '081300000001',
            'notes' => 'Branch A',
            'start_date' => '2026-06-09',
            'end_date' => '2026-06-10',
            'status' => 'returned',
            'shift_session_id' => $shift->id,
            'handled_by_user_id' => $adminA->id,
            'payment_method' => 'cash',
            'payment_kind' => 'full',
            'amount_paid' => 110000,
        ]);
        $inRange->forceFill(['created_at' => '2026-05-09 10:00:00', 'updated_at' => '2026-05-09 10:00:00'])->save();
        $inRange->outfits()->attach($outfit->id);

        $outRange = Rental::create([
            'customer_name' => 'Out Range',
            'phone' => '081300000002',
            'notes' => 'Branch A',
            'start_date' => '2026-05-08',
            'end_date' => '2026-05-09',
            'status' => 'returned',
            'shift_session_id' => $shift->id,
            'handled_by_user_id' => $adminB->id,
        ]);
        $outRange->forceFill(['created_at' => '2026-05-08 10:00:00', 'updated_at' => '2026-05-08 10:00:00'])->save();
        $outRange->outfits()->attach($outfit->id);

        $this->actingAs($management)
            ->get(route('shifts.report', [
                'shift' => $shift->id,
                'period' => 'daily',
                'day' => '2026-05-09',
            ]))
            ->assertOk()
            ->assertSeeText('In Range')
            ->assertDontSeeText('Out Range')
            ->assertSeeText($adminA->name)
            ->assertSeeText('Rp 110.000');
    }

    public function test_shift_report_for_staff_admin_includes_only_rentals_they_handled(): void
    {
        $adminA = User::factory()->create();
        $adminA->forceFill(['role' => 'admin'])->save();
        $adminB = User::factory()->create();
        $adminB->forceFill(['role' => 'admin'])->save();

        $outfit = Outfit::create(['code' => 'HND-SCOPE-1', 'price' => 100000, 'stock' => 2]);
        $shift = ShiftSession::create([
            'opened_by_user_id' => $adminA->id,
            'opened_at' => now()->subHours(2),
            'period' => ShiftSession::PERIOD_MORNING,
            'closed_at' => now(),
            'closed_by_user_id' => $adminA->id,
        ]);

        $mine = Rental::create([
            'customer_name' => 'Handled By A Only',
            'phone' => '081100000001',
            'notes' => 'Branch A',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-16',
            'status' => 'returned',
            'shift_session_id' => $shift->id,
            'handled_by_user_id' => $adminA->id,
        ]);
        $mine->forceFill(['created_at' => '2026-06-15 10:00:00', 'updated_at' => '2026-06-15 10:00:00'])->save();
        $mine->outfits()->attach($outfit->id);

        $theirs = Rental::create([
            'customer_name' => 'Handled By B Only',
            'phone' => '081100000002',
            'notes' => 'Branch A',
            'start_date' => '2026-06-15',
            'end_date' => '2026-06-16',
            'status' => 'returned',
            'shift_session_id' => $shift->id,
            'handled_by_user_id' => $adminB->id,
        ]);
        $theirs->forceFill(['created_at' => '2026-06-15 11:00:00', 'updated_at' => '2026-06-15 11:00:00'])->save();
        $theirs->outfits()->attach($outfit->id);

        $query = [
            'shift' => $shift->id,
            'period' => 'custom',
            'date_from' => '2026-06-15',
            'date_to' => '2026-06-15',
        ];

        $this->actingAs($adminA)
            ->get(route('shifts.report', $query))
            ->assertOk()
            ->assertSeeText('Handled By A Only', false)
            ->assertSeeText('Handled By B Only', false)
            ->assertDontSeeText('only rents you handled', false);

        $management = User::factory()->create();
        $management->forceFill(['role' => 'management'])->save();

        $this->actingAs($management)
            ->get(route('shifts.report', $query))
            ->assertOk()
            ->assertSeeText('Handled By A Only', false)
            ->assertSeeText('Handled By B Only', false)
            ->assertDontSeeText('only rents you handled', false);
    }

    public function test_rentals_index_shift_control_financial_totals_match_rental_financial_summary(): void
    {
        Cache::flush();
        StoreCache::forever('pricing_tax_percent', 10);

        $user = User::factory()->create();
        $user->forceFill(['role' => 'admin'])->save();

        $outfit = Outfit::create([
            'code' => 'SHIFT-PANEL-MATH',
            'price' => 100000,
            'stock' => 1,
        ]);

        $shift = ShiftSession::create([
            'opened_by_user_id' => $user->id,
            'opened_at' => now()->subHour(),
            'period' => ShiftSession::PERIOD_MORNING,
        ]);

        $rental = Rental::create([
            'customer_name' => 'Panel Math Customer',
            'phone' => '081199900001',
            'notes' => 'Branch A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
            'shift_session_id' => $shift->id,
            'handled_by_user_id' => $user->id,
            'discount' => 10000,
            'discount_type' => 'fixed',
        ]);
        $rental->outfits()->attach($outfit->id, ['discount' => 5000, 'discount_type' => 'fixed']);

        $rental->load('outfits');
        $expected = RentalFinancialSummary::summarize($rental, 10.0);
        $this->assertSame(100000.0, $expected['gross']);
        $this->assertSame(5000.0, $expected['item_discount_total']);
        $this->assertSame(10000.0, $expected['order_discount_amount']);
        $this->assertSame(85000.0, $expected['pre_tax_net']);
        $this->assertSame(8500.0, $expected['tax_amount']);
        $this->assertSame(93500.0, $expected['net_incl_tax']);

        $html = $this->actingAs($user)->get(route('rentals.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Rp 100.000', $html);
        $this->assertStringContainsString('−Rp 5.000', $html);
        $this->assertStringContainsString('−Rp 10.000', $html);
        $this->assertStringContainsString('Rp 85.000', $html);
        $this->assertStringContainsString('Rp 8.500', $html);
        $this->assertStringContainsString('Rp 93.500', $html);
    }

    public function test_rentals_index_shift_control_counts_only_rentals_handled_by_viewer(): void
    {
        Cache::flush();
        StoreCache::forever('pricing_tax_percent', 0);

        $adminA = User::factory()->create();
        $adminA->forceFill(['role' => 'admin'])->save();
        $adminB = User::factory()->create();
        $adminB->forceFill(['role' => 'admin'])->save();

        $outfit = Outfit::create(['code' => 'SHIFT-PANEL-SCOPE', 'price' => 50000, 'stock' => 2]);

        $shift = ShiftSession::create([
            'opened_by_user_id' => $adminA->id,
            'opened_at' => now()->subHour(),
            'period' => ShiftSession::PERIOD_AFTERNOON,
        ]);

        Rental::create([
            'customer_name' => 'ShiftPanelHandledByA',
            'phone' => '081188800001',
            'notes' => 'Branch A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
            'shift_session_id' => $shift->id,
            'handled_by_user_id' => $adminA->id,
        ])->outfits()->attach($outfit->id);

        Rental::create([
            'customer_name' => 'ShiftPanelHandledByB',
            'phone' => '081188800002',
            'notes' => 'Branch A',
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'status' => 'active',
            'shift_session_id' => $shift->id,
            'handled_by_user_id' => $adminB->id,
        ])->outfits()->attach($outfit->id);

        $html = $this->actingAs($adminA)->get(route('rentals.index'))->assertOk()->getContent();

        $this->assertStringContainsString('· 2 rents', $html);
        $this->assertStringContainsString('· 2 items out', $html);
        $this->assertStringContainsString('ShiftPanelHandledByA', $html);
        $this->assertStringContainsString('ShiftPanelHandledByB', $html);
    }
}
