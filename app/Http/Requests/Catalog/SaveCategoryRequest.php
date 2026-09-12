<?php

namespace App\Http\Requests\Catalog;

use App\Models\Category;
use App\Models\Shop;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SaveCategoryRequest extends FormRequest
{
    /**
     * The value the parent select submits for a top level category.
     */
    public const string NO_PARENT = 'none';

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        $category = $this->category();

        return $category
            ? Gate::allows('update', $category)
            : Gate::allows('create', [Category::class, $this->shop()]);
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('name')) {
            $this->merge(['name' => trim((string) $this->input('name'))]);
        }

        // The parent select needs a non-empty value for its top level entry,
        // so that sentinel and a blank both mean the top of the tree.
        if (in_array($this->input('parent_id'), ['', self::NO_PARENT], true)) {
            $this->merge(['parent_id' => null]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', $this->freeSlug()],
            'parent_id' => [
                'nullable',
                'integer',
                // Scoping the rule to the shop is what stops a category
                // being filed under another shop's tree.
                Rule::exists('categories', 'id')->where('shop_id', $this->shop()->id),
                $this->notItsOwnDescendant(),
            ],
        ];
    }

    /**
     * Require a name no other category in the shop already answers to.
     */
    private function freeSlug(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $slug = Str::slug((string) $value);

            if ($slug === '') {
                $fail(__('The name must contain at least one letter or number.'));

                return;
            }

            $category = $this->category();

            $taken = $this->shop()->categories()
                ->where('slug', $slug)
                ->when($category !== null, fn ($query) => $query->whereKeyNot($category->id))
                ->exists();

            if ($taken) {
                $fail(__('This shop already has a category by that name.'));
            }
        };
    }

    /**
     * Reject a parent that would put the category inside its own branch.
     *
     * Without this a category could be made its own ancestor, which would
     * detach the whole branch from the tree and loop anything walking it.
     */
    private function notItsOwnDescendant(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $category = $this->category();

            if ($category === null || $value === null) {
                return;
            }

            $parentId = (int) $value;

            if ($parentId === $category->id) {
                $fail(__('A category cannot be filed under itself.'));

                return;
            }

            if (in_array($parentId, $this->descendantIds($category), true)) {
                $fail(__('A category cannot be filed under one of its own subcategories.'));
            }
        };
    }

    /**
     * Get the ids of everything below a category.
     *
     * @return array<int, int>
     */
    private function descendantIds(Category $category): array
    {
        $children = $this->shop()->categories()
            ->select(['id', 'parent_id'])
            ->get()
            ->groupBy('parent_id');

        $ids = [];
        $queue = [$category->id];

        while ($queue !== []) {
            foreach ($children->get(array_shift($queue), []) as $child) {
                $ids[] = $child->id;
                $queue[] = $child->id;
            }
        }

        return $ids;
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

    /**
     * Get the category being updated, if any.
     */
    private function category(): ?Category
    {
        $category = $this->route('category');

        return $category instanceof Category ? $category : null;
    }
}
