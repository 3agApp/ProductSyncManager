<?php

namespace App\Filament\Resources\Shops\Schemas;

use App\Enums\ShopType;
use App\Rules\ShopName;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ShopForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Shop')
                    ->description('Renaming the shop also changes its URL.')
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                        TextInput::make('name')
                            ->required()
                            ->maxLength(255)
                            ->rule(new ShopName),
                        Select::make('type')
                            ->options(collect(ShopType::cases())
                                ->mapWithKeys(fn (ShopType $type) => [$type->value => $type->label()])
                                ->all())
                            ->required()
                            ->native(false),
                        TextInput::make('currency')
                            ->required()
                            ->length(3)
                            ->alpha()
                            ->dehydrateStateUsing(fn (string $state): string => strtoupper($state)),
                        TextInput::make('tax_rate')
                            ->label('Tax rate')
                            ->required()
                            ->numeric()
                            ->minValue(0)
                            ->maxValue(100)
                            ->step(0.01)
                            ->suffix('%'),
                        Toggle::make('prices_include_tax')
                            ->label('Prices include tax'),
                    ]),
            ]);
    }
}
