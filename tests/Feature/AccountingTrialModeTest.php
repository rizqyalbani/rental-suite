<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\Store;
use App\Models\User;
use App\Support\AccountingTrialMode;
use App\Support\CashMovementRecorder;
use App\Support\ExpenseRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccountingTrialModeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * AccountingTrialMode is backed by the plain cache facade (array driver
     * in testing), not the database — RefreshDatabase never touches it, so
     * a test that turns trial mode on and forgets to turn it back off would
     * leak into every test that runs afterward in the same process.
     */
    protected function tearDown(): void
    {
        AccountingTrialMode::setEnabled(false);
        parent::tearDown();
    }

    private function makeStore(): Store
    {
        return Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
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

    public function test_new_entries_are_tagged_tutorial_while_trial_mode_is_on(): void
    {
        $store = $this->makeStore();
        $category = $this->makeLeafAccount('5900', 'expense', 'debit');
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);

        AccountingTrialMode::setEnabled(true);

        $expense = ExpenseRecorder::record(
            storeId: $store->id,
            expenseDate: now()->toDateString(),
            accountId: $category->id,
            paymentAccountId: $cash->id,
            amount: 15000,
            description: 'Trial expense',
            recordedByUserId: null,
        );

        $this->assertTrue($expense->is_tutorial);
        $this->assertTrue($expense->journalEntry->is_tutorial);
    }

    public function test_trial_entries_visible_and_counted_while_on_hidden_and_excluded_once_off(): void
    {
        $store = $this->makeStore();
        $category = $this->makeLeafAccount('5900', 'expense', 'debit');
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);

        AccountingTrialMode::setEnabled(true);
        // A manually-driven trial expense (not through a guided tour's
        // practice step, isTutorialPractice defaults false) — the original
        // pre-launch bulk-testing case, which is meant to behave like real
        // data for as long as Trial Mode stays on.
        $expense = ExpenseRecorder::record(
            storeId: $store->id,
            expenseDate: now()->toDateString(),
            accountId: $category->id,
            paymentAccountId: $cash->id,
            amount: 15000,
            description: 'Trial expense',
            recordedByUserId: null,
        );

        // Visible and counted while trial mode is still on.
        $this->assertTrue(Expense::where('id', $expense->id)->exists());
        $this->assertSame(15000.0, $category->fresh()->balance());

        // Switched off: hidden from normal queries, excluded from balance,
        // but never actually deleted.
        AccountingTrialMode::setEnabled(false);
        $this->assertFalse(Expense::where('id', $expense->id)->exists());
        $this->assertSame(0.0, $category->fresh()->balance());
        $this->assertTrue(Expense::withoutGlobalScopes()->where('id', $expense->id)->exists());
    }

    public function test_guided_practice_entries_are_always_hidden_and_excluded_on_or_off(): void
    {
        $store = $this->makeStore();
        $category = $this->makeLeafAccount('5900', 'expense', 'debit');
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);

        AccountingTrialMode::setEnabled(true);
        // A tutorial's own practice submission (isTutorialPractice: true) —
        // must never count toward real numbers, on or off, since a guided
        // walkthrough can be opened at any time, including long after real
        // data already exists.
        $expense = ExpenseRecorder::record(
            storeId: $store->id,
            expenseDate: now()->toDateString(),
            accountId: $category->id,
            paymentAccountId: $cash->id,
            amount: 20000,
            description: 'Drinking water gallon',
            recordedByUserId: null,
            isTutorialPractice: true,
        );

        $this->assertFalse(Expense::where('id', $expense->id)->exists());
        $this->assertSame(0.0, $category->fresh()->balance());

        AccountingTrialMode::setEnabled(false);
        $this->assertFalse(Expense::where('id', $expense->id)->exists());
        $this->assertSame(0.0, $category->fresh()->balance());
        $this->assertTrue(Expense::withoutGlobalScopes()->where('id', $expense->id)->exists());
    }

    public function test_new_entries_are_real_once_trial_mode_is_off(): void
    {
        $store = $this->makeStore();
        $category = $this->makeLeafAccount('5900', 'expense', 'debit');
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);

        AccountingTrialMode::setEnabled(false);

        $expense = ExpenseRecorder::record(
            storeId: $store->id,
            expenseDate: now()->toDateString(),
            accountId: $category->id,
            paymentAccountId: $cash->id,
            amount: 15000,
            description: 'Real expense',
            recordedByUserId: null,
        );

        $this->assertFalse($expense->is_tutorial);
        $this->assertSame(15000.0, $category->fresh()->balance());
    }

    public function test_account_with_only_trial_history_is_not_locked_for_editing(): void
    {
        $category = $this->makeLeafAccount('5900', 'expense', 'debit');
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $store = $this->makeStore();

        AccountingTrialMode::setEnabled(true);
        ExpenseRecorder::record(
            storeId: $store->id,
            expenseDate: now()->toDateString(),
            accountId: $category->id,
            paymentAccountId: $cash->id,
            amount: 15000,
            description: 'Trial expense',
            recordedByUserId: null,
        );
        AccountingTrialMode::setEnabled(false);

        $management = User::factory()->create(['role' => 'management']);
        $response = $this->actingAs($management)->put(route('accounts.update', $category), [
            'code' => '5901',
            'name' => 'Renamed',
            'type' => 'expense',
            'normal_balance' => 'debit',
            'is_active' => '1',
        ]);

        $response->assertRedirect(route('accounts.index'));
        $this->assertSame('5901', $category->fresh()->code);
    }

    public function test_clear_trial_data_deletes_trial_rows_but_keeps_real_rows(): void
    {
        $store = $this->makeStore();
        $category = $this->makeLeafAccount('5900', 'expense', 'debit');
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $bank = $this->makeLeafAccount('1200', 'asset', 'debit', true);

        AccountingTrialMode::setEnabled(false);
        $realExpense = ExpenseRecorder::record(
            storeId: $store->id, expenseDate: now()->toDateString(),
            accountId: $category->id, paymentAccountId: $cash->id,
            amount: 10000, description: 'Real', recordedByUserId: null,
        );

        $superAdmin = User::factory()->create(['role' => 'super_admin']);

        AccountingTrialMode::setEnabled(true);
        $trialExpense = ExpenseRecorder::record(
            storeId: $store->id, expenseDate: now()->toDateString(),
            accountId: $category->id, paymentAccountId: $cash->id,
            amount: 20000, description: 'Trial', recordedByUserId: null,
        );
        $trialMovement = CashMovementRecorder::record(
            storeId: $store->id, movementDate: now()->toDateString(),
            direction: 'out', cashAccountId: $cash->id, counterAccountId: $bank->id,
            amount: 30000, description: 'Trial deposit', recordedByUserId: null,
        );
        // Through the real HTTP route (not Account::create() directly), and
        // a parent+child pair — exercises the leaf-first delete ordering,
        // which is also the path that hit a real MySQL-only bug ("can't
        // specify target table for update in FROM clause" from combining
        // DELETE with whereDoesntHave('children') in one statement; SQLite,
        // which this suite runs on, doesn't reject that pattern, so this
        // assertion only proves the *logic* — the MySQL fix itself was
        // confirmed live against the real dev database).
        $this->actingAs($superAdmin)->post(route('accounts.store'), [
            'code' => '5991', 'name' => 'Trial Parent', 'type' => 'expense', 'normal_balance' => 'debit',
        ]);
        $trialParent = Account::withoutGlobalScopes()->where('code', '5991')->firstOrFail();
        $this->actingAs($superAdmin)->post(route('accounts.store'), [
            'code' => '5992', 'name' => 'Trial Child', 'type' => 'expense', 'normal_balance' => 'debit',
            'parent_id' => $trialParent->id,
        ]);
        AccountingTrialMode::setEnabled(false);

        $this->actingAs($superAdmin)
            ->post(route('settings.accounting-trial-data.clear'))
            ->assertRedirect(route('settings.index'));

        $this->assertNull(Expense::withoutGlobalScopes()->find($trialExpense->id));
        $this->assertNull(CashMovement::withoutGlobalScopes()->find($trialMovement->id));
        $this->assertNull(Account::withoutGlobalScopes()->find($trialParent->id));
        $this->assertNull(Account::withoutGlobalScopes()->where('code', '5992')->first());
        $this->assertNotNull(Expense::withoutGlobalScopes()->find($realExpense->id), 'Real expense must survive a trial-data clear.');
        $this->assertSame(10000.0, $category->fresh()->balance());
    }

    public function test_clear_trial_data_forbidden_for_non_super_admin(): void
    {
        $management = User::factory()->create(['role' => 'management']);

        $this->actingAs($management)
            ->post(route('settings.accounting-trial-data.clear'))
            ->assertForbidden();
    }
}
