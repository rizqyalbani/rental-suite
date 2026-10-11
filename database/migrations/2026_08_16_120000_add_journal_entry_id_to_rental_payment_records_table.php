<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rental_payment_records', function (Blueprint $table) {
            // Same nullOnDelete() as expenses/cash_movements.journal_entry_id —
            // if a trial-tagged journal entry gets purged by "Clear Trial
            // Data", the (real, always-kept) payment record just loses its
            // link rather than leaving a dangling reference.
            $table->foreignId('journal_entry_id')->nullable()->after('payment_kind')
                ->constrained('journal_entries')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rental_payment_records', function (Blueprint $table) {
            $table->dropConstrainedForeignId('journal_entry_id');
        });
    }
};
