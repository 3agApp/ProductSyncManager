<?php

namespace App\Http\Requests\Shops;

use App\Enums\ShopRole;
use App\Models\Shop;
use App\Rules\UniqueShopInvitation;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateShopInvitationRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $shop = $this->route('shop');

        abort_if(! $shop instanceof Shop, 404);

        return [
            'email' => ['required', 'string', 'email', 'max:255', new UniqueShopInvitation($shop)],
            'role' => ['required', 'string', Rule::enum(ShopRole::class)],
        ];
    }
}
