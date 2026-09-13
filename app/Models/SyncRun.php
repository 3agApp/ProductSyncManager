<?php

namespace App\Models;

use App\Enums\SyncRunStatus;
use App\Enums\SyncTrigger;
use Database\Factories\SyncRunFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $shop_id
 * @property int|null $active_shop_id
 * @property int|null $triggered_by
 * @property SyncTrigger $trigger
 * @property SyncRunStatus $status
 * @property int|null $total_products
 * @property int $processed_products
 * @property int $created_products
 * @property int $updated_products
 * @property int $archived_products
 * @property int $skipped_products
 * @property string|null $message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read Shop $shop
 * @property-read User|null $triggeredBy
 */
#[Fillable([
    'triggered_by',
    'trigger',
    'status',
    'total_products',
    'processed_products',
    'created_products',
    'updated_products',
    'archived_products',
    'skipped_products',
    'message',
    'started_at',
    'finished_at',
])]
class SyncRun extends Model
{
    /** @use HasFactory<SyncRunFactory> */
    use HasFactory;

    /**
     * Runs still active after this many minutes are treated as stalled.
     */
    public const int ACTIVE_TIMEOUT_MINUTES = 60;

    /**
     * Bootstrap the model and its traits.
     */
    protected static function boot(): void
    {
        parent::boot();

        // The unique index on active_shop_id is what stops a shop having two
        // active runs, so every write keeps it in step with the status.
        static::saving(function (SyncRun $run) {
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
     * Get the user who triggered the run, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function triggeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    /**
     * Scope the query to runs that are queued or in progress.
     *
     * @param  Builder<SyncRun>  $query
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
     * Mark the run as started, clearing any counters from a previous attempt.
     */
    public function markRunning(): void
    {
        $this->update([
            'status' => SyncRunStatus::Running,
            'started_at' => now(),
            'finished_at' => null,
            'message' => null,
            'total_products' => null,
            'processed_products' => 0,
            'created_products' => 0,
            'updated_products' => 0,
            'archived_products' => 0,
            'skipped_products' => 0,
        ]);
    }

    /**
     * Mark the run as completed successfully.
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
     * Get the completion percentage, or null when the total is unknown.
     */
    public function progress(): ?int
    {
        if ($this->total_products === null || $this->total_products === 0) {
            return null;
        }

        return (int) min(100, round($this->processed_products / $this->total_products * 100));
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
            'total_products' => 'integer',
            'processed_products' => 'integer',
            'created_products' => 'integer',
            'updated_products' => 'integer',
            'archived_products' => 'integer',
            'skipped_products' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
