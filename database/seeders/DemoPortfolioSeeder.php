<?php

namespace Database\Seeders;

use App\Models\Rental;
use App\Models\ShiftSession;
use App\Models\Store;
use App\Models\Outfit;
use App\Models\User;
use App\Support\RentalPaymentRecorder;
use App\Support\StoreContext;
use Illuminate\Database\Seeder;

/**
 * Fictional data for portfolio screenshots. Run ONLY against a separate demo
 * database (DB_DATABASE=system_rental_demo), never against the real one:
 *
 *   DB_DATABASE=system_rental_demo CACHE_STORE=array php artisan db:seed --class=DemoPortfolioSeeder
 *
 * Every name, phone number and price below is invented. Money is written
 * through RentalPaymentRecorder so the ledger and reports stay consistent.
 */
class DemoPortfolioSeeder extends Seeder
{
    public function run(): void
    {
        if (! str_contains((string) config('database.connections.'.config('database.default').'.database'), 'demo')) {
            throw new \RuntimeException('Refusing to seed: DB_DATABASE must be a demo database.');
        }

        $this->call(ChartOfAccountsSeeder::class);

        $stores = [
            Store::create(['name' => 'Demo Store A', 'code' => 'demo-a', 'is_active' => true]),
            Store::create(['name' => 'Demo Store B', 'code' => 'demo-b', 'is_active' => true]),
        ];

        $users = [];
        foreach ([['Demo Owner', 'super_admin'], ['Demo Manager', 'management'], ['Demo Supervisor', 'supervisor'], ['Demo Cashier', 'admin'], ['Demo Accountant', 'accounting']] as [$name, $role]) {
            $user = User::factory()->create([
                'name' => $name,
                'email' => str_replace(' ', '.', strtolower($name)).'@demo.test',
                'password' => 'password',
                'role' => $role,
            ]);
            $user->stores()->attach(array_map(fn ($s) => $s->id, $stores));
            $users[$role] = $user;
        }

        $customers = ['Alya Pratama', 'Bima Santoso', 'Citra Dewi', 'Dimas Wijaya', 'Eka Lestari', 'Fajar Nugroho', 'Gita Maharani', 'Harper Kusuma', 'Intan Permata', 'Joko Saputra'];
        $prices = [150000, 175000, 200000, 250000, 300000, 350000];

        foreach ($stores as $storeIndex => $store) {
            StoreContext::set($store->id);
            // Shelf prices include VAT, as in the pricing described in the README.
            \App\Support\StoreCache::forever('pricing_prices_include_tax', true);

            $items = [];
            for ($i = 1; $i <= 15; $i++) {
                $items[] = Outfit::create([
                    'store_id' => $store->id,
                    'code' => sprintf('D%s-%03d', $storeIndex + 1, $i),
                    'description' => 'Demo item '.$i,
                    'price' => $prices[$i % count($prices)],
                    'stock' => 1,
                ]);
            }

            // One closed shift per past day, plus an open one today.
            $shifts = [];
            for ($d = 10; $d >= 0; $d--) {
                $day = now()->subDays($d)->startOfDay()->addHours(8);
                $shifts[$d] = ShiftSession::create([
                    'store_id' => $store->id,
                    'opened_by_user_id' => $users['admin']->id,
                    'opened_at' => $day,
                    'period' => ShiftSession::PERIOD_MORNING,
                    'closed_at' => $d > 0 ? $day->copy()->addHours(10) : null,
                    'closed_by_user_id' => $d > 0 ? $users['admin']->id : null,
                    'total_transactions' => 0,
                    'total_revenue' => 0,
                ]);
            }

            // 10 rentals spread over the last 10 days; a few ongoing, one with a down payment.
            for ($n = 0; $n < 10; $n++) {
                $daysAgo = 10 - $n;
                $days = 3;
                $start = now()->subDays($daysAgo);
                $item = $items[($n * 2) % count($items)];
                // Only rentals that are still running keep an open balance; finished ones are paid in full and returned.
                $isDownPayment = $n >= 8;
                $due = (float) $item->price;
                $paid = $isDownPayment ? round($due * 0.5, 2) : $due;
                $kind = $isDownPayment ? 'down_payment' : 'full';
                $method = $n % 3 === 0 ? 'transfer' : 'cash';

                $rental = Rental::create([
                    'store_id' => $store->id,
                    'customer_name' => $customers[$n % count($customers)],
                    'phone' => '08120000'.str_pad((string) ($n + 1), 4, '0', STR_PAD_LEFT),
                    'start_date' => $start->toDateString(),
                    'end_date' => $start->copy()->addDays($days - 1)->toDateString(),
                    'status' => $start->copy()->addDays($days - 1)->lt(now()->startOfDay()) ? 'returned' : 'active',
                    'picked_up_at' => $start->copy()->setTime(10, 0),
                    'payment_method' => $method,
                    'payment_kind' => $kind,
                    'amount_paid' => $paid,
                    'handled_by_user_id' => $users['admin']->id,
                    'created_by_user_id' => $users['admin']->id,
                    'shift_session_id' => $shifts[$daysAgo]->id,
                ]);
                $rental->outfits()->attach($item->id, [
                    'price_incl_tax' => $item->price,
                    'price_excl_tax' => round($item->price / 1.1, 2),
                    'tax_amount' => round($item->price - $item->price / 1.1, 2),
                ]);
                RentalPaymentRecorder::recordIfNonZero(
                    $rental->load('outfits'), $paid, $shifts[$daysAgo]->id, $users['admin']->id,
                    $method, $kind, $start->copy()->setTime(10, 0)->toDateTimeString(), $due,
                );
            }

            // One pending approval so the approvals queue has something to show.
            \App\Support\RentalFreeRequest::submit($rental->fresh(), $users['supervisor'], 'Service recovery: the customer received a damaged item.', $shifts[0]->id);

            // Back-office examples: capital, expenses, a drawing, a fixed asset with one depreciation.
            $acc = \App\Models\Account::pluck('id', 'code');
            $by = $users['accounting']->id;
            $day = fn (int $ago) => now()->subDays($ago)->toDateString();
            \App\Support\CashMovementRecorder::record($store->id, $day(10), 'in', $acc['1100'], $acc['3100'], 5000000, 'Owner capital for the month', $by);
            \App\Support\ExpenseRecorder::record($store->id, $day(8), $acc['5400'], $acc['1100'], 450000, 'Electricity and internet', $by);
            \App\Support\ExpenseRecorder::record($store->id, $day(6), $acc['5700'], $acc['1100'], 120000, 'Courier for item delivery', $by);
            \App\Support\ExpenseRecorder::record($store->id, $day(4), $acc['5100'], $acc['1200'], 300000, 'Steaming and repair of 3 items', $by);
            \App\Support\CashMovementRecorder::record($store->id, $day(2), 'out', $acc['1100'], $acc['3200'], 500000, 'Owner drawing', $by);
            $asset = \App\Support\FixedAssetRecorder::record($store->id, 'Steam iron set', $acc['1600'], $day(9), 3600000, $acc['1200'], 'Bought for the pressing corner', $by);
            \App\Support\FixedAssetRecorder::depreciate($asset, $day(1), 100000, 'Monthly depreciation', $by);
        }

        StoreContext::set(null);
    }
}
