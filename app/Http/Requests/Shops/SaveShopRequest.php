<?php

namespace App\Http\Requests\Shops;

use App\Enums\ShopType;
use App\Rules\ShopName;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveShopRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', new ShopName],
            'type' => ['sometimes', 'required', Rule::enum(ShopType::class)],
        ];
    }
}
