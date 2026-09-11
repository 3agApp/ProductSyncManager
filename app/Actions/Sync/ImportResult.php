<?php

namespace App\Actions\Sync;

/**
 * The outcome of importing one page of remote products.
 */
readonly class ImportResult
{
    public function __construct(
        public int $created = 0,
        public int $updated = 0,
        public int $skipped = 0,
    ) {
        //
    }
}
