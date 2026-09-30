<?php

namespace App\Enums;

/**
 * Where a product stands on Temu, as far as this app last saw.
 */
enum TemuProductStatus: string
{
    /** Chosen for Temu but not sent yet. */
    case Pending = 'pending';

    /** Created on Temu, which is still processing, reviewing or pricing it. */
    case Processing = 'processing';

    /** Saved on Temu as a draft, which needs information before it can be published. */
    case Draft = 'draft';

    /** On sale, or listed with no stock. */
    case Active = 'active';

    /** Taken off sale, by Temu or by this app. */
    case Inactive = 'inactive';

    /** Not published: the price was not accepted or the details were rejected. */
    case Rejected = 'rejected';

    /** Deleted on Temu. */
    case Deleted = 'deleted';

    /** Temu refused the last request this app made for the product. */
    case Failed = 'failed';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::Inactive => 'Off sale',
            self::Rejected => 'Rejected',
            self::Deleted => 'Deleted',
            self::Failed => 'Failed',
        };
    }

    /**
     * Determine if someone has to look at the product before it can sell.
     */
    public function needsAttention(): bool
    {
        return in_array($this, [self::Draft, self::Rejected, self::Failed], true);
    }

    /**
     * Determine if Temu will not accept product changes in this status.
     *
     * A product still being processed, priced or reviewed refuses updates,
     * and a deleted one cannot be changed at all.
     */
    public function refusesUpdates(): bool
    {
        return in_array($this, [self::Processing, self::Deleted], true);
    }

    /**
     * Determine if Temu takes price changes for a product in this status.
     *
     * A draft has no price order yet for a change to amend, so Temu
     * refuses one until the product is submitted.
     */
    public function takesPriceChanges(): bool
    {
        return $this !== self::Draft;
    }

    /**
     * Map Temu's product sub-status code to a status.
     *
     * The codes are the goodsShowSubStatus values from Temu's product
     * listing guide. An unknown code is treated as still processing, so it
     * is checked again rather than acted on.
     */
    public static function fromTemuSubStatus(int $code): self
    {
        return match (true) {
            $code === 3003 => self::Active,
            $code >= 2001 && $code < 3000 => self::Active,
            $code >= 3001 && $code < 4000 => self::Inactive,
            $code === 4003, $code === 4004 => self::Rejected,
            $code >= 4001 && $code < 5000 => self::Processing,
            $code === 5001 => self::Draft,
            $code >= 6001 && $code < 7000 => self::Deleted,
            default => self::Processing,
        };
    }

    /**
     * Describe Temu's sub-status code in words.
     */
    public static function describeTemuSubStatus(?int $code): ?string
    {
        return match ($code) {
            null => null,
            2001 => 'On sale',
            2002 => 'On sale, documents needed within the deadline',
            2003 => 'On sale, penalty pending',
            2004 => 'On sale, penalty warning',
            2005 => 'On sale, category must be corrected',
            2006 => 'On sale, variants must be corrected',
            2101 => 'On sale, low traffic',
            3001 => 'Off sale due to a penalty',
            3002 => 'Off sale',
            3003 => 'Out of stock',
            3004 => 'Off sale, price too high',
            4001 => 'Price being assessed',
            4002 => 'Under review',
            4003 => 'Price not accepted',
            4004 => 'Information incomplete',
            5001 => 'Draft, not submitted',
            6001 => 'Deleted',
            6002 => 'Deleted after the price was refused',
            default => "Temu status {$code}",
        };
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
