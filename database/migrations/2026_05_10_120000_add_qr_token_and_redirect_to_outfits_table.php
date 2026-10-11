<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('outfits', function (Blueprint $table) {
            $table->string('qr_token', 64)->nullable()->unique()->after('id');
            $table->text('qr_redirect_url')->nullable()->after('description');
        });

        // Query builder, not the Outfit Eloquent model: the model's global
        // scopes (store/tutorial filtering) reflect today's schema, not the
        // schema as it existed at this point in migration history — using
        // it here breaks a from-scratch migration run the moment a later
        // migration adds a column one of those scopes depends on.
        DB::table('outfits')->whereNull('qr_token')->orderBy('id')->chunkById(100, function ($outfits) {
            foreach ($outfits as $outfit) {
                DB::table('outfits')->where('id', $outfit->id)->update(['qr_token' => Str::random(48)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('outfits', function (Blueprint $table) {
            $table->dropColumn(['qr_token', 'qr_redirect_url']);
        });
    }
};
