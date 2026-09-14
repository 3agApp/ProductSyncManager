<?php

namespace App\Enums;

enum NaldaDefaultBehavior: string
{
    case IncludeAll = 'include_all';
    case ExcludeAll = 'exclude_all';

    /**
     * Get the display label for the behavior.
     */
    public function label(): string
    {
        return match ($this) {
            self::IncludeAll => 'Include all products',
            self::ExcludeAll => 'Exclude all products',
        };
    }

    /**
     * Get all behaviors as select options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $behavior) => ['value' => $behavior->value, 'label' => $behavior->label()],
            self::cases(),
        );
    }
}
