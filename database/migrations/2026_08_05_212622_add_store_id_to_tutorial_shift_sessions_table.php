<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // BelongsToStore's creating() hook always tries to set store_id on any
        // model using the trait (see TutorialShiftSession) — every other
        // Tutorial* table already carries this column for that reason (see
        // tutorial_rentals), this one was missed when the table was created.
        Schema::table('tutorial_shift_sessions', function (Blueprint $table) {
            $table->foreignId('store_id')->nullable()->after('id')->constrained('stores')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tutorial_shift_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('store_id');
        });
    }
};
