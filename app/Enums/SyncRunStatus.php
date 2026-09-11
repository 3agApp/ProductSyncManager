<?php

namespace App\Enums;

enum SyncRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Determine if the run is queued or still in progress.
     */
    public function isActive(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }

    /**
     * Determine if the run has reached a terminal state.
     */
    public function isFinished(): bool
    {
        return ! $this->isActive();
    }

    /**
     * Get all statuses as select options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $status) => ['value' => $status->value, 'label' => $status->label()],
            self::cases(),
        );
    }
}
