<?php

namespace App\Actions\Users;

use App\Actions\Shops\HandOverOwnedShops;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class DeleteUser
{
    public function __construct(private HandOverOwnedShops $handOverOwnedShops)
    {
        //
    }

    /**
     * Delete the user, leaving no shop without an owner.
     *
     * The shops are handed to whoever else is in them rather than deleted
     * with the user: removing one account should not take a catalogue its
     * other members are still working in.
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user) {
            $this->handOverOwnedShops->handle($user);

            $user->delete();
        });
    }
}
