<?php

namespace App\Http\Requests\Shops;

use App\Enums\NaldaDefaultBehavior;
use App\Enums\NaldaPriceMode;
use App\Models\Shop;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveNaldaConnectionRequest extends FormRequest
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
            'upload_after_sync' => $this->boolean('upload_after_sync'),
            'import_orders' => $this->boolean('import_orders'),
            'api_key' => trim((string) $this->input('api_key')),
            'country' => strtoupper(trim((string) $this->input('country'))),
            'currency' => strtoupper(trim((string) $this->input('currency'))),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * Credentials are optional, so the file can be downloaded and uploaded
     * by hand before the automatic upload is set up.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sftp_username' => ['nullable', 'string', 'max:255'],
            'sftp_password' => ['nullable', 'string', 'max:255'],
            // A bare name in the SFTP root: no folders, so it can never
            // point anywhere else on the server.
            'file_name' => ['required', 'string', 'max:100', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*\.csv$/'],
            'default_behavior' => ['required', Rule::enum(NaldaDefaultBehavior::class)],
            'country' => ['required', 'string', 'alpha', 'size:2'],
            'currency' => ['required', 'string', 'alpha', 'size:3'],
            'condition' => ['required', 'string', 'max:32'],
            'price_mode' => ['required', Rule::enum(NaldaPriceMode::class)],
            'delivery_time_days' => ['required', 'integer', 'min:1', 'max:365'],
            'return_days' => ['required', 'integer', 'min:0', 'max:365'],
            'upload_after_sync' => ['required', 'boolean'],
            'api_key' => ['nullable', 'string', 'max:255'],
            'import_orders' => ['required', 'boolean'],
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
                $username = trim((string) $this->input('sftp_username'));
                $password = (string) $this->input('sftp_password');

                if ($username === '' && $password !== '') {
                    $validator->errors()->add('sftp_username', __('Enter the SFTP user that goes with this password.'));
                }

                $hasStoredPassword = filled($this->shop()->naldaConnection?->sftp_username);

                if ($username !== '' && $password === '' && ! $hasStoredPassword) {
                    $validator->errors()->add('sftp_password', __('Enter the SFTP password.'));
                }

                $hasApiKey = filled($this->input('api_key')) || $this->shop()->naldaConnection?->hasUsableApiKey() === true;

                if ($this->boolean('import_orders') && ! $hasApiKey) {
                    $validator->errors()->add('api_key', __('Enter the API key to import orders.'));
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
            'file_name.regex' => __('The file name must end in .csv and use only letters, numbers, dots, dashes and underscores.'),
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
            'sftp_username' => 'SFTP user',
            'sftp_password' => 'SFTP password',
            'file_name' => 'file name',
            'default_behavior' => 'default product behavior',
            'price_mode' => 'prices',
            'delivery_time_days' => 'delivery time',
            'return_days' => 'return period',
            'upload_after_sync' => 'upload after sync',
            'api_key' => 'API key',
            'import_orders' => 'import orders',
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
