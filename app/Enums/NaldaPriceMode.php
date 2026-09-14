<?php

namespace App\Enums;

enum NaldaPriceMode: string
{
    case AsIs = 'as_is';
    case AddTax = 'add_tax';

    /**
     * Get the display label for the mode.
     */
    public function label(): string
    {
        return match ($this) {
            self::AsIs => 'Send prices as they are',
            self::AddTax => 'Add VAT to prices',
        };
    }

    /**
     * Get all modes as select options.
     *
     * @return array<int, array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $mode) => ['value' => $mode->value, 'label' => $mode->label()],
            self::cases(),
        );
    }
}
