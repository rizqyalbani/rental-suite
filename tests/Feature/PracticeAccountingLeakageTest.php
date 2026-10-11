<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\CashMovement;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Store;
use App\Models\User;
use App\Support\AccountingTrialMode;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Leakage test for the accounting practice steps (readiness checklist, owner request 1b-b): an expense,
 * a manual journal entry or a fixed asset made with `tutorial_practice=1` must leave every number the
 * REAL books show exactly as it was (scoped counts, account balances, the dashboard-style sums), while
 * the practice row really exists when read without scopes (so the test cannot pass on a no-op).
 * Trial Mode is ON, as on production: that is the harder case, because `is_tutorial` rows then count.
 */
class PracticeAccountingLeakageTest extends TestCase
{
    use RefreshDatabase;

    private Store $store;

    private User $manager;

    private Account $cash;

    private Account $expenseAccount;

    private Account $assetAccount;

    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
        $this->cash = Account::create(['code' => '1100', 'name' => 'Store Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => true, 'is_active' => true]);
        $this->assetAccount = Account::create(['code' => '1650', 'name' => 'Supplies (Capital)', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => false, 'is_active' => true]);
        $this->expenseAccount = Account::create(['code' => '5900', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'is_cash_equivalent' => false, 'is_active' => true]);
        Account::create(['code' => '1610', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'normal_balance' => 'credit', 'is_cash_equivalent' => false, 'is_active' => true]);
        Account::create(['code' => '5150', 'name' => 'Depreciation Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'is_cash_equivalent' => false, 'is_active' => true]);
        $this->store = Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
        $this->manager = User::factory()->create(['role' => 'management']);
        $this->manager->stores()->attach($this->store->id);
        StoreContext::set($this->store->id);
        AccountingTrialMode::setEnabled(true);
    }

    protected function tearDown(): void
    {
        AccountingTrialMode::setEnabled(false);
        StoreContext::set(null);
        parent::tearDown();
    }

    private function as(User $u)
    {
        return $this->actingAs($u)->withSession(['current_store_id' => $this->store->id]);
    }

    /** What the real books show: everything through the normal (scoped) queries, plus every account balance. */
    private function realBooks(): array
    {
        return [
            'journal_entries' => JournalEntry::count(),
            'journal_lines' => JournalEntryLine::query()->whereIn('journal_entry_id', JournalEntry::query()->select('id'))->count(),
            'journal_debit' => (float) JournalEntryLine::query()->whereIn('journal_entry_id', JournalEntry::query()->select('id'))->sum('debit'),
            'expenses' => Expense::count(),
            'expenses_sum' => (float) Expense::sum('amount'),
            'cash_movements' => CashMovement::count(),
            'fixed_assets' => FixedAsset::count(),
            'balances' => Account::orderBy('code')->get()->mapWithKeys(fn (Account $a) => [$a->code => $a->balance()])->all(),
        ];
    }

    /** Seed one REAL expense so the books are not empty (a leak into zero would be easy to miss). */
    private function seedRealExpense(): void
    {
        $this->as($this->manager)->post(route('expenses.store'), [
            'expense_date' => now()->toDateString(), 'account_id' => $this->expenseAccount->id,
            'payment_account_id' => $this->cash->id, 'amount' => '250.000', 'description' => 'Real expense',
        ])->assertRedirect();
    }

    public function test_a_practice_expense_leaks_nothing_into_the_real_books(): void
    {
        $this->seedRealExpense();
        $before = $this->realBooks();
        $this->assertSame(1, $before['expenses']);

        $this->as($this->manager)->post(route('expenses.store'), [
            'expense_date' => now()->toDateString(), 'account_id' => $this->expenseAccount->id,
            'payment_account_id' => $this->cash->id, 'amount' => '75.000', 'description' => 'Practice expense',
            'tutorial_practice' => '1',
        ])->assertRedirect();

        $this->assertSame(1, Expense::withoutGlobalScopes()->where('description', 'Practice expense')->where('is_tutorial_practice', true)->count(), 'practice side did change');
        $this->assertSame($before, $this->realBooks());
    }

    public function test_a_practice_manual_journal_entry_leaks_nothing_into_the_real_books(): void
    {
        $this->seedRealExpense();
        $before = $this->realBooks();

        $this->as($this->manager)->post(route('accounting.journal-entries.store'), [
            'entry_date' => now()->toDateString(), 'debit_account_id' => $this->expenseAccount->id,
            'credit_account_id' => $this->cash->id, 'amount' => '40.000', 'memo' => 'Practice journal',
            'tutorial_practice' => '1',
        ])->assertRedirect();

        $this->assertSame(1, JournalEntry::withoutGlobalScopes()->where('memo', 'Practice journal')->where('is_tutorial_practice', true)->count(), 'practice side did change');
        $this->assertSame($before, $this->realBooks());
    }

    public function test_a_practice_fixed_asset_leaks_nothing_into_the_real_books(): void
    {
        $this->seedRealExpense();
        $before = $this->realBooks();

        $this->as($this->manager)->post(route('accounting.fixed-assets.store'), [
            'name' => 'Practice asset', 'account_id' => $this->assetAccount->id, 'purchase_date' => now()->toDateString(),
            'purchase_price' => '1.200.000', 'payment_account_id' => $this->cash->id,
            'tutorial_practice' => '1',
        ])->assertRedirect();

        $asset = FixedAsset::withoutGlobalScopes()->where('name', 'Practice asset')->where('is_tutorial_practice', true)->first();
        $this->assertNotNull($asset, 'practice side did change');
        $this->assertSame($before, $this->realBooks());
        $this->assertSame(2, DB::table('journal_entries')->where('is_tutorial_practice', true)->count() + 1, 'the practice purchase journal exists');
    }

    /** The tour's "Try depreciating (practice)" step runs on a registered (real) asset: its practice journal must not show in the real books. */
    public function test_a_practice_depreciation_on_a_real_asset_leaks_nothing_into_the_real_books(): void
    {
        $this->as($this->manager)->post(route('accounting.fixed-assets.store'), [
            'name' => 'Real asset', 'account_id' => $this->assetAccount->id, 'purchase_date' => now()->toDateString(),
            'purchase_price' => '2.000.000', 'payment_account_id' => $this->cash->id,
        ])->assertRedirect();
        $asset = FixedAsset::where('name', 'Real asset')->firstOrFail();
        $before = $this->realBooks();

        $this->as($this->manager)->post(route('accounting.fixed-assets.depreciate', $asset->id), [
            'date' => now()->toDateString(), 'amount' => '100.000', 'tutorial_practice' => '1',
        ])->assertRedirect();

        $this->assertSame(1, DB::table('journal_entries')->where('is_tutorial_practice', true)->count(), 'practice side did change');
        $this->assertSame($before, $this->realBooks());
    }

    /** Guard: the snapshot above would catch a real expense (the same post without the practice flag). */
    public function test_the_snapshot_detects_a_real_expense(): void
    {
        $this->seedRealExpense();
        $before = $this->realBooks();

        $this->as($this->manager)->post(route('expenses.store'), [
            'expense_date' => now()->toDateString(), 'account_id' => $this->expenseAccount->id,
            'payment_account_id' => $this->cash->id, 'amount' => '75.000', 'description' => 'Not practice',
        ])->assertRedirect();

        $this->assertNotSame($before, $this->realBooks());
    }
}
