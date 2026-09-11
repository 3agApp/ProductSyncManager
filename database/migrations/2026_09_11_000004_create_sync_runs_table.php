<?php

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
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
        Schema::create('sync_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->foreignId('triggered_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('trigger')->default(SyncTrigger::Manual->value);
            $table->string('status')->default(SyncRunStatus::Queued->value);
            $table->unsignedInteger('total_products')->nullable();
            $table->unsignedInteger('processed_products')->default(0);
            $table->unsignedInteger('created_products')->default(0);
            $table->unsignedInteger('updated_products')->default(0);
            $table->unsignedInteger('archived_products')->default(0);
            $table->unsignedInteger('skipped_products')->default(0);
            $table->text('message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['shop_id', 'status']);
            $table->index(['shop_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('sync_runs');
    }
};
