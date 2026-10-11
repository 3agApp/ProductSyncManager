<?php

namespace App\Enums;

/**
 * Where a Temu order stands in its import into WooCommerce.
 */
enum TemuOrderStatus: string
{
    /** Seen on Temu, waiting to be imported. */
    case Pending = 'pending';

    /** Being sent to WooCommerce, or the request was cut off before it answered. */
    case Creating = 'creating';

    /** Created in WooCommerce. */
    case Imported = 'imported';

    /** WooCommerce refused the order, or something it needs was missing. */
    case Failed = 'failed';

    /** Cancelled on Temu, and in WooCommerce if it had been imported. */
    case Cancelled = 'cancelled';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting',
            self::Creating => 'Check WooCommerce',
            self::Imported => 'Imported',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Determine if someone has to look at the order.
     *
     * An order stuck in creating may or may not exist in WooCommerce, so it
     * is never sent again on its own.
     */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Creating, self::Failed], true);
    }
}
