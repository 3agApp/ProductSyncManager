<?php

namespace App\Http\Requests\Shops;

use App\Models\Shop;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Validator;

class DeleteShopRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('delete', $this->route('shop'));
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
        ];
    }

    /**
     * Configure the validator instance.
     *
     * @return array<int, Closure(Validator): void>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($this->input('name') !== $this->shop()->name) {
                    $validator->errors()->add('name', __('The shop name does not match.'));
                }
            },
        ];
    }

    /**
     * Get the shop associated with the request.
     */
    private function shop(): Shop
    {
        $shop = $this->route('shop');

        abort_if(! $shop instanceof Shop, 404);

        return $shop;
    }
}
