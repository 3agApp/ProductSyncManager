<?php

namespace App\Http\Controllers\Products;

use App\Enums\BulkProductAction;
use App\Enums\NaldaListing;
use App\Enums\ProductStatus;
use App\Enums\TemuListing;
use App\Http\Controllers\Controller;
use App\Http\Requests\Products\BulkProductRequest;
use App\Models\Product;
use App\Models\Shop;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class BulkProductController extends Controller
{
    /**
     * Apply one action to several of the shop's products.
     *
     * The ids are resolved through the shop before anything is written, so
     * a request naming products from elsewhere acts on none of them rather
     * than on the ones it happens to have guessed right.
     */
    public function __invoke(BulkProductRequest $request, Shop $currentShop): RedirectResponse
    {
        $action = $request->action();
        $ids = $currentShop->products()->whereIn('id', $request->ids())->pluck('id')->all();

        if ($action === null || $ids === []) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('Those products are no longer available.')]);

            return back();
        }

        $affected = DB::transaction(fn () => match ($action) {
            BulkProductAction::SetBrand => $this->setBrand($currentShop, $ids, $request->validated('brand_id')),
            BulkProductAction::AddCategory => $this->addCategory($ids, (int) $request->validated('category_id')),
            BulkProductAction::RemoveCategory => $this->removeCategory($ids, (int) $request->validated('category_id')),
            BulkProductAction::SetStatus => $this->setStatus($currentShop, $ids, $request->validated('status')),
            BulkProductAction::SetNaldaListing => $this->setNaldaListing($currentShop, $ids, $request->validated('nalda_listing')),
            BulkProductAction::SetTemuListing => $this->setTemuListing($currentShop, $ids, $request->validated('temu_listing')),
            BulkProductAction::Delete => $this->delete($currentShop, $ids),
        });

        Inertia::flash('toast', ['type' => 'success', 'message' => $action->message($affected)]);

        return back();
    }

    /**
     * Put every selected product under one brand, or none.
     *
     * @param  array<int, int>  $ids
     */
    private function setBrand(Shop $shop, array $ids, mixed $brandId): int
    {
        return $shop->products()->whereIn('id', $ids)->update([
            'brand_id' => $brandId === null ? null : (int) $brandId,
        ]);
    }

    /**
     * File every selected product under a category.
     *
     * Products already filed there are left as they are rather than being
     * reported as changed.
     *
     * @param  array<int, int>  $ids
     */
    private function addCategory(array $ids, int $categoryId): int
    {
        $already = DB::table('category_product')
            ->where('category_id', $categoryId)
            ->whereIn('product_id', $ids)
            ->pluck('product_id')
            ->all();

        $missing = array_values(array_diff($ids, $already));

        if ($missing === []) {
            return 0;
        }

        DB::table('category_product')->insert(array_map(
            fn (int $productId) => ['product_id' => $productId, 'category_id' => $categoryId],
            $missing,
        ));

        Product::query()->whereIn('id', $missing)->update(['updated_at' => now()]);

        return count($missing);
    }

    /**
     * Take every selected product out of a category.
     *
     * @param  array<int, int>  $ids
     */
    private function removeCategory(array $ids, int $categoryId): int
    {
        $removed = DB::table('category_product')
            ->where('category_id', $categoryId)
            ->whereIn('product_id', $ids)
            ->pluck('product_id')
            ->all();

        DB::table('category_product')
            ->where('category_id', $categoryId)
            ->whereIn('product_id', $removed)
            ->delete();

        Product::query()->whereIn('id', $removed)->update(['updated_at' => now()]);

        return count($removed);
    }

    /**
     * Move every selected product to one status.
     *
     * @param  array<int, int>  $ids
     */
    private function setStatus(Shop $shop, array $ids, mixed $status): int
    {
        return $shop->products()->whereIn('id', $ids)->update([
            'status' => ProductStatus::from((string) $status)->value,
        ]);
    }

    /**
     * Include every selected product on Nalda, exclude it, or hand it back to the shop default.
     *
     * @param  array<int, int>  $ids
     */
    private function setNaldaListing(Shop $shop, array $ids, mixed $listing): int
    {
        return $shop->products()->whereIn('id', $ids)->update([
            'nalda_listing' => NaldaListing::from((string) $listing)->value,
        ]);
    }

    /**
     * Include every selected product on Temu, exclude it, or hand it back to the shop default.
     *
     * @param  array<int, int>  $ids
     */
    private function setTemuListing(Shop $shop, array $ids, mixed $listing): int
    {
        return $shop->products()->whereIn('id', $ids)->update([
            'temu_listing' => TemuListing::from((string) $listing)->value,
        ]);
    }

    /**
     * Delete every selected product.
     *
     * @param  array<int, int>  $ids
     */
    private function delete(Shop $shop, array $ids): int
    {
        return $shop->products()->whereIn('id', $ids)->delete();
    }
}
