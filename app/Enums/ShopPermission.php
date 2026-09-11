<?php

namespace App\Enums;

enum ShopPermission: string
{
    case UpdateShop = 'shop:update';
    case DeleteShop = 'shop:delete';

    case AddMember = 'member:add';
    case UpdateMember = 'member:update';
    case RemoveMember = 'member:remove';

    case CreateInvitation = 'invitation:create';
    case CancelInvitation = 'invitation:cancel';

    case CreateProduct = 'product:create';
    case UpdateProduct = 'product:update';
    case DeleteProduct = 'product:delete';

    case ManageSync = 'sync:manage';
    case RunSync = 'sync:run';
}
