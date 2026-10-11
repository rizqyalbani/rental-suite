<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('rentals') || Schema::hasColumn('rentals', 'discount_note')) {
            return;
        }

        Schema::table('rentals', function (Blueprint $table): void {
            $table->string('discount_note', 500)->nullable()->after('discount_type');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('rentals') || ! Schema::hasColumn('rentals', 'discount_note')) {
            return;
        }

        Schema::table('rentals', function (Blueprint $table): void {
            $table->dropColumn('discount_note');
        });
    }
};
