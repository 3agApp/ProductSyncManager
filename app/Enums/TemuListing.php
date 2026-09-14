<?php

namespace App\Enums;

enum TemuListing: string
{
    case Default = 'default';
    case Include = 'include';
    case Exclude = 'exclude';

    /**
     * Get the display label for the listing choice.
     */
    public function label(): string
    {
        return match ($this) {
            self::Default => 'Follow shop default',
            self::Include => 'Always include',
            self::Exclude => 'Always exclude',
        };
    }

    /**
     * Determine if a product with this choice is listed, given the shop's default.
     */
    public function includes(TemuDefaultBehavior $default): bool
    {
        return match ($this) {
            self::Include => true,
            self::Exclude => false,
            self::Default => $default === TemuDefaultBehavior::IncludeAll,
        };
    }

    /**
     * Get all listing choices as select options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $listing) => ['value' => $listing->value, 'label' => $listing->label()],
            self::cases(),
        );
    }
}
