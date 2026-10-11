<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Relax the status enum to a string so 'cancelled' (requester withdrew) fits.
        Schema::table('rental_approval_requests', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Leave as string; narrowing back to the enum would drop 'cancelled' rows.
    }
};
