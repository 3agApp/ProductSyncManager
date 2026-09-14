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
        Schema::create('nalda_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            // Set only while the export is queued or running, so the unique
            // index allows one active export per shop.
            $table->foreignId('active_shop_id')->nullable()->unique()->constrained('shops')->cascadeOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger');
            $table->string('status');
            $table->unsignedInteger('listed_products')->default(0);
            $table->unsignedInteger('removed_products')->default(0);
            $table->unsignedInteger('skipped_products')->default(0);
            $table->string('file_name')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });

        // Every GTIN the last successful upload listed on Nalda. A product
        // that disappears from the catalog is not removed from Nalda by
        // leaving it out of the file, so the next file sends a delete row for
        // any GTIN in here that is no longer listed.
        Schema::create('nalda_listed_gtins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('gtin', 64);

            $table->unique(['shop_id', 'gtin']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nalda_listed_gtins');
        Schema::dropIfExists('nalda_exports');
    }
};
