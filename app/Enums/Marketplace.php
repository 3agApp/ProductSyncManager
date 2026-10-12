<?php

namespace App\Enums;

/**
 * A marketplace whose orders are imported into WooCommerce.
 *
 * The value is what the WooCommerce plugin stores in `_wpsm_marketplace`.
 */
enum Marketplace: string
{
    case Nalda = 'nalda';

    case Temu = 'temu';

    /**
     * Get the display name, which is a brand and never translated.
     */
    public function label(): string
    {
        return match ($this) {
            self::Nalda => 'Nalda',
            self::Temu => 'Temu',
        };
    }
}
