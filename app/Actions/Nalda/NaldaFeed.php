<?php

namespace App\Actions\Nalda;

final readonly class NaldaFeed
{
    /**
     * @param  array<int, string>  $listedGtins  every GTIN the file lists for sale
     */
    public function __construct(
        public int $listed,
        public int $removed,
        public int $skipped,
        public array $listedGtins,
    ) {
        //
    }
}
