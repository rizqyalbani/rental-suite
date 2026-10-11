<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Rental;
use App\Models\Store;
use App\Models\User;
use App\Support\CashMovementRecorder;
use App\Support\ExpenseRecorder;
use App\Support\JournalPostingService;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingReportsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * StoreContext is a plain static (not cache/DB-backed), so it survives
     * RefreshDatabase's rollback — see AccountingRoleAccessTest for the same
     * fix and reasoning.
     */
    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
    }

    private function makeStore(): Store
    {
        return Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
    }

    private function seedAccounts(): array
    {
        return [
            'kas' => Account::create(['code' => '1100', 'name' => 'Store Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => true, 'is_active' => true]),
            'bank' => Account::create(['code' => '1200', 'name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => true, 'is_active' => true]),
            'modal' => Account::create(['code' => '3100', 'name' => "Owner's Capital", 'type' => 'equity', 'normal_balance' => 'credit', 'is_active' => true]),
            'revenue' => Account::create(['code' => '4100', 'name' => 'Rental Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'is_active' => true]),
            'expense' => Account::create(['code' => '5900', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'is_active' => true]),
        ];
    }

    public function test_reports_forbidden_for_supervisor(): void
    {
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        foreach (['accounting.reports.trial-balance', 'accounting.reports.balance-sheet', 'accounting.reports.profit-loss', 'accounting.reports.general-ledger'] as $route) {
            $this->actingAs($supervisor)->get(route($route))->assertForbidden();
        }
    }

    public function test_reports_accessible_to_accounting_management_super_admin(): void
    {
        foreach (['super_admin', 'management', 'accounting'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            foreach (['accounting.reports.trial-balance', 'accounting.reports.balance-sheet', 'accounting.reports.profit-loss', 'accounting.reports.general-ledger'] as $route) {
                $this->actingAs($user)->get(route($route))->assertOk();
            }
        }
    }

    public function test_trial_balance_debit_total_equals_credit_total(): void
    {
        $accounts = $this->seedAccounts();
        $store = $this->makeStore();

        // Owner injects capital: Dr Cash, Cr Capital.
        CashMovementRecorder::record(
            storeId: $store->id, movementDate: now()->toDateString(), direction: 'in',
            cashAccountId: $accounts['kas']->id, counterAccountId: $accounts['modal']->id,
            amount: 500000, description: 'Capital deposit', recordedByUserId: null,
        );
        // Dr Expense, Cr Cash.
        ExpenseRecorder::record(
            storeId: $store->id, expenseDate: now()->toDateString(),
            accountId: $accounts['expense']->id, paymentAccountId: $accounts['kas']->id,
            amount: 75000, description: 'Parking', recordedByUserId: null,
        );

        $management = User::factory()->create(['role' => 'management']);
        $response = $this->actingAs($management)->get(route('accounting.reports.trial-balance'));
        $response->assertOk();

        $totalDebit = $response->viewData('totalDebit');
        $totalCredit = $response->viewData('totalCredit');
        $this->assertEqualsWithDelta($totalCredit, $totalDebit, 0.01);
        $this->assertSame(500000.0, $totalDebit);
    }

    public function test_balance_sheet_assets_equal_liabilities_plus_equity(): void
    {
        $accounts = $this->seedAccounts();
        $store = $this->makeStore();

        CashMovementRecorder::record(
            storeId: $store->id, movementDate: now()->toDateString(), direction: 'in',
            cashAccountId: $accounts['kas']->id, counterAccountId: $accounts['modal']->id,
            amount: 1000000, description: 'Capital deposit', recordedByUserId: null,
        );
        // Revenue posted directly via JournalPostingService (mirrors how
        // RentalRevenueRecorder posts rent payments): Dr Cash, Cr Revenue.
        JournalPostingService::postTwoLine(
            storeId: $store->id, entryDate: now()->toDateString(), memo: 'Rental',
            debitAccountId: $accounts['kas']->id, creditAccountId: $accounts['revenue']->id,
            amount: 300000, createdByUserId: null,
        );
        ExpenseRecorder::record(
            storeId: $store->id, expenseDate: now()->toDateString(),
            accountId: $accounts['expense']->id, paymentAccountId: $accounts['kas']->id,
            amount: 50000, description: 'Parking', recordedByUserId: null,
        );

        $management = User::factory()->create(['role' => 'management']);
        $response = $this->actingAs($management)->get(route('accounting.reports.balance-sheet'));
        $response->assertOk();

        $assetTotal = $response->viewData('assetTotal');
        $liabilityTotal = $response->viewData('liabilityTotal');
        $equityTotal = $response->viewData('equityTotal');
        $currentEarnings = $response->viewData('currentEarnings');

        // Cash = 1,000,000 + 300,000 - 50,000 = 1,250,000.
        $this->assertSame(1250000.0, $assetTotal);
        $this->assertSame(250000.0, $currentEarnings); // 300,000 revenue - 50,000 expense
        $this->assertEqualsWithDelta($assetTotal, $liabilityTotal + $equityTotal, 0.01);
    }

    public function test_profit_loss_only_includes_entries_inside_the_date_range(): void
    {
        $accounts = $this->seedAccounts();
        $store = $this->makeStore();

        JournalPostingService::postTwoLine(
            storeId: $store->id, entryDate: '2026-03-15', memo: 'Rental March',
            debitAccountId: $accounts['kas']->id, creditAccountId: $accounts['revenue']->id,
            amount: 200000, createdByUserId: null,
        );
        JournalPostingService::postTwoLine(
            storeId: $store->id, entryDate: '2026-04-15', memo: 'Rental April (outside the range)',
            debitAccountId: $accounts['kas']->id, creditAccountId: $accounts['revenue']->id,
            amount: 999999, createdByUserId: null,
        );

        $management = User::factory()->create(['role' => 'management']);
        $response = $this->actingAs($management)->get(route('accounting.reports.profit-loss', [
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
        ]));
        $response->assertOk();

        $this->assertSame(200000.0, $response->viewData('revenueTotal'));
        $this->assertSame(200000.0, $response->viewData('netIncome'));
    }

    public function test_a_refund_booked_to_retur_sewa_lowers_revenue_in_laba_rugi_and_the_neraca_still_balances(): void
    {
        $accounts = $this->seedAccounts();
        $accounts['retur'] = Account::create(['code' => '4910', 'name' => 'Rental Refunds', 'type' => 'revenue', 'normal_balance' => 'debit', 'is_active' => true]);
        $store = $this->makeStore();

        // Rp 1.100.000 paid in full, then Rp 220.000 handed back (Dr Rental Refunds / Cr Cash).
        JournalPostingService::postTwoLine(
            storeId: $store->id, entryDate: now()->toDateString(), memo: 'Rental paid in full',
            debitAccountId: $accounts['kas']->id, creditAccountId: $accounts['revenue']->id,
            amount: 1100000, createdByUserId: null,
        );
        JournalPostingService::postTwoLine(
            storeId: $store->id, entryDate: now()->toDateString(), memo: 'Refund',
            debitAccountId: $accounts['retur']->id, creditAccountId: $accounts['kas']->id,
            amount: 220000, createdByUserId: null,
        );

        $management = User::factory()->create(['role' => 'management']);

        $pl = $this->actingAs($management)->get(route('accounting.reports.profit-loss', [
            'date_from' => now()->startOfMonth()->toDateString(), 'date_to' => now()->toDateString(),
        ]))->assertOk();
        $this->assertSame(880000.0, $pl->viewData('revenueTotal'));
        $this->assertSame(880000.0, $pl->viewData('netIncome'));
        $returRow = collect($pl->viewData('revenueRows'))->first(fn ($row) => $row['account']->code === '4910');
        $this->assertSame(-220000.0, $returRow['amount'], 'the refund shows as a negative revenue row');

        $bs = $this->actingAs($management)->get(route('accounting.reports.balance-sheet'))->assertOk();
        $this->assertSame(880000.0, $bs->viewData('assetTotal'));
        $this->assertSame(880000.0, $bs->viewData('currentEarnings'));
        $this->assertEqualsWithDelta($bs->viewData('assetTotal'), $bs->viewData('liabilityTotal') + $bs->viewData('equityTotal'), 0.01);
    }

    public function test_general_ledger_filters_by_account(): void
    {
        $accounts = $this->seedAccounts();
        $store = $this->makeStore();

        ExpenseRecorder::record(
            storeId: $store->id, expenseDate: now()->toDateString(),
            accountId: $accounts['expense']->id, paymentAccountId: $accounts['kas']->id,
            amount: 40000, description: 'Parking', recordedByUserId: null,
        );
        CashMovementRecorder::record(
            storeId: $store->id, movementDate: now()->toDateString(), direction: 'in',
            cashAccountId: $accounts['bank']->id, counterAccountId: $accounts['modal']->id,
            amount: 100000, description: 'Cash deposit', recordedByUserId: null,
        );

        $management = User::factory()->create(['role' => 'management']);
        $response = $this->actingAs($management)->get(route('accounting.reports.general-ledger', [
            'account_id' => $accounts['expense']->id,
        ]));
        $response->assertOk();

        $lines = $response->viewData('lines');
        $this->assertGreaterThan(0, $lines->count());
        foreach ($lines as $line) {
            $this->assertSame($accounts['expense']->id, $line->account_id);
        }
    }

    public function test_general_ledger_entry_shows_reversal_link(): void
    {
        $accounts = $this->seedAccounts();
        $store = $this->makeStore();

        $expense = ExpenseRecorder::record(
            storeId: $store->id, expenseDate: now()->toDateString(),
            accountId: $accounts['expense']->id, paymentAccountId: $accounts['kas']->id,
            amount: 20000, description: 'Parking', recordedByUserId: null,
        );
        $originalEntryId = $expense->journal_entry_id;

        ExpenseRecorder::update(
            expense: $expense, expenseDate: now()->toDateString(),
            accountId: $accounts['expense']->id, paymentAccountId: $accounts['kas']->id,
            amount: 25000, description: 'Parking (correction)', updatedByUserId: null,
        );

        $management = User::factory()->create(['role' => 'management']);
        // update() reverses the ORIGINAL entry (a new entry with
        // reverses_journal_entry_id pointing back at it) and posts a
        // separate, unrelated fresh entry for the corrected values — so the
        // original's detail page is where "reversedBy" should show up, not
        // the corrected entry (which references nothing).
        $response = $this->actingAs($management)->get(route('accounting.reports.general-ledger.entry', $originalEntryId));
        $response->assertOk();
        $this->assertNotNull($response->viewData('reversedBy'));
    }

    public function test_pdf_endpoints_return_pdf(): void
    {
        $this->seedAccounts();
        $management = User::factory()->create(['role' => 'management']);

        foreach (['accounting.reports.trial-balance.pdf', 'accounting.reports.balance-sheet.pdf', 'accounting.reports.profit-loss.pdf'] as $route) {
            $response = $this->actingAs($management)->get(route($route));
            $response->assertOk();
            $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
        }
    }

    public function test_orders_date_filter_actually_filters(): void
    {
        $store = $this->makeStore();
        StoreContext::set($store->id);

        $inRange = Rental::create([
            'store_id' => $store->id, 'customer_name' => 'In Range', 'phone' => '081200000001',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'status' => 'active',
        ]);
        $inRange->forceFill(['created_at' => '2026-03-15 10:00:00', 'updated_at' => '2026-03-15 10:00:00'])->save();

        $outOfRange = Rental::create([
            'store_id' => $store->id, 'customer_name' => 'Out Of Range', 'phone' => '081200000002',
            'start_date' => now()->toDateString(), 'end_date' => now()->addDay()->toDateString(), 'status' => 'active',
        ]);
        $outOfRange->forceFill(['created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00'])->save();

        $management = User::factory()->create(['role' => 'management']);
        $response = $this->actingAs($management)->get(route('accounting.dashboard', [
            'date_from' => '2026-03-01',
            'date_to' => '2026-03-31',
        ]));
        $response->assertOk();

        // The order summary counts only the rent booked inside the range.
        $this->assertSame(1, (int) $response->viewData('orderCounts')->sum());
        $this->assertSame(1, (int) ($response->viewData('orderCounts')['active'] ?? 0));
        $response->assertSee('Order Summary');
    }
}
