<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        User::factory()->create([
            'name' => 'Super Admin',
            'email' => 'superadmin@rental.test',
            'password' => 'password',
            'role' => 'super_admin',
        ]);

        User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@rental.test',
            'password' => 'password',
            'role' => 'admin',
        ]);

        User::factory()->create([
            'name' => 'Management',
            'email' => 'management@rental.test',
            'password' => 'password',
            'role' => 'management',
        ]);

        $this->call(ChartOfAccountsSeeder::class);
    }
}
