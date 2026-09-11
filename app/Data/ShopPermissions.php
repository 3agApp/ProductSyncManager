<?php

namespace App\Data;

readonly class ShopPermissions
{
    public function __construct(
        public bool $canUpdateShop,
        public bool $canDeleteShop,
        public bool $canAddMember,
        public bool $canUpdateMember,
        public bool $canRemoveMember,
        public bool $canCreateInvitation,
        public bool $canCancelInvitation,
        public bool $canCreateProduct,
        public bool $canUpdateProduct,
        public bool $canDeleteProduct,
        public bool $canManageSync,
        public bool $canRunSync,
    ) {
        //
    }
}
