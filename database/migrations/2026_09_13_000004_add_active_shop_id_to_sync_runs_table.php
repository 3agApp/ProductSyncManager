<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Holds the shop id while a run is queued or running, and null once it
        // finishes. The unique index lets the database refuse a second active
        // run for a shop, which a check-then-insert cannot do under concurrency.
        // Unique indexes allow many nulls on SQLite, MySQL and PostgreSQL alike.
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('active_shop_id')->nullable()->unique();
        });

        $active = ['queued', 'running'];

        DB::table('sync_runs')
            ->whereIn('status', $active)
            ->select('shop_id', DB::raw('max(id) as id'))
            ->groupBy('shop_id')
            ->get()
            ->each(function (object $latest) use ($active) {
                // Only one active run per shop can survive the new invariant.
                DB::table('sync_runs')
                    ->where('shop_id', $latest->shop_id)
                    ->whereIn('status', $active)
                    ->where('id', '<', $latest->id)
                    ->update([
                        'status' => 'failed',
                        'message' => 'The sync was superseded by a newer run.',
                        'finished_at' => now(),
                    ]);

                DB::table('sync_runs')
                    ->where('id', $latest->id)
                    ->update(['active_shop_id' => $latest->shop_id]);
            });
    }

    public function down(): void
    {
        Schema::table('sync_runs', function (Blueprint $table) {
            $table->dropUnique(['active_shop_id']);
            $table->dropColumn('active_shop_id');
        });
    }
};
