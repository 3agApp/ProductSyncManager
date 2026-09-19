<?php

namespace App\Services\Auth;

/**
 * The claims Accounts returns about the person signing in.
 */
class AccountsUser
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $email,
        public readonly ?string $name,
    ) {}
}
