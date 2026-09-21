<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use Carbon\CarbonImmutable;
use Database\Factories\TemuSyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $shop_id
 * @property int|null $active_shop_id
 * @property int|null $triggered_by
 * @property SyncTrigger $trigger
 * @property SyncRunStatus $status
 * @property int $created_products
 * @property int $updated_products
 * @property int $removed_products
 * @property int $failed_products
 * @property int $skipped_products
 * @property string|null $message
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Shop $shop
 */
#[Fillable([
    'triggered_by',
    'trigger',
    'status',
    'created_products',
    'updated_products',
    'removed_products',
    'failed_products',
    'skipped_products',
    'message',
    'started_at',
    'finished_at',
])]
class TemuSyncRun extends Model
{
    /** @use HasFactory<TemuSyncRunFactory> */
    use HasFactory;

    /**
     * Runs still active after this many minutes are treated as stalled.
     */
    public const int ACTIVE_TIMEOUT_MINUTES = 120;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        // The unique index on active_shop_id is what stops a shop having two
        // active runs, so every write keeps it in step with the status.
        static::saving(function (TemuSyncRun $run) {
            $run->active_shop_id = $run->status->isActive() ? $run->shop_id : null;
        });
    }

    /**
     * Get the shop that owns the run.
     *
     * @return BelongsTo<Shop, $this>
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }

    /**
     * Scope the query to runs that are queued or in progress.
     *
     * @param  Builder<TemuSyncRun>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->whereIn('status', [
            SyncRunStatus::Queued->value,
            SyncRunStatus::Running->value,
        ]);
    }

    /**
     * Mark the run as started, clearing anything from a previous attempt.
     */
    public function markRunning(): void
    {
        $this->update([
            'status' => SyncRunStatus::Running,
            'started_at' => $this->started_at ?? now(),
            'finished_at' => null,
            'message' => null,
        ]);
    }

    /**
     * Mark the run as finished.
     */
    public function markCompleted(): void
    {
        $this->update([
            'status' => SyncRunStatus::Completed,
            'finished_at' => now(),
        ]);
    }

    /**
     * Mark the run as failed with a message safe to show the user.
     */
    public function markFailed(string $message): void
    {
        $this->update([
            'status' => SyncRunStatus::Failed,
            'message' => $message,
            'finished_at' => now(),
        ]);
    }

    /**
     * Get how long the run took, or has been running for.
     */
    public function durationInSeconds(): ?int
    {
        if ($this->started_at === null) {
            return null;
        }

        return (int) $this->started_at->diffInSeconds($this->finished_at ?? now(), absolute: true);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'trigger' => SyncTrigger::class,
            'status' => SyncRunStatus::class,
            'created_products' => 'integer',
            'updated_products' => 'integer',
            'removed_products' => 'integer',
            'failed_products' => 'integer',
            'skipped_products' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
