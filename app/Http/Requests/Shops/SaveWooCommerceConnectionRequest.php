<?php

namespace App\Http\Requests\Shops;

use App\Models\Shop;
use App\Rules\StoreUrl;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class SaveWooCommerceConnectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manageSync', $this->shop());
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * A blank key or secret means "keep the stored one", so they are only
     * required when there is nothing stored yet.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $hasConnection = $this->shop()->wooCommerceConnection !== null;

        return [
            'webhook_secret' => ['nullable', 'string', 'min:32', 'max:255'],
            'store_url' => ['required', 'string', 'max:255', new StoreUrl],
            'consumer_key' => [$hasConnection ? 'nullable' : 'required', 'string', 'max:255'],
            'consumer_secret' => [$hasConnection ? 'nullable' : 'required', 'string', 'max:255'],
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
            'store_url' => 'store address',
            'consumer_key' => 'consumer key',
            'consumer_secret' => 'consumer secret',
        ];
    }

    /**
     * Get the shop associated with the request.
     */
    public function shop(): Shop
    {
        $shop = $this->route('shop');

        abort_if(! $shop instanceof Shop, 404);

        return $shop;
    }
}
