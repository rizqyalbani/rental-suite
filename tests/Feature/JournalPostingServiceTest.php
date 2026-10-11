<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Store;
use App\Support\CashMovementRecorder;
use App\Support\ExpenseRecorder;
use App\Support\JournalEntryValidationException;
use App\Support\JournalPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class JournalPostingServiceTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_post_creates_balanced_entry(): void
    {
        $store = $this->makeStore();
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $expense = $this->makeLeafAccount('5900', 'expense', 'debit');

        $entry = JournalPostingService::postTwoLine(
            storeId: $store->id,
            entryDate: now()->toDateString(),
            memo: 'Test expense',
            debitAccountId: $expense->id,
            creditAccountId: $cash->id,
            amount: 50000,
            createdByUserId: null,
        );

        $this->assertInstanceOf(JournalEntry::class, $entry);
        $this->assertSame(2, $entry->lines()->count());
        $this->assertEquals(
            JournalEntryLine::where('journal_entry_id', $entry->id)->sum('debit'),
            JournalEntryLine::where('journal_entry_id', $entry->id)->sum('credit'),
        );
    }

    public function test_post_rejects_unbalanced_lines(): void
    {
        $store = $this->makeStore();
        $a = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $b = $this->makeLeafAccount('5900', 'expense', 'debit');

        $this->expectException(JournalEntryValidationException::class);

        try {
            JournalPostingService::post(
                storeId: $store->id,
                entryDate: now()->toDateString(),
                memo: 'Unbalanced',
                lines: [
                    ['account_id' => $a->id, 'debit' => 100],
                    ['account_id' => $b->id, 'credit' => 99],
                ],
                createdByUserId: null,
            );
        } finally {
            $this->assertSame(0, JournalEntry::count(), 'Rejected entry must not leave a partial row.');
        }
    }

    public function test_post_rejects_inactive_account(): void
    {
        $store = $this->makeStore();
        $a = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $b = $this->makeLeafAccount('5900', 'expense', 'debit');
        $b->update(['is_active' => false]);

        $this->expectException(JournalEntryValidationException::class);
        JournalPostingService::postTwoLine(
            storeId: $store->id,
            entryDate: now()->toDateString(),
            memo: 'Should fail',
            debitAccountId: $b->id,
            creditAccountId: $a->id,
            amount: 10,
            createdByUserId: null,
        );
    }

    public function test_post_rejects_parent_account_with_children(): void
    {
        $store = $this->makeStore();
        $parent = $this->makeLeafAccount('5000', 'expense', 'debit');
        $child = $this->makeLeafAccount('5100', 'expense', 'debit');
        $child->update(['parent_id' => $parent->id]);
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);

        $this->expectException(JournalEntryValidationException::class);
        JournalPostingService::postTwoLine(
            storeId: $store->id,
            entryDate: now()->toDateString(),
            memo: 'Should fail — posting against a header account',
            debitAccountId: $parent->id,
            creditAccountId: $cash->id,
            amount: 10,
            createdByUserId: null,
        );
    }

    public function test_expense_recorder_creates_source_row_and_journal_entry_atomically(): void
    {
        $store = $this->makeStore();
        $category = $this->makeLeafAccount('5900', 'expense', 'debit');
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);

        $expense = ExpenseRecorder::record(
            storeId: $store->id,
            expenseDate: now()->toDateString(),
            accountId: $category->id,
            paymentAccountId: $cash->id,
            amount: 25000,
            description: 'Test expense',
            recordedByUserId: null,
        );

        $this->assertNotNull($expense->journal_entry_id);
        $this->assertSame(25000.0, (float) $expense->journalEntry->lines->firstWhere('account_id', $category->id)->debit);
        $this->assertSame(25000.0, (float) $expense->journalEntry->lines->firstWhere('account_id', $cash->id)->credit);
    }

    public function test_expense_recorder_rolls_back_fully_on_posting_failure(): void
    {
        $store = $this->makeStore();
        $category = $this->makeLeafAccount('5900', 'expense', 'debit');
        $inactiveCash = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $inactiveCash->update(['is_active' => false]);

        try {
            ExpenseRecorder::record(
                storeId: $store->id,
                expenseDate: now()->toDateString(),
                accountId: $category->id,
                paymentAccountId: $inactiveCash->id,
                amount: 1000,
                description: 'Should fail',
                recordedByUserId: null,
            );
            $this->fail('Expected JournalEntryValidationException.');
        } catch (JournalEntryValidationException) {
            $this->assertSame(0, \App\Models\Expense::count(), 'No orphan Expense row without a journal entry.');
            $this->assertSame(0, JournalEntry::count());
        }
    }

    public function test_cash_movement_monthly_deposit_scenario_posts_debit_bank_credit_till(): void
    {
        $store = $this->makeStore();
        $till = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $bank = $this->makeLeafAccount('1200', 'asset', 'debit', true);

        $movement = CashMovementRecorder::record(
            storeId: $store->id,
            movementDate: now()->toDateString(),
            direction: 'out',
            cashAccountId: $till->id,
            counterAccountId: $bank->id,
            amount: 500000,
            description: 'Monthly deposit',
            recordedByUserId: null,
        );

        $lines = $movement->journalEntry->lines;
        $this->assertSame(500000.0, (float) $lines->firstWhere('account_id', $bank->id)->debit);
        $this->assertSame(500000.0, (float) $lines->firstWhere('account_id', $till->id)->credit);
    }

    public function test_cash_movement_in_direction_posts_debit_cash_credit_counter(): void
    {
        $store = $this->makeStore();
        $till = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $equity = $this->makeLeafAccount('3100', 'equity', 'credit');

        $movement = CashMovementRecorder::record(
            storeId: $store->id,
            movementDate: now()->toDateString(),
            direction: 'in',
            cashAccountId: $till->id,
            counterAccountId: $equity->id,
            amount: 1000000,
            description: 'Owner capital injection',
            recordedByUserId: null,
        );

        $lines = $movement->journalEntry->lines;
        $this->assertSame(1000000.0, (float) $lines->firstWhere('account_id', $till->id)->debit);
        $this->assertSame(1000000.0, (float) $lines->firstWhere('account_id', $equity->id)->credit);
    }

    public function test_account_balance_helper_computes_correctly_for_debit_normal_account(): void
    {
        $store = $this->makeStore();
        $cash = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $equity = $this->makeLeafAccount('3100', 'equity', 'credit');

        CashMovementRecorder::record(
            storeId: $store->id,
            movementDate: now()->toDateString(),
            direction: 'in',
            cashAccountId: $cash->id,
            counterAccountId: $equity->id,
            amount: 200000,
            description: null,
            recordedByUserId: null,
        );

        $this->assertSame(200000.0, $cash->fresh()->balance($store->id));
        $this->assertSame(200000.0, $equity->fresh()->balance($store->id));
    }

    public function test_expense_recorder_update_reverses_old_entry_and_posts_corrected_one(): void
    {
        $store = $this->makeStore();
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
        $originalEntryId = $expense->journal_entry_id;

        $updated = ExpenseRecorder::update(
            expense: $expense,
            expenseDate: now()->toDateString(),
            accountId: $category->id,
            paymentAccountId: $cash->id,
            amount: 20000,
            description: 'Parking (corrected)',
            updatedByUserId: null,
        );

        // Original entry is untouched but now has a reversal pointing back at it.
        $original = JournalEntry::find($originalEntryId);
        $this->assertFalse((bool) $original->is_reversal);
        $reversal = JournalEntry::where('reverses_journal_entry_id', $originalEntryId)->first();
        $this->assertNotNull($reversal, 'Updating must create a reversal of the original entry.');
        $this->assertTrue((bool) $reversal->is_reversal);

        // The Expense row now points at a third, brand-new entry with the corrected amount.
        $this->assertNotSame($originalEntryId, $updated->journal_entry_id);
        $this->assertNotSame($reversal->id, $updated->journal_entry_id);
        $this->assertSame(20000.0, (float) $updated->amount);
        $this->assertSame('Parking (corrected)', $updated->description);

        // All three entries share the same reference back to this Expense — full audit trail.
        $this->assertSame(3, JournalEntry::where('reference_type', 'expense')->where('reference_id', $expense->id)->count());

        // Net effect on both accounts is just the corrected 20000 — the original
        // 15000 and its reversal cancel out mathematically.
        $this->assertSame(20000.0, $category->fresh()->balance($store->id));
        $this->assertSame(-20000.0, $cash->fresh()->balance($store->id));
    }

    public function test_cash_movement_update_reverses_old_entry_and_posts_corrected_one(): void
    {
        $store = $this->makeStore();
        $till = $this->makeLeafAccount('1100', 'asset', 'debit', true);
        $bank = $this->makeLeafAccount('1200', 'asset', 'debit', true);

        $movement = CashMovementRecorder::record(
            storeId: $store->id,
            movementDate: now()->toDateString(),
            direction: 'out',
            cashAccountId: $till->id,
            counterAccountId: $bank->id,
            amount: 500000,
            description: 'Monthly deposit',
            recordedByUserId: null,
        );
        $originalEntryId = $movement->journal_entry_id;

        $updated = CashMovementRecorder::update(
            movement: $movement,
            movementDate: now()->toDateString(),
            direction: 'out',
            cashAccountId: $till->id,
            counterAccountId: $bank->id,
            amount: 600000,
            description: 'Monthly deposit (corrected)',
            updatedByUserId: null,
        );

        $reversal = JournalEntry::where('reverses_journal_entry_id', $originalEntryId)->first();
        $this->assertNotNull($reversal);
        $this->assertNotSame($originalEntryId, $updated->journal_entry_id);
        $this->assertSame(600000.0, (float) $updated->amount);
        $this->assertSame(3, JournalEntry::where('reference_type', 'cash_movement')->where('reference_id', $movement->id)->count());

        $this->assertSame(600000.0, $bank->fresh()->balance($store->id));
        $this->assertSame(-600000.0, $till->fresh()->balance($store->id));
    }
}
