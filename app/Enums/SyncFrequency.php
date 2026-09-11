<?php

namespace App\Enums;

enum SyncFrequency: string
{
    case Hourly = 'hourly';
    case SixHourly = 'six_hourly';
    case Daily = 'daily';
    case Weekly = 'weekly';

    /**
     * Get the display label for the frequency.
     */
    public function label(): string
    {
        return match ($this) {
            self::Hourly => 'Hourly',
            self::SixHourly => 'Every 6 hours',
            self::Daily => 'Daily',
            self::Weekly => 'Weekly',
        };
    }

    /**
     * Determine if the frequency needs a day of the week to be chosen.
     */
    public function requiresDayOfWeek(): bool
    {
        return $this === self::Weekly;
    }

    /**
     * Get all frequencies as select options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $frequency) => ['value' => $frequency->value, 'label' => $frequency->label()],
            self::cases(),
        );
    }
}
