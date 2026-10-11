<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('store_settings')) {
            Schema::create('store_settings', function (Blueprint $table) {
                $table->id();
                $table->foreignId('store_id')->nullable()->constrained()->cascadeOnDelete();
                $table->string('key', 120);
                $table->text('value')->nullable();
                $table->timestamps();

                $table->unique(['store_id', 'key']);
            });

            return;
        }

        $db = (string) (DB::connection()->getDatabaseName() ?? '');
        if ($db !== '') {
            $hasUnique = DB::selectOne(
                'select 1 as ok from information_schema.statistics where table_schema = ? and table_name = ? and index_name = ? limit 1',
                [$db, 'store_settings', 'store_settings_store_id_key_unique']
            );
            if (! $hasUnique) {
                Schema::table('store_settings', function (Blueprint $table) {
                    $table->unique(['store_id', 'key']);
                });
            }

            $hasFk = DB::selectOne(
                "select 1 as ok
                 from information_schema.referential_constraints
                 where constraint_schema = ?
                   and table_name = ?
                   and constraint_name = ?
                 limit 1",
                [$db, 'store_settings', 'store_settings_store_id_foreign']
            );
            if (! $hasFk) {
                Schema::table('store_settings', function (Blueprint $table) {
                    $table->foreign('store_id')->references('id')->on('stores')->cascadeOnDelete();
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('store_settings');
    }
};
