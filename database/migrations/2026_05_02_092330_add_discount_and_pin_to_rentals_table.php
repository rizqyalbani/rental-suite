<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->decimal('discount', 12, 2)->nullable()->after('address');
            $table->string('discount_type', 10)->default('fixed')->after('discount');
            $table->foreignId('pin_id')->nullable()->constrained('pins')->nullOnDelete()->after('discount_type');
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropForeign(['pin_id']);
            $table->dropColumn(['discount', 'discount_type', 'pin_id']);
        });
    }
};
