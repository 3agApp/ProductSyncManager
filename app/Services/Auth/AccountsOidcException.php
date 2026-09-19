<?php

namespace App\Services\Auth;

use RuntimeException;

/**
 * Thrown for every way signing in through Accounts can fail: missing
 * configuration, an unreachable issuer, a tampered callback, or a token
 * the issuer refuses to exchange.
 */
class AccountsOidcException extends RuntimeException
{
    //
}
