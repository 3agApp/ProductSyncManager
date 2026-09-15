<?php

namespace App\Filament\Resources\Shops\Pages;

use App\Filament\Resources\Shops\ShopActions;
use App\Filament\Resources\Shops\ShopResource;
use App\Models\Shop;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

class ViewShop extends ViewRecord
{
    protected static string $resource = ShopResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()
                ->hidden(fn (Shop $record): bool => $record->trashed()),
            ShopActions::restore(),
            ShopActions::delete(),
        ];
    }
}
