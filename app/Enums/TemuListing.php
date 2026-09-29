<?php

namespace App\Enums;

enum TemuListing: string
{
    case Default = 'default';
    case Include = 'include';
    case Exclude = 'exclude';

    /**
     * Get the display label for a product's listing choice.
     */
    public function label(): string
    {
        return match ($this) {
            self::Default => 'Follow brand / shop default',
            self::Include => 'Always include',
            self::Exclude => 'Always exclude',
        };
    }

    /**
     * Get the display label for a brand's listing choice.
     *
     * A brand has nothing between it and the shop, so following the
     * default only ever means following the shop.
     */
    public function brandLabel(): string
    {
        return match ($this) {
            self::Default => 'Follow shop default',
            self::Include => 'Include all products',
            self::Exclude => 'Exclude all products',
        };
    }

    /**
     * Determine if a product with this choice is listed.
     *
     * The most specific choice wins: the product's own include or exclude,
     * then its brand's, and only when both follow along the shop's default.
     */
    public function includes(TemuDefaultBehavior $default, ?self $brand = null): bool
    {
        return match ($this) {
            self::Include => true,
            self::Exclude => false,
            self::Default => $brand !== null && $brand !== self::Default
                ? $brand->includes($default)
                : $default === TemuDefaultBehavior::IncludeAll,
        };
    }

    /**
     * Get all listing choices as select options for a product.
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

    /**
     * Get all listing choices as select options for a brand.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function brandOptions(): array
    {
        return array_map(
            fn (self $listing) => ['value' => $listing->value, 'label' => $listing->brandLabel()],
            self::cases(),
        );
    }
}
