<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\ShopRole;
use App\Filament\Resources\Shops\ShopResource;
use App\Models\Membership;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Lists the user's shops. Memberships are changed from the shop's page.
 */
class ShopMembershipsRelationManager extends RelationManager
{
    protected static string $relationship = 'shopMemberships';

    protected static ?string $title = 'Shops';

    public function isReadOnly(): bool
    {
        return true;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('shop'))
            ->columns([
                TextColumn::make('shop.name')
                    ->label('Shop')
                    ->url(fn (Membership $record): string => ShopResource::getUrl('view', ['record' => $record->shop])),
                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn (ShopRole $state): string => $state->label()),
                TextColumn::make('created_at')
                    ->label('Joined')
                    ->dateTime(),
            ]);
    }
}
