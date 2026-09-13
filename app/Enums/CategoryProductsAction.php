<?php

namespace App\Enums;

/**
 * What happens to the products filed under the categories being deleted.
 */
enum CategoryProductsAction: string
{
    case Keep = 'keep';
    case Move = 'move';
    case Delete = 'delete';

    /**
     * Get the product permission the choice needs, beyond deleting categories.
     */
    public function permission(): ?ShopPermission
    {
        return match ($this) {
            self::Keep => null,
            self::Move => ShopPermission::UpdateProduct,
            self::Delete => ShopPermission::DeleteProduct,
        };
    }

    /**
     * Get the message to report when the deletion succeeded.
     */
    public function message(int $categories, int $deletedProducts, int $movedProducts): string
    {
        $message = trans_choice('{1} :count category deleted.|[0,*] :count categories deleted.', $categories);

        return $message.' '.match ($this) {
            self::Keep => __('All products kept.'),
            self::Move => trans_choice('{1} :count product moved.|[0,*] :count products moved.', $movedProducts),
            self::Delete => trans_choice('{1} :count exclusive product deleted. Shared products kept.|[0,*] :count exclusive products deleted. Shared products kept.', $deletedProducts),
        };
    }
}
