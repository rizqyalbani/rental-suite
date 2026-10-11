<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\FixedAsset;
use App\Models\Store;
use App\Support\AccountingTrialMode;
use App\Support\FixedAssetRecorder;
use App\Support\StoreContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FixedAssetTest extends TestCase
{
    use RefreshDatabase;

    /**
     * StoreContext is a plain static (not cache/DB-backed), so it survives
     * RefreshDatabase's rollback — a prior test file's real HTTP request
     * could leave it pointing at a store id that no longer exists once that
     * test's transaction rolls back. Same fix as the other accounting tests.
     */
    protected function setUp(): void
    {
        parent::setUp();
        StoreContext::set(null);
    }

    protected function tearDown(): void
    {
        AccountingTrialMode::setEnabled(false);
        parent::tearDown();
    }

    private function makeStore(): Store
    {
        return Store::create(['name' => 'Test Store', 'code' => 'test-store', 'is_active' => true]);
    }

    private function makeAccounts(): array
    {
        $asset = Account::create(['code' => '1650', 'name' => 'Supplies (Capital)', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => false, 'is_active' => true]);
        $cash = Account::create(['code' => '1100', 'name' => 'Store Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'is_cash_equivalent' => true, 'is_active' => true]);
        $expense = Account::create(['code' => '5900', 'name' => 'Miscellaneous Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'is_cash_equivalent' => false, 'is_active' => true]);
        // Depreciation always credits 1610 (contra-asset) and debits 5150 —
        // resolved by code in FixedAsset::accumulatedDepreciationAccountId()
        // and FixedAssetRecorder::depreciate(), so both must exist for any
        // test that calls depreciate().
        Account::create(['code' => '1610', 'name' => 'Accumulated Depreciation', 'type' => 'asset', 'normal_balance' => 'credit', 'is_cash_equivalent' => false, 'is_active' => true]);
        Account::create(['code' => '5150', 'name' => 'Depreciation Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'is_cash_equivalent' => false, 'is_active' => true]);

        return [$asset, $cash, $expense];
    }

    public function test_recording_an_asset_posts_a_real_purchase_journal_entry(): void
    {
        [$asset, $cash] = $this->makeAccounts();
        $store = $this->makeStore();

        $fixedAsset = FixedAssetRecorder::record(
            storeId: $store->id,
            name: 'Broom',
            accountId: $asset->id,
            purchaseDate: now()->toDateString(),
            purchasePrice: 55000,
            paymentAccountId: $cash->id,
            notes: null,
            createdByUserId: null,
        );

        $this->assertNotNull($fixedAsset->journal_entry_id);
        $this->assertSame(55000.0, $asset->fresh()->balance());
        $this->assertSame(0.0, $cash->fresh()->balance() + 55000, 'Cash should have decreased by the purchase price.');
        $this->assertSame(0.0, $fixedAsset->totalDepreciated());
        $this->assertSame(55000.0, $fixedAsset->bookValue());
    }

    public function test_depreciation_linked_to_one_asset_does_not_affect_a_sibling_asset(): void
    {
        [$assetAccount, $cash] = $this->makeAccounts();
        $store = $this->makeStore();
        $contraAccount = Account::where('code', '1610')->firstOrFail();

        $sapu = FixedAssetRecorder::record(
            storeId: $store->id, name: 'Broom', accountId: $assetAccount->id,
            purchaseDate: now()->toDateString(), purchasePrice: 55000,
            paymentAccountId: $cash->id, notes: null, createdByUserId: null,
        );
        $hanger = FixedAssetRecorder::record(
            storeId: $store->id, name: 'Hanger Batch', accountId: $assetAccount->id,
            purchaseDate: now()->toDateString(), purchasePrice: 100000,
            paymentAccountId: $cash->id, notes: null, createdByUserId: null,
        );

        // Depreciate only "Broom" by 15.000, via the guided flow — no
        // Debit/Credit account picked by the caller.
        FixedAssetRecorder::depreciate(
            asset: $sapu,
            date: now()->toDateString(),
            amount: 15000,
            note: null,
            createdByUserId: null,
        );

        $this->assertSame(15000.0, $sapu->totalDepreciated());
        $this->assertSame(40000.0, $sapu->bookValue());
        // The sibling item, same account, must read zero — depreciation is
        // scoped by fixed_asset_id, not just "any credit to this account".
        $this->assertSame(0.0, $hanger->totalDepreciated());
        $this->assertSame(100000.0, $hanger->bookValue());

        // Depreciation credits the 1610 contra-asset account, not the
        // item's own account — so the asset account keeps its full original
        // cost (155.000) forever, and 1610 alone carries the 15.000.
        $this->assertSame(155000.0, $assetAccount->fresh()->balance());
        // 1610 is credit-normal, so its balance() reads positive 15.000
        // (credit - debit) for a pure-credit contra-asset balance.
        $this->assertSame(15000.0, $contraAccount->fresh()->balance());
    }

    public function test_guided_practice_asset_is_excluded_from_real_balances(): void
    {
        [$asset, $cash] = $this->makeAccounts();
        $store = $this->makeStore();
        AccountingTrialMode::setEnabled(true);

        FixedAssetRecorder::record(
            storeId: $store->id, name: 'Practice', accountId: $asset->id,
            purchaseDate: now()->toDateString(), purchasePrice: 25000,
            paymentAccountId: $cash->id, notes: null, createdByUserId: null,
            isTutorialPractice: true,
        );

        // A guided-practice asset never counts toward the account balance,
        // on or off — same rule as Expense/CashMovement/JournalEntry.
        $this->assertSame(0.0, $asset->fresh()->balance());
        $this->assertFalse(FixedAsset::where('name', 'Practice')->exists());

        AccountingTrialMode::setEnabled(false);
        $this->assertSame(0.0, $asset->fresh()->balance());
    }

    public function test_guided_practice_depreciation_is_excluded_from_real_balances(): void
    {
        [$asset, $cash] = $this->makeAccounts();
        $store = $this->makeStore();
        $contraAccount = Account::where('code', '1610')->firstOrFail();

        $sapu = FixedAssetRecorder::record(
            storeId: $store->id, name: 'Broom', accountId: $asset->id,
            purchaseDate: now()->toDateString(), purchasePrice: 55000,
            paymentAccountId: $cash->id, notes: null, createdByUserId: null,
        );

        AccountingTrialMode::setEnabled(true);
        FixedAssetRecorder::depreciate(
            asset: $sapu,
            date: now()->toDateString(),
            amount: 10000,
            note: null,
            createdByUserId: null,
            isTutorialPractice: true,
        );

        $this->assertSame(0.0, $sapu->totalDepreciated());
        $this->assertSame(55000.0, $sapu->fresh()->bookValue());
        $this->assertSame(0.0, $contraAccount->fresh()->balance());
    }
}
