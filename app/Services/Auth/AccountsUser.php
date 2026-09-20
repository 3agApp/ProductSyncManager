<?php

namespace App\Services\Auth;

/**
 * The claims Accounts returns about the person signing in.
 */
class AccountsUser
{
    /**
     * @param  bool|null  $emailVerified  Null when the issuer sent no
     *                                    email_verified claim, which OIDC
     *                                    allows: not said, rather than no.
     */
    public function __construct(
        public readonly string $id,
        public readonly ?string $email,
        public readonly ?string $name,
        public readonly ?bool $emailVerified = null,
    ) {}
}
