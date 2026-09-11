<?php

namespace App\Http\Requests\Shops;

use App\Enums\SyncFrequency;
use App\Models\Shop;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class SaveShopSyncSettingsRequest extends FormRequest
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
            'is_enabled' => $this->boolean('is_enabled'),
        ]);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'is_enabled' => ['required', 'boolean'],
            'frequency' => ['required', Rule::enum(SyncFrequency::class)],
            'time_of_day' => ['required', 'string', 'date_format:H:i'],
            'day_of_week' => ['nullable', 'integer', 'between:0,6'],
            'timezone' => ['required', 'string', 'timezone'],
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
                $frequency = SyncFrequency::tryFrom((string) $this->input('frequency'));

                if ($frequency?->requiresDayOfWeek() && $this->input('day_of_week') === null) {
                    $validator->errors()->add('day_of_week', __('Choose which day of the week to sync on.'));
                }
            },
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
            'is_enabled' => 'scheduled sync',
            'time_of_day' => 'time of day',
            'day_of_week' => 'day of the week',
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
