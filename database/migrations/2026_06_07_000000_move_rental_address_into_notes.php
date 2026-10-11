<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            if (! Schema::hasColumn('rentals', 'notes')) {
                if (Schema::hasColumn('rentals', 'address')) {
                    $table->text('notes')->nullable()->after('address');
                } else {
                    $table->text('notes')->nullable();
                }
            }
        });

        if (Schema::hasColumn('rentals', 'address') && Schema::hasColumn('rentals', 'notes')) {
            DB::table('rentals')
                ->select(['id', 'address', 'notes'])
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        $address = trim((string) ($row->address ?? ''));
                        if ($address === '') {
                            continue;
                        }
                        $notes = trim((string) ($row->notes ?? ''));
                        if ($notes !== '') {
                            continue;
                        }

                        DB::table('rentals')
                            ->where('id', (int) $row->id)
                            ->update(['notes' => $address]);
                    }
                });
        }

        Schema::table('rentals', function (Blueprint $table) {
            if (Schema::hasColumn('rentals', 'address')) {
                $table->dropColumn('address');
            }
        });
    }

    public function down(): void
    {
        Schema::table('rentals', function (Blueprint $table) {
            if (! Schema::hasColumn('rentals', 'address')) {
                $table->text('address')->nullable()->after('phone');
            }
        });

        if (Schema::hasColumn('rentals', 'address') && Schema::hasColumn('rentals', 'notes')) {
            DB::table('rentals')
                ->select(['id', 'notes'])
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        $notes = trim((string) ($row->notes ?? ''));
                        if ($notes === '') {
                            continue;
                        }
                        DB::table('rentals')
                            ->where('id', (int) $row->id)
                            ->update(['address' => $notes]);
                    }
                });
        }

        Schema::table('rentals', function (Blueprint $table) {
            if (Schema::hasColumn('rentals', 'notes')) {
                $table->dropColumn('notes');
            }
        });
    }
};
