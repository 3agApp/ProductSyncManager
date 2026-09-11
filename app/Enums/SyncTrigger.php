<?php

namespace App\Enums;

enum SyncTrigger: string
{
    case Manual = 'manual';
    case Scheduled = 'scheduled';

    /**
     * Get the display label for the trigger.
     */
    public function label(): string
    {
        return ucfirst($this->value);
    }

    /**
     * Get all triggers as select options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $trigger) => ['value' => $trigger->value, 'label' => $trigger->label()],
            self::cases(),
        );
    }
}
