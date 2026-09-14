<?php

namespace App\Data;

readonly class UserShop
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public bool $isPersonal,
        public string $currency,
        public ?string $role,
        public ?string $roleLabel,
        public ?bool $isCurrent = null,
    ) {
        //
    }
}
