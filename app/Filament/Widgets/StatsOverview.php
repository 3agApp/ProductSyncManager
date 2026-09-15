<?php

namespace App\Filament\Widgets;

use App\Models\Shop;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class StatsOverview extends StatsOverviewWidget
{
    protected function getStats(): array
    {
        $newUsers = User::where('created_at', '>=', now()->subDays(7))->count();

        return [
            Stat::make('Users', User::count())
                ->description("{$newUsers} joined in the last 7 days"),
            Stat::make('Shops', Shop::count()),
            Stat::make('Users without a shop', User::whereDoesntHave('shops')->count()),
        ];
    }
}
