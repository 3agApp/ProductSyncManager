<?php

use App\Console\Commands\DispatchDueSyncs;
use App\Console\Commands\PruneWebhookDeliveries;
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

Schedule::command(PruneWebhookDeliveries::class)
    ->daily()
    ->description('Prune old webhook deliveries');

Schedule::command('queue:prune-failed', ['--hours' => 168])
    ->daily()
    ->description('Prune failed jobs older than a week');

Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->description('Record Horizon queue metrics');
