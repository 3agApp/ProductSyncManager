<?php

namespace App\Filament\Resources\Shops\Schemas;

use App\Enums\ShopType;
use App\Filament\Resources\Users\UserResource;
use App\Models\Shop;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShopInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Shop')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('slug'),
                        TextEntry::make('type')
                            ->badge()
                            ->formatStateUsing(fn (ShopType $state): string => $state->label()),
                        TextEntry::make('ownerMembership.user.email')
                            ->label('Owner')
                            ->placeholder('No owner')
                            ->url(fn (Shop $record): ?string => $record->ownerMembership
                                ? UserResource::getUrl('view', ['record' => $record->ownerMembership->user_id])
                                : null),
                        TextEntry::make('memberships_count')
                            ->label('Members')
                            ->state(fn (Shop $record): int => $record->memberships()->count()),
                        TextEntry::make('products_count')
                            ->label('Products')
                            ->state(fn (Shop $record): int => $record->products()->count()),
                        TextEntry::make('currency'),
                        TextEntry::make('tax_rate')
                            ->label('Tax rate')
                            ->suffix('%'),
                        IconEntry::make('prices_include_tax')
                            ->label('Prices include tax')
                            ->boolean(),
                        IconEntry::make('woocommerce_connected')
                            ->label('WooCommerce connected')
                            ->state(fn (Shop $record): bool => $record->wooCommerceConnection()->exists())
                            ->boolean(),
                        TextEntry::make('created_at')
                            ->label('Created')
                            ->dateTime(),
                        TextEntry::make('deleted_at')
                            ->label('Deleted')
                            ->dateTime()
                            ->color('danger')
                            ->visible(fn (Shop $record): bool => $record->trashed()),
                    ]),
            ]);
    }
}
