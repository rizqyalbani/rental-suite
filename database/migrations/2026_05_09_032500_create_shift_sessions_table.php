<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('shift_sessions')) {
            Schema::create('shift_sessions', function (Blueprint $table) {
                $table->id();
                $table->foreignId('opened_by_user_id')->constrained('users')->cascadeOnDelete();
                $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->timestamp('opened_at');
                $table->timestamp('closed_at')->nullable();
                $table->text('opening_note')->nullable();
                $table->text('closing_note')->nullable();
                $table->unsignedInteger('total_transactions')->default(0);
                $table->decimal('total_revenue', 14, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasColumn('rentals', 'shift_session_id')) {
            Schema::table('rentals', function (Blueprint $table) {
                $table->foreignId('shift_session_id')->nullable()->after('handled_by_user_id')->constrained('shift_sessions')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('rentals', 'shift_session_id')) {
            Schema::table('rentals', function (Blueprint $table) {
                $table->dropConstrainedForeignId('shift_session_id');
            });
        }

        if (Schema::hasTable('shift_sessions')) {
            Schema::dropIfExists('shift_sessions');
        }
    }
};
