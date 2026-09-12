<?php

namespace App\Http\Requests\Catalog;

use App\Enums\ShopPermission;
use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class DeleteCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        $category = $this->category();

        return Gate::allows('delete', $category)
            && match ($this->input('products', 'keep')) {
                'delete' => $this->user()->hasShopPermission($category->shop, ShopPermission::DeleteProduct),
                'move' => $this->user()->hasShopPermission($category->shop, ShopPermission::UpdateProduct),
                default => true,
            };
    }

    /** @return array<string, array<mixed>> */
    public function rules(): array
    {
        return [
            'children' => ['sometimes', 'required', Rule::in(['keep', 'delete'])],
            'products' => ['sometimes', 'required', Rule::in(['keep', 'move', 'delete'])],
            'destination_id' => ['nullable', 'required_if:products,move', 'integer', Rule::exists('categories', 'id')->where('shop_id', $this->category()->shop_id)],
            'confirmation' => ['required_if:products,delete', 'nullable', 'string', Rule::in([$this->category()->name])],
            'preview_token' => [Rule::requiredIf($this->input('children') === 'delete' || in_array($this->input('products'), ['move', 'delete'], true)), 'nullable', 'string', 'size:64'],
        ];
    }

    private function category(): Category
    {
        $category = $this->route('category');
        abort_unless($category instanceof Category, 404);

        return $category;
    }
}
