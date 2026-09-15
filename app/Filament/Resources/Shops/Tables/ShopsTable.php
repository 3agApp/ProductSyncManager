<?php

namespace App\Filament\Resources\Shops\Tables;

use App\Enums\ShopType;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class ShopsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')
                    ->description(fn ($record): string => $record->slug)
                    ->searchable(['name', 'slug'])
                    ->sortable(),
                TextColumn::make('ownerMembership.user.email')
                    ->label('Owner')
                    ->placeholder('No owner')
                    ->searchable(),
                TextColumn::make('type')
                    ->badge()
                    ->formatStateUsing(fn (ShopType $state): string => $state->label()),
                TextColumn::make('memberships_count')
                    ->label('Members')
                    ->sortable(),
                TextColumn::make('products_count')
                    ->label('Products')
                    ->sortable(),
                TextColumn::make('currency'),
                TextColumn::make('created_at')
                    ->label('Created')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('deleted_at')
                    ->label('Deleted')
                    ->dateTime()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options(collect(ShopType::cases())
                        ->mapWithKeys(fn (ShopType $type) => [$type->value => $type->label()])
                        ->all()),
                TrashedFilter::make(),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make()
                    ->hidden(fn ($record): bool => $record->trashed()),
            ]);
    }
}
