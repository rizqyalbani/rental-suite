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
        // Practice rentals must only ever reference a practice shift, never a real one
        // — the original FKs (added before tutorial_shift_sessions existed) pointed at
        // the real shift_sessions table.
        // Column-array form (not the constraint-name string) — SQLite can't
        // drop a named foreign key directly, only rebuild the table, and
        // Laravel only does that table rebuild via this form. Resolves to
        // the same default constraint names on MySQL either way.
        Schema::table('tutorial_rentals', function (Blueprint $table) {
            $table->dropForeign(['shift_session_id']);
            $table->dropForeign(['overdue_payment_shift_session_id']);
        });
        Schema::table('tutorial_rentals', function (Blueprint $table) {
            $table->foreign('shift_session_id', 'tr_shift_session_fk')->references('id')->on('tutorial_shift_sessions')->nullOnDelete();
            $table->foreign('overdue_payment_shift_session_id', 'tr_overdue_shift_fk')->references('id')->on('tutorial_shift_sessions')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('tutorial_rentals', function (Blueprint $table) {
            $table->dropForeign(['shift_session_id']);
            $table->dropForeign(['overdue_payment_shift_session_id']);
        });
        Schema::table('tutorial_rentals', function (Blueprint $table) {
            $table->foreign('shift_session_id')->references('id')->on('shift_sessions')->nullOnDelete();
            $table->foreign('overdue_payment_shift_session_id')->references('id')->on('shift_sessions')->nullOnDelete();
        });
    }
};
