<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class UserInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('Account')
                    ->columns(3)
                    ->columnSpanFull()
                    ->schema([
                        TextEntry::make('name'),
                        TextEntry::make('email')
                            ->label('Email address')
                            ->copyable(),
                        TextEntry::make('email_verified_at')
                            ->label('Email verified')
                            ->dateTime()
                            ->placeholder('Not verified'),
                        IconEntry::make('sso_linked')
                            ->label('Linked to 3AG Accounts')
                            ->state(fn (User $record): bool => filled($record->sso_id))
                            ->boolean(),
                        TextEntry::make('currentShop.name')
                            ->label('Current shop')
                            ->placeholder('None'),
                        TextEntry::make('created_at')
                            ->label('Joined')
                            ->dateTime(),
                        TextEntry::make('updated_at')
                            ->label('Last updated')
                            ->since(),
                    ]),
            ]);
    }
}
