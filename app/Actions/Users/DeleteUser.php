<?php

namespace App\Actions\Users;

use App\Actions\Shops\DeleteShop;
use App\Models\Shop;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteUser
{
    public function __construct(private DeleteShop $deleteShop)
    {
        //
    }

    /**
     * Delete the user along with the shops they own, so no shop is left
     * without an owner.
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            $user->ownedShops()
                ->get()
                ->each(fn (Shop $shop) => $this->deleteShop->handle($shop));

            $user->delete();
        });
    }
}
