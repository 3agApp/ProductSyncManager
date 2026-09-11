<?php

namespace App\Enums;

enum ShopType: string
{
    case Wordpress = 'wordpress';

    /**
     * Get the display label for the type.
     */
    public function label(): string
    {
        return match ($this) {
            self::Wordpress => 'WordPress',
        };
    }

    /**
     * Determine if shops of this type can sync products from a remote store.
     */
    public function supportsSync(): bool
    {
        return match ($this) {
            self::Wordpress => true,
        };
    }

    /**
     * Get all types as select options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }
}
