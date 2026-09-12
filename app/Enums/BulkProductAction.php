<?php

namespace App\Enums;

enum BulkProductAction: string
{
    case SetBrand = 'set-brand';
    case AddCategory = 'add-category';
    case RemoveCategory = 'remove-category';
    case SetStatus = 'set-status';
    case Delete = 'delete';

    /**
     * Get the permission the action needs on the shop.
     *
     * Everything but removal is an edit, so a member who may update
     * products may do it to several at once.
     */
    public function permission(): ShopPermission
    {
        return $this === self::Delete
            ? ShopPermission::DeleteProduct
            : ShopPermission::UpdateProduct;
    }

    /**
     * Get the message to report when the action succeeded.
     */
    public function message(int $count): string
    {
        $products = trans_choice('{1}:count product|[2,*]:count products', $count, ['count' => $count]);

        return match ($this) {
            self::SetBrand => __(':products updated.', ['products' => $products]),
            self::AddCategory => __(':products filed.', ['products' => $products]),
            self::RemoveCategory => __(':products removed from the category.', ['products' => $products]),
            self::SetStatus => __(':products updated.', ['products' => $products]),
            self::Delete => __(':products deleted.', ['products' => $products]),
        };
    }
}
