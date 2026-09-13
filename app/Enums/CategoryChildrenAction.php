<?php

namespace App\Enums;

/**
 * What happens to a category's children when it is deleted.
 */
enum CategoryChildrenAction: string
{
    case Keep = 'keep';
    case Delete = 'delete';
}
