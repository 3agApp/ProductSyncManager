<?php

namespace App\Http\Requests\Products;

use App\Enums\BulkProductAction;
use App\Enums\NaldaListing;
use App\Enums\ProductStatus;
use App\Models\Shop;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkProductRequest extends FormRequest
{
    /**
     * The most products one request may act on.
     *
     * The list pages at a hundred, so this leaves room for a few pages'
     * worth of selection without letting a crafted request work through
     * the whole catalog in one statement.
     */
    private const int MAX_PRODUCTS = 500;

    /**
     * Determine if the user is authorized to make this request.
     *
     * Which permission applies depends on the action, so an unreadable one
     * is refused here rather than being taken as an edit.
     */
    public function authorize(): bool
    {
        $action = BulkProductAction::tryFrom((string) $this->input('action'));

        return $action !== null
            && $this->user()?->hasShopPermission($this->shop(), $action->permission());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $shopId = $this->shop()->id;

        // Clearing the brand means sending an explicit null, which `required`
        // would reject -- `present` asks for the key, not for a value.
        $brandRules = ['nullable', 'integer', Rule::exists('brands', 'id')->where('shop_id', $shopId)];

        if ($this->action() === BulkProductAction::SetBrand) {
            array_unshift($brandRules, 'present');
        }

        return [
            'action' => ['required', Rule::enum(BulkProductAction::class)],
            'ids' => ['required', 'array', 'min:1', 'max:'.self::MAX_PRODUCTS],
            'ids.*' => ['integer'],
            'brand_id' => $brandRules,
            'category_id' => [
                'integer',
                Rule::requiredIf(in_array($this->action(), [
                    BulkProductAction::AddCategory,
                    BulkProductAction::RemoveCategory,
                ], true)),
                Rule::exists('categories', 'id')->where('shop_id', $shopId),
            ],
            'status' => [
                Rule::requiredIf($this->action() === BulkProductAction::SetStatus),
                Rule::enum(ProductStatus::class),
            ],
            'nalda_listing' => [
                Rule::requiredIf($this->action() === BulkProductAction::SetNaldaListing),
                Rule::enum(NaldaListing::class),
            ],
        ];
    }

    /**
     * Get the action being applied.
     */
    public function action(): ?BulkProductAction
    {
        return BulkProductAction::tryFrom((string) $this->input('action'));
    }

    /**
     * Get the submitted product ids.
     *
     * @return array<int, int>
     */
    public function ids(): array
    {
        /** @var array<int, mixed> $ids */
        $ids = $this->validated('ids', []);

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Get the shop associated with the request.
     */
    public function shop(): Shop
    {
        $shop = $this->route('current_shop');

        abort_if(! $shop instanceof Shop, 404);

        return $shop;
    }
}
