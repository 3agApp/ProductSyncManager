<?php

namespace App\Http\Requests\Shops;

use App\Enums\TemuDefaultBehavior;
use App\Models\Shop;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveTemuConnectionRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return Gate::allows('manageSync', $this->shop());
    }

    /**
     * Prepare the data for validation.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'sync_after_sync' => $this->boolean('sync_after_sync'),
            'import_orders' => $this->boolean('import_orders'),
            'confirm_shipments' => $this->boolean('confirm_shipments'),
            'app_key' => trim((string) $this->input('app_key')),
            'currency' => strtoupper(trim((string) $this->input('currency'))),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * The secret and token are write-only: a blank field keeps what is
     * stored, so neither has to travel back to the browser.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'app_key' => ['nullable', 'string', 'max:128', 'regex:/^[A-Za-z0-9]+$/'],
            'app_secret' => ['nullable', 'string', 'max:255'],
            'access_token' => ['nullable', 'string', 'max:1024'],
            'default_behavior' => ['required', Rule::enum(TemuDefaultBehavior::class)],
            'currency' => ['required', 'string', 'alpha', 'size:3'],
            'discount_percent' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:90'],
            'sync_after_sync' => ['required', 'boolean'],
            'import_orders' => ['required', 'boolean'],
            'order_shipping' => ['required', 'numeric', 'decimal:0,2', 'min:0', 'max:1000'],
            'confirm_shipments' => ['required', 'boolean'],
        ];
    }

    /**
     * Get additional validation applied after the rules pass.
     *
     * @return array<int, callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator) {
                $appKey = (string) $this->input('app_key');
                $stored = $this->shop()->temuConnection;
                $sameKey = $stored !== null && $stored->app_key === $appKey;

                if ($appKey === '') {
                    if (filled($this->input('app_secret')) || filled($this->input('access_token'))) {
                        $validator->errors()->add('app_key', __('Enter the app key that goes with this secret and token.'));
                    }

                    return;
                }

                // A new app key needs its own secret; the stored one belongs
                // to the previous app.
                if (blank($this->input('app_secret')) && ! ($sameKey && filled($stored->app_secret))) {
                    $validator->errors()->add('app_secret', __('Enter the app secret.'));
                }
            },
        ];
    }

    /**
     * Get custom messages for validator errors.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'app_key.regex' => __('The app key only contains letters and numbers.'),
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
            'app_key' => 'app key',
            'app_secret' => 'app secret',
            'access_token' => 'access token',
            'default_behavior' => 'default product behavior',
            'discount_percent' => 'Temu discount',
            'sync_after_sync' => 'sync after every WooCommerce sync',
            'import_orders' => 'import orders into WooCommerce',
            'order_shipping' => 'shipping paid by Temu',
            'confirm_shipments' => 'confirm shipments on Temu',
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
