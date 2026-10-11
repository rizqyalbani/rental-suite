<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stores', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('code', 30)->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('store_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->constrained('stores')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['store_id', 'user_id']);
        });

        DB::table('stores')->insert([
            ['id' => 1, 'name' => 'Store 1', 'code' => 'store-1', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'name' => 'Store 2', 'code' => 'store-2', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $defaultStoreId = 1;
        $secondStoreId = 2;

        foreach (['outfits', 'rentals', 'shift_sessions', 'rental_approval_requests', 'invoices'] as $table) {
            if (! Schema::hasColumn($table, 'store_id')) {
                Schema::table($table, function (Blueprint $table) {
                    $table->foreignId('store_id')->nullable()->after('id')->constrained('stores')->restrictOnDelete();
                });
            }
            DB::table($table)->whereNull('store_id')->update(['store_id' => $defaultStoreId]);
            Schema::table($table, function (Blueprint $table) {
                $table->index('store_id');
            });
        }

        DB::table('users')->orderBy('id')->chunkById(200, function ($users) use ($defaultStoreId, $secondStoreId): void {
            foreach ($users as $user) {
                DB::table('store_user')->insertOrIgnore([
                    'store_id' => $defaultStoreId,
                    'user_id' => $user->id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
                $role = strtolower(trim((string) ($user->role ?? '')));
                if (in_array($role, ['super_admin', 'management', 'manager'], true)) {
                    DB::table('store_user')->insertOrIgnore([
                        'store_id' => $secondStoreId,
                        'user_id' => $user->id,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }
            }
        });

        Schema::table('outfits', function (Blueprint $table) {
            $table->dropUnique('outfits_code_unique');
            $table->unique(['store_id', 'code']);
        });
    }

    public function down(): void
    {
        Schema::table('outfits', function (Blueprint $table) {
            $table->dropUnique('outfits_store_id_code_unique');
            $table->unique('code');
        });

        foreach (['invoices', 'rental_approval_requests', 'shift_sessions', 'rentals', 'outfits'] as $tableName) {
            if (Schema::hasColumn($tableName, 'store_id')) {
                Schema::table($tableName, function (Blueprint $table) {
                    $table->dropConstrainedForeignId('store_id');
                });
            }
        }

        Schema::dropIfExists('store_user');
        Schema::dropIfExists('stores');
    }
};
