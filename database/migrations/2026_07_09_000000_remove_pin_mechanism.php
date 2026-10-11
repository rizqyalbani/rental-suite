<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            $table->dropForeign(['pin_id']);
            $table->dropColumn('pin_id');
        });

        Schema::dropIfExists('pins');
    }

    public function down(): void
    {
        Schema::create('pins', function (Blueprint $table) {
            $table->id();
            $table->string('pin', 10);
            $table->foreignId('generated_by')->constrained('users')->cascadeOnDelete();
            $table->boolean('is_used')->default(false);
            $table->foreignId('used_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });

        Schema::table('rentals', function (Blueprint $table) {
            $table->foreignId('pin_id')->nullable()->constrained('pins')->nullOnDelete()->after('discount_type');
        });
    }
};
