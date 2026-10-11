<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\User;
use App\Support\CashMovementRecorder;
use App\Support\DebugTools;
use App\Support\ExpenseRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingRoleAccessTest extends TestCase
{
    use RefreshDatabase;

    /**
     * StoreContext is a plain static (not cache/DB-backed), so it survives
     * RefreshDatabase's rollback — a prior test's real HTTP request (through
     * SetCurrentStore middleware) can leave it pointing at a store id that no
     * longer exists once that test's transaction rolls back. Tests here that
     * create a ShiftSession directly (bypassing that middleware) would
     * otherwise inherit that stale id via BelongsToStore's creating() hook.
     */
    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
    }

    /**
     * DebugTools is backed by the plain cache facade (array driver in
     * testing), not the database — RefreshDatabase never touches it, so a
     * test that turns it on and forgets to turn it back off would leak into
     * every test that runs afterward in the same process.
     */
    protected function tearDown(): void
    {
        DebugTools::setEnabled(false);
        parent::tearDown();
    }

    private function makeLeafAccount(string $code, string $type, string $normalBalance, bool $cashEquivalent = false): Account
    {
        return Account::create([
            'code' => $code,
            'name' => $code,
            'type' => $type,
            'normal_balance' => $normalBalance,
            'is_cash_equivalent' => $cashEquivalent,
            'is_active' => true,
        ]);
    }

    public function test_accounting_role_can_view_but_not_manage_chart_of_accounts(): void
    {
        // Accounting can view the account structure that Trial Balance/Balance
        // Sheet/P&L reference (read-only), but adding/editing accounts stays
        // Management/Super Admin only — see User::mayViewChartOfAccounts().
        $user = User::factory()->create(['role' => 'accounting']);
        $account = $this->makeLeafAccount('5900', 'expense', 'debit');

        $this->actingAs($user)->get(route('accounting.dashboard'))->assertOk();
        $this->actingAs($user)->get(route('accounts.index'))->assertOk();
        $this->actingAs($user)->post(route('accounts.store'), [
            'code' => '5901', 'name' => 'Test', 'type' => 'expense', 'normal_balance' => 'debit',
        ])->assertForbidden();
        $this->actingAs($user)->put(route('accounts.update', $account), ['name' => 'Renamed'])->assertForbidden();
    }

    public function test_admin_can_record_expenses_but_not_manage_chart_of_accounts(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        $this->actingAs($user)->get(route('expenses.index'))->assertOk();
        $this->actingAs($user)->get(route('cash-movements.index'))->assertOk();
        $this->actingAs($user)->get(route('accounts.index'))->assertForbidden();
        $this->actingAs($user)->get(route('accounting.dashboard'))->assertForbidden();
    }

    public function test_supervisor_cannot_record_expenses(): void
    {
        // Client's own notes name only Admin for cash-in/out/expense entry —
        // Supervisor deliberately excluded, unlike its usual broad rent-
        // transaction access.
        $user = User::factory()->create(['role' => 'supervisor']);

        $this->actingAs($user)->get(route('expenses.index'))->assertForbidden();
    }

    public function test_management_can_manage_chart_of_accounts_and_view_dashboard(): void
    {
        $user = User::factory()->create(['role' => 'management']);

        $this->actingAs($user)->get(route('accounts.index'))->assertOk();
        $this->actingAs($user)->get(route('accounting.dashboard'))->assertOk();
    }

    public function test_admin_can_record_but_not_edit_an_expense(): void
    {
        $store = Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        $category = $this->makeLeafAccount('5900', 'expense', 'debit');
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $expense = ExpenseRecorder::record(
            storeId: $store->id,
            expenseDate: now()->toDateString(),
            accountId: $category->id,
            paymentAccountId: $cash->id,
            amount: 15000,
            description: 'Parking',
            recordedByUserId: null,
        );

        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)
            ->put(route('expenses.update', $expense), [
                'expense_date' => now()->toDateString(),
                'account_id' => $category->id,
                'payment_account_id' => $cash->id,
                'amount' => '20000',
                'description' => 'Should be forbidden',
            ])
            ->assertForbidden();
    }

    public function test_accounting_role_can_edit_an_expense_and_a_cash_movement(): void
    {
        $store = Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        $category = $this->makeLeafAccount('5900', 'expense', 'debit');
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $bank = $this->makeLeafAccount('1200', 'asset', 'debit', true);

        $expense = ExpenseRecorder::record(
            storeId: $store->id,
            expenseDate: now()->toDateString(),
            accountId: $category->id,
            paymentAccountId: $cash->id,
            amount: 15000,
            description: 'Parking',
            recordedByUserId: null,
        );
        $movement = CashMovementRecorder::record(
            storeId: $store->id,
            movementDate: now()->toDateString(),
            direction: 'out',
            cashAccountId: $cash->id,
            counterAccountId: $bank->id,
            amount: 500000,
            description: 'Deposit',
            recordedByUserId: null,
        );

        $accountingUser = User::factory()->create(['role' => 'accounting']);

        $this->actingAs($accountingUser)
            ->put(route('expenses.update', $expense), [
                'expense_date' => now()->toDateString(),
                'account_id' => $category->id,
                'payment_account_id' => $cash->id,
                'amount' => '20000',
                'description' => 'Corrected',
            ])
            ->assertRedirect(route('expenses.index'));
        $this->assertSame(20000.0, (float) $expense->fresh()->amount);

        $this->actingAs($accountingUser)
            ->put(route('cash-movements.update', $movement), [
                'movement_date' => now()->toDateString(),
                'direction' => 'out',
                'cash_account_id' => $cash->id,
                'counter_account_id' => $bank->id,
                'amount' => '600000',
                'description' => 'Corrected deposit',
            ])
            ->assertRedirect(route('cash-movements.index'));
        $this->assertSame(600000.0, (float) $movement->fresh()->amount);
    }

    public function test_accounting_role_can_view_a_shift_report(): void
    {
        Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = ShiftSession::create([
            'opened_by_user_id' => $admin->id,
            'opened_at' => now()->subHours(2),
            'period' => ShiftSession::PERIOD_MORNING,
            'closed_at' => now(),
            'closed_by_user_id' => $admin->id,
            'total_transactions' => 0,
            'total_revenue' => 0,
        ]);

        $accountingUser = User::factory()->create(['role' => 'accounting']);

        $this->actingAs($accountingUser)->get(route('shifts.report', $shift))->assertOk();
    }

    public function test_accounting_role_cannot_reopen_a_shift_even_with_debug_tools_enabled(): void
    {
        DebugTools::setEnabled(true);

        Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = ShiftSession::create([
            'opened_by_user_id' => $admin->id,
            'opened_at' => now()->subHours(2),
            'period' => ShiftSession::PERIOD_MORNING,
            'closed_at' => now(),
            'closed_by_user_id' => $admin->id,
            'total_transactions' => 0,
            'total_revenue' => 0,
        ]);

        $accountingUser = User::factory()->create(['role' => 'accounting']);

        $this->actingAs($accountingUser)
            ->post(route('shifts.reopen', $shift))
            ->assertForbidden();
        $this->assertNotNull($shift->fresh()->closed_at, 'Accounting must not be able to reopen a closed shift.');
    }

    public function test_accounting_shifts_by_date_returns_shifts_for_the_date(): void
    {
        // Shifts below open up to 4 hours before "now"; pin the clock to midday so they stay on
        // today's date whatever time the suite runs (it used to fail from 00:00 to 04:00).
        \Illuminate\Support\Carbon::setTestNow(now()->setTime(12, 0, 0));

        Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        $admin = User::factory()->create(['role' => 'admin']);
        $otherAdmin = User::factory()->create(['role' => 'admin']);
        $today = now()->toDateString();

        $ownShift = ShiftSession::create([
            'opened_by_user_id' => $admin->id,
            'opened_at' => now()->subHours(4),
            'period' => ShiftSession::PERIOD_MORNING,
            'closed_at' => now()->subHours(1),
            'closed_by_user_id' => $admin->id,
            'total_transactions' => 3,
            'total_revenue' => 150000,
        ]);
        // Belongs to a different admin — accountingShiftsByDate() must still
        // return it, unlike the debug-only byDate() which restricts non-
        // privileged callers to their own shifts.
        ShiftSession::create([
            'opened_by_user_id' => $otherAdmin->id,
            'opened_at' => now()->subHours(3),
            'period' => ShiftSession::PERIOD_AFTERNOON,
        ]);

        $accountingUser = User::factory()->create(['role' => 'accounting']);

        $response = $this->actingAs($accountingUser)
            ->getJson(route('accounting.shifts-by-date', ['date' => $today]))
            ->assertOk();

        $ids = collect($response->json('shifts'))->pluck('id')->all();
        $this->assertContains($ownShift->id, $ids);
        $this->assertCount(2, $ids);
    }

    public function test_accounting_shifts_by_date_forbidden_for_admin(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->getJson(route('accounting.shifts-by-date', ['date' => now()->toDateString()]))
            ->assertForbidden();
    }

    public function test_help_accounting_terms_visible_to_roles_that_touch_accounting(): void
    {
        foreach (['super_admin', 'management', 'accounting', 'admin'] as $role) {
            $user = User::factory()->create(['role' => $role]);
            $this->actingAs($user)->get(route('help.accounting-terms'))->assertOk();
        }
    }

    public function test_help_accounting_terms_forbidden_for_supervisor(): void
    {
        $supervisor = User::factory()->create(['role' => 'supervisor']);

        $this->actingAs($supervisor)->get(route('help.accounting-terms'))->assertForbidden();
    }
}
