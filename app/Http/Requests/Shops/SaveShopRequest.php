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
     * Prepare the data for validation.
     *
     * The tax and currency fields are only touched when submitted, so creating a shop
     * or renaming one leaves them as they are.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('prices_include_tax')) {
            $this->merge(['prices_include_tax' => $this->boolean('prices_include_tax')]);
        }

        if ($this->has('currency')) {
            $this->merge(['currency' => strtoupper(trim((string) $this->input('currency')))]);
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
            'name' => ['required', 'string', 'max:255', new ShopName],
            'type' => ['sometimes', 'required', Rule::enum(ShopType::class)],
            'prices_include_tax' => ['sometimes', 'boolean'],
            'tax_rate' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:100'],
            'currency' => ['sometimes', 'required', 'string', 'alpha', 'size:3'],
        ];
    }

    /**
     * Get custom attributes for validator errors.
     *
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'prices_include_tax' => 'prices include tax',
            'tax_rate' => 'tax rate',
        ];
    }
}
