<?php

use App\Models\ShopInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    ShopInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired shop invitations');
