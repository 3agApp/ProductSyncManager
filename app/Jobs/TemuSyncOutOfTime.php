<?php

namespace App\Jobs;

use RuntimeException;

/**
 * Raised inside a Temu sync when an attempt has used its working time.
 */
final class TemuSyncOutOfTime extends RuntimeException
{
    //
}
