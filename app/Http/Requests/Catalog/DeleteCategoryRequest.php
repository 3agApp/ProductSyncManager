<?php

namespace App\Http\Requests\Catalog;

use App\Actions\Catalog\DeleteCategory;
use App\Enums\CategoryChildrenAction;
use App\Enums\CategoryProductsAction;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DeleteCategoryRequest extends FormRequest
{
    /**
     * Determine if the user may delete the category and do what they chose
     * with its products.
     */
    public function authorize(): bool
    {
        $category = $this->category();
        $permission = $this->productsAction()->permission();

        return Gate::allows('delete', $category)
            && ($permission === null || $this->user()->hasShopPermission($category->shop, $permission));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $category = $this->category();
        $products = $this->productsAction();

        return [
            'children' => ['sometimes', 'required', Rule::enum(CategoryChildrenAction::class)],
            'products' => ['sometimes', 'required', Rule::enum(CategoryProductsAction::class)],
            'destination_id' => [
                Rule::requiredIf($products === CategoryProductsAction::Move),
                'nullable',
                'integer',
                Rule::exists('categories', 'id')->where('shop_id', $category->shop_id),
            ],
            'confirmation' => [
                Rule::requiredIf($products === CategoryProductsAction::Delete),
                'nullable',
                'string',
                Rule::in([$category->name]),
            ],
            'preview_token' => [
                Rule::requiredIf(DeleteCategory::needsReview($this->childrenAction(), $products)),
                'nullable',
                'string',
                'size:64',
            ],
        ];
    }

    /**
     * Get what should happen to the category's children.
     */
    public function childrenAction(): CategoryChildrenAction
    {
        return CategoryChildrenAction::tryFrom((string) $this->input('children')) ?? CategoryChildrenAction::Keep;
    }

    /**
     * Get what should happen to the affected products.
     */
    public function productsAction(): CategoryProductsAction
    {
        return CategoryProductsAction::tryFrom((string) $this->input('products')) ?? CategoryProductsAction::Keep;
    }

    /**
     * Get the category being deleted.
     */
    private function category(): Category
    {
        $category = $this->route('category');
        abort_unless($category instanceof Category, 404);

        return $category;
    }
}
