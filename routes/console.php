<?php

use App\Console\Commands\DispatchDueSyncs;
use App\Models\ShopInvitation;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    ShopInvitation::query()
        ->whereNotNull('expires_at')
        ->where('expires_at', '<', now())
        ->delete();
})->daily()->description('Delete expired shop invitations');

Schedule::command(DispatchDueSyncs::class)
    ->everyMinute()
    ->withoutOverlapping()
    ->description('Dispatch due shop syncs');
