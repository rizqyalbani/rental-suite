<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

/**
 * Starter Chart of Accounts for an outfit-rental retail business. Idempotent —
 * keyed by `code`, safe to re-run. Add/rename accounts freely afterward via
 * the Chart of Accounts UI; this is a starting point, not a fixed schema.
 *
 * The 5 top-level rows (1000/2000/3000/4000/5000) are pure grouping headers
 * — JournalPostingService rejects posting directly against any account that
 * has children, so real entries always land on a leaf account.
 */
class ChartOfAccountsSeeder extends Seeder
{
    public function run(): void
    {
        // [code, name, type, normal_balance, is_cash_equivalent, parent_code]
        $accounts = [
            // Assets
            ['1000', 'ASSETS', 'asset', 'debit', false, null],
            ['1100', 'Store Cash', 'asset', 'debit', true, '1000'],
            ['1200', 'Bank', 'asset', 'debit', true, '1000'],
            ['1300', 'Rental Receivable', 'asset', 'debit', false, '1000'],
            ['1600', 'Equipment (Capital Asset)', 'asset', 'debit', false, '1000'],
            ['1610', 'Accumulated Depreciation', 'asset', 'credit', false, '1000'],
            ['1650', 'Supplies (Capital Asset)', 'asset', 'debit', false, '1000'],

            // Liabilities
            ['2000', 'LIABILITIES', 'liability', 'credit', false, null],
            ['2100', 'Accounts Payable', 'liability', 'credit', false, '2000'],
            ['2300', 'Customer Deposits', 'liability', 'credit', false, '2000'],
            ['2400', 'Output VAT Payable', 'liability', 'credit', false, '2000'],

            // Equity
            ['3000', 'EQUITY', 'equity', 'credit', false, null],
            ['3100', 'Owner\'s Capital', 'equity', 'credit', false, '3000'],
            ['3200', 'Owner\'s Drawings', 'equity', 'debit', false, '3000'],
            ['3250', 'Opening Balance Adjustment', 'equity', 'debit', false, '3000'],
            ['3300', 'Retained Earnings', 'equity', 'credit', false, '3000'],

            // Revenue
            ['4000', 'REVENUE', 'revenue', 'credit', false, null],
            ['4100', 'Rental Revenue', 'revenue', 'credit', false, '4000'],
            ['4200', 'Late Fee Revenue', 'revenue', 'credit', false, '4000'],
            ['4300', 'Other Income', 'revenue', 'credit', false, '4000'],
            ['4400', 'Cancellation Revenue (Forfeited Deposits)', 'revenue', 'credit', false, '4000'],
            ['4900', 'Discount Given', 'revenue', 'debit', false, '4000'],
            ['4910', 'Rental Refunds (Removed Item Refund)', 'revenue', 'debit', false, '4000'],

            // Expenses
            ['5000', 'EXPENSES', 'expense', 'debit', false, null],
            ['5100', 'Outfit Maintenance & Repair', 'expense', 'debit', false, '5000'],
            ['5150', 'Depreciation Expense', 'expense', 'debit', false, '5000'],
            ['5200', 'Salaries & Wages', 'expense', 'debit', false, '5000'],
            ['5300', 'Store Rent Expense', 'expense', 'debit', false, '5000'],
            ['5400', 'Utilities (Electricity, Water, Internet)', 'expense', 'debit', false, '5000'],
            ['5500', 'Marketing & Advertising', 'expense', 'debit', false, '5000'],
            ['5600', 'Office Supplies', 'expense', 'debit', false, '5000'],
            ['5700', 'Transport & Delivery', 'expense', 'debit', false, '5000'],
            ['5800', 'Bank & Admin Fees', 'expense', 'debit', false, '5000'],
            ['5900', 'Miscellaneous Expense', 'expense', 'debit', false, '5000'],
        ];

        // Two passes: create/update every account first (so every code
        // exists), then resolve parent_id by code lookup — avoids ordering
        // the array by dependency by hand.
        foreach ($accounts as [$code, $name, $type, $normalBalance, $isCashEquivalent]) {
            Account::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => $type,
                    'normal_balance' => $normalBalance,
                    'is_cash_equivalent' => $isCashEquivalent,
                    'is_active' => true,
                ]
            );
        }

        $idsByCode = Account::pluck('id', 'code');
        foreach ($accounts as [$code, , , , , $parentCode]) {
            if ($parentCode === null) {
                continue;
            }
            Account::where('code', $code)->update(['parent_id' => $idsByCode[$parentCode]]);
        }
    }
}
