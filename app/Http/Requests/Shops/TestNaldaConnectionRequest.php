<?php

namespace App\Http\Requests\Shops;

use App\Models\Shop;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class TestNaldaConnectionRequest extends FormRequest
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
     * Both are optional so saved credentials can be retested without
     * retyping the password.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sftp_username' => ['nullable', 'string', 'max:255'],
            'sftp_password' => ['nullable', 'string', 'max:255'],
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
